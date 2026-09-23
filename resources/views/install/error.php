<?php
/** @var string $message */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installationsfehler</title></head><body><main><h1>Installationsfehler</h1><p><?= $e($message) ?></p><p><a href="/install">Installer neu öffnen</a></p></main></body></html>
