<?php
/** @var array $migrations */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MeldeVerkehr installiert</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;margin:0;color:#172033}main{max-width:760px;margin:50px auto;padding:24px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:16px;padding:28px}a{display:inline-block;margin-top:18px;padding:12px 18px;border-radius:9px;background:#172033;color:#fff;text-decoration:none;font-weight:700}code{background:#eef2f6;padding:2px 5px;border-radius:5px}</style></head>
<body><main><div class="card"><h1>Installation abgeschlossen</h1><p>MeldeVerkehr wurde installiert und der Installer ist jetzt gesperrt.</p>
<?php if ($migrations): ?><p>Ausgeführte Migrationen:</p><ul><?php foreach ($migrations as $migration): ?><li><code><?= $e($migration) ?></code></li><?php endforeach; ?></ul><?php endif; ?>
<p>Als Nächstes können SMTP/IMAP und die Cronjobs eingerichtet werden.</p><a href="/">Zur Anwendung</a></div></main></body></html>
