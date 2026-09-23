<?php

declare(strict_types=1);

namespace MeldeVerkehr\Mail;

interface MailTransportInterface
{
    public function send(string $to, string $subject, string $html, string $text = ''): bool;
}
