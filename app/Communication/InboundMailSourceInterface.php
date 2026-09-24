<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

interface InboundMailSourceInterface
{
    /**
     * @return array<int,array{
     *   source_id:string,
     *   message_id:?string,
     *   in_reply_to:?string,
     *   from:string,
     *   to:array<int,string>,
     *   subject:string,
     *   text:string,
     *   received_at:?string,
     *   attachments:array<int,array{filename:string,mime_type:string,content:string}>
     * }>
     */
    public function fetch(int $limit = 20): array;

    public function acknowledge(string $sourceId): void;
}
