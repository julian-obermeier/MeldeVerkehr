<?php

declare(strict_types=1);

namespace MeldeVerkehr\Assist;

final class HttpJsonVisionProvider implements VisionProviderInterface
{
    private readonly string $resolvedIp;
    private readonly string $resolvedHost;
    private readonly int $resolvedPort;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey
    ) {
        [$this->resolvedHost, $this->resolvedIp, $this->resolvedPort] = $this->assertEndpoint();
    }

    public function name(): string
    {
        return 'http_json';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function analyze(
        string $purpose,
        string $imagePath,
        string $mimeType,
        array $context = []
    ): array {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL ist für den konfigurierten Vision-Provider nicht verfügbar.');
        }

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            throw new \RuntimeException('Analysebild ist nicht lesbar.');
        }

        $size = filesize($imagePath);
        if ($size === false || $size < 1 || $size > 12 * 1024 * 1024) {
            throw new \RuntimeException('Analysebild überschreitet das Provider-Limit von 12 MB.');
        }

        $bytes = file_get_contents($imagePath);
        if ($bytes === false) {
            throw new \RuntimeException('Analysebild konnte nicht gelesen werden.');
        }

        $payload = json_encode(
            [
                'purpose' => $purpose,
                'image' => [
                    'mime_type' => $mimeType,
                    'base64' => base64_encode($bytes),
                ],
                'context' => $context,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $response = '';
        $tooLarge = false;
        $ch = curl_init($this->endpoint);

        if ($ch === false) {
            throw new \RuntimeException('Vision-Provider konnte nicht initialisiert werden.');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'MeldeVerkehr-Assist/1.0',
            CURLOPT_RESOLVE => [
                $this->resolvedHost . ':' . $this->resolvedPort . ':' . $this->resolvedIp,
            ],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > 2 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }

                $response .= $chunk;
                return strlen($chunk);
            },
        ]);

        try {
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if ($tooLarge) {
                throw new \RuntimeException('Vision-Provider-Antwort überschreitet 2 MB.');
            }

            if ($ok === false) {
                throw new \RuntimeException('Vision-Provider-Verbindung fehlgeschlagen: ' . mb_substr($error, 0, 300));
            }

            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Vision-Provider antwortete mit HTTP ' . $status . '.');
            }

            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded) || !is_array($decoded['suggestions'] ?? null)) {
                throw new \RuntimeException('Vision-Provider-Antwort entspricht nicht dem erwarteten Schema.');
            }

            return [
                'suggestions' => $decoded['suggestions'],
                'metadata' => is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
            ];
        } finally {
            curl_close($ch);
        }
    }

    private function assertEndpoint(): array
    {
        $parts = parse_url($this->endpoint);

        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('ASSIST_ENDPOINT muss eine HTTPS-URL ohne eingebettete Zugangsdaten sein.');
        }

        $host = strtolower((string) $parts['host']);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('ASSIST_ENDPOINT darf keine direkte IP-Adresse verwenden.');
        }

        $addresses = gethostbynamel($host);
        if (!is_array($addresses) || $addresses === []) {
            throw new \InvalidArgumentException('ASSIST_ENDPOINT konnte nicht aufgelöst werden.');
        }

        $validated = [];
        foreach ($addresses as $address) {
            if (
                filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                ) === false
            ) {
                throw new \InvalidArgumentException('ASSIST_ENDPOINT löst auf eine private/reservierte Adresse auf.');
            }
            $validated[] = $address;
        }

        sort($validated, SORT_STRING);
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('ASSIST_ENDPOINT verwendet einen ungültigen Port.');
        }

        return [$host, $validated[0], $port];
    }
}
