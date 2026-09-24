<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

interface DispatchTransportInterface
{
    /**
     * @param array<int,array{name:string,mime_type:string,path:string,sha256:string,size:int}> $attachments
     * @param array<string,string> $headers
     * @return array{accepted:bool,provider_reference:?string,response:array,message_id:?string}
     */
    public function send(
        string $dispatchId,
        string $recipient,
        string $subject,
        string $text,
        array $attachments = [],
        array $headers = []
    ): array;
}
