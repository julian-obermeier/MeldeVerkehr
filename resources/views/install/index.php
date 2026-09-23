<?php
/** @var array<string,bool> $checks */
/** @var bool $systemOk */
/** @var string $csrf */
/** @var ?string $error */
/** @var array $old */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>MeldeVerkehr installieren</title>
    <style>
        body{font-family:system-ui,sans-serif;background:#f5f7fa;margin:0;color:#172033}main{max-width:780px;margin:40px auto;padding:24px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:16px;padding:24px;margin-bottom:20px;box-shadow:0 8px 30px rgba(20,34,55,.06)}h1{margin-top:0}.check{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #eef1f4}.ok{font-weight:700}.bad{font-weight:700}label{display:block;font-weight:600;margin:14px 0 6px}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #bbc5d2;border-radius:9px;font:inherit}button{margin-top:20px;padding:12px 18px;border:0;border-radius:9px;background:#172033;color:#fff;font-weight:700;cursor:pointer}button:disabled{opacity:.45;cursor:not-allowed}.error{padding:12px;border:1px solid #c9a0a0;border-radius:9px;margin:12px 0}.hint{color:#59677a}
    </style>
</head>
<body><main>
<div class="card">
    <h1>MeldeVerkehr installieren</h1>
    <p class="hint">Schritt 1 von 2 – System und Datenbank</p>
    <?php foreach ($checks as $label => $passed): ?>
        <div class="check"><span><?= $e($label) ?></span><span class="<?= $passed ? 'ok' : 'bad' ?>"><?= $passed ? 'OK' : 'FEHLT' ?></span></div>
    <?php endforeach; ?>
</div>
<div class="card">
    <h2>Datenbank</h2>
    <?php if ($error): ?><div class="error"><?= $e($error) ?></div><?php endif; ?>
    <form method="post" action="/install/database">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label for="db_host">Host</label>
        <input id="db_host" name="db_host" value="<?= $e($old['host'] ?? 'localhost') ?>" required>
        <label for="db_port">Port</label>
        <input id="db_port" name="db_port" type="number" value="<?= $e($old['port'] ?? 3306) ?>" required>
        <label for="db_database">Datenbank</label>
        <input id="db_database" name="db_database" value="<?= $e($old['database'] ?? '') ?>" required>
        <label for="db_username">Benutzer</label>
        <input id="db_username" name="db_username" value="<?= $e($old['username'] ?? '') ?>" required>
        <label for="db_password">Passwort</label>
        <input id="db_password" name="db_password" type="password" autocomplete="new-password">
        <button type="submit" <?= $systemOk ? '' : 'disabled' ?>>Verbindung prüfen und weiter</button>
    </form>
</div>
</main></body></html>
