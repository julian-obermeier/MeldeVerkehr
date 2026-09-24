<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

final class InboundMailPoller
{
    public function __construct(
        private readonly InboundMailSourceInterface $source,
        private readonly CommunicationService $communications
    ) {
    }

    public function run(int $limit = 20): array
    {
        $processed = 0;
        $errors = 0;
        $quarantined = 0;

        foreach ($this->source->fetch($limit) as $message) {
            try {
                $result = $this->communications->ingest($message);
                $this->source->acknowledge((string) $message['source_id']);
                $processed++;

                if (($result['status'] ?? null) === 'QUARANTINED') {
                    $quarantined++;
                }
            } catch (\Throwable) {
                $errors++;
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
            'quarantined' => $quarantined,
            'message' => sprintf(
                'Inbound mail: processed=%d quarantined=%d errors=%d',
                $processed,
                $quarantined,
                $errors
            ),
        ];
    }
}
