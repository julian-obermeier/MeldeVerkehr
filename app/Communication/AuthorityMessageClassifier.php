<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

final class AuthorityMessageClassifier
{
    public function __construct(private readonly string $timezone = 'Europe/Berlin')
    {
    }

    public function classify(string $subject, string $body, ?string $receivedAt = null): array
    {
        $text = mb_strtolower(trim($subject . "\n" . $body), 'UTF-8');
        $scores = [
            'DELIVERY_FAILURE' => $this->score($text, [
                'delivery status notification (failure)' => 8,
                'mail delivery failed' => 8,
                'undeliverable' => 7,
                'zustellung fehlgeschlagen' => 8,
                'unzustellbar' => 7,
                'returned mail' => 6,
                'recipient address rejected' => 8,
            ]),
            'RECEIPT' => $this->score($text, [
                'eingang bestätigt' => 5,
                'eingangsbestätigung' => 5,
                'ist eingegangen' => 4,
                'haben ihre meldung erhalten' => 5,
                'vorgang wurde erfasst' => 3,
            ]),
            'INQUIRY' => $this->score($text, [
                'rückfrage' => 5,
                'bitte teilen sie' => 4,
                'bitte ergänzen sie' => 5,
                'benötigen wir noch' => 5,
                'weitere angaben' => 4,
                'können sie uns' => 3,
            ]),
            'DEADLINE' => $this->score($text, [
                'frist' => 6,
                'bis spätestens' => 6,
                'innerhalb von' => 4,
                'fristgerecht' => 3,
            ]),
            'DEMAND' => $this->score($text, [
                'nachforderung' => 6,
                'nachreichen' => 5,
                'reichen sie' => 4,
                'übersenden sie' => 4,
                'unterlagen' => 2,
                'nachweis' => 2,
            ]),
            'REJECTION' => $this->score($text, [
                'nicht weiterverfolgt' => 6,
                'nicht bearbeitet' => 5,
                'abgelehnt' => 6,
                'keine verfolgung' => 5,
                'verfahren eingestellt' => 5,
                'nicht zuständig' => 5,
            ]),
            'CLOSURE' => $this->score($text, [
                'vorgang abgeschlossen' => 6,
                'verfahren abgeschlossen' => 6,
                'bearbeitung abgeschlossen' => 5,
                'abschließend' => 2,
                'erledigt' => 3,
            ]),
        ];

        arsort($scores);
        $classification = (string) array_key_first($scores);
        $best = (int) ($scores[$classification] ?? 0);

        if ($best <= 0) {
            $classification = 'OTHER';
        }

        $deadline = $this->extractDeadline($text, $receivedAt);

        if ($deadline !== null && $classification === 'OTHER') {
            $classification = 'DEADLINE';
            $best = 3;
        }

        $confidence = $classification === 'OTHER'
            ? 0.25
            : min(0.98, 0.50 + ($best * 0.06));

        return [
            'classification' => $classification,
            'confidence' => round($confidence, 4),
            'deadline_at' => $deadline,
            'source' => 'DETERMINISTIC',
        ];
    }

    private function score(string $text, array $terms): int
    {
        $score = 0;

        foreach ($terms as $term => $weight) {
            if (str_contains($text, $term)) {
                $score += (int) $weight;
            }
        }

        return $score;
    }

    private function extractDeadline(string $text, ?string $receivedAt): ?string
    {
        $dates = [];

        if (preg_match_all('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dates[] = sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
            }
        }

        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dates[] = sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
            }
        }

        $base = $receivedAt !== null
            ? new \DateTimeImmutable($receivedAt, new \DateTimeZone('UTC'))
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (preg_match('/innerhalb von\s+(\d{1,3})\s+tagen/u', $text, $m)) {
            $days = min(365, max(1, (int) $m[1]));
            return $base->modify('+' . $days . ' days')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        }

        $future = [];
        foreach (array_unique($dates) as $date) {
            try {
                $local = new \DateTimeImmutable($date . ' 23:59:59', new \DateTimeZone($this->timezone));
                $utc = $local->setTimezone(new \DateTimeZone('UTC'));

                if ($utc >= $base->modify('-1 day')) {
                    $future[] = $utc;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($future === []) {
            return null;
        }

        usort($future, static fn(\DateTimeImmutable $a, \DateTimeImmutable $b): int => $a <=> $b);

        return $future[0]->format('Y-m-d H:i:s');
    }
}
