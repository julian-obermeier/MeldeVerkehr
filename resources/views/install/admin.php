<?php
/** @var string $csrf */
/** @var string $databaseVersion */
/** @var ?string $error */
/** @var array $old */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MeldeVerkehr – Einrichtung</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;margin:0;color:#172033}main{max-width:780px;margin:40px auto;padding:24px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:16px;padding:24px;box-shadow:0 8px 30px rgba(20,34,55,.06)}label{display:block;font-weight:600;margin:14px 0 6px}input,select{width:100%;box-sizing:border-box;padding:12px;border:1px solid #bbc5d2;border-radius:9px;font:inherit}button{margin-top:20px;padding:12px 18px;border:0;border-radius:9px;background:#172033;color:#fff;font-weight:700}.error{padding:12px;border:1px solid #c9a0a0;border-radius:9px}.hint{color:#59677a}</style></head>
<body><main><div class="card">
<h1>Anwendung einrichten</h1>
<p class="hint">Schritt 2 von 2 – Datenbank verbunden: <?= $e($databaseVersion) ?></p>
<?php if ($error): ?><div class="error"><?= $e($error) ?></div><?php endif; ?>
<form method="post" action="/install/admin">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<h2>Anwendung</h2>
<label for="app_name">Name</label><input id="app_name" name="app_name" value="<?= $e($old['name'] ?? $old['app_name'] ?? 'MeldeVerkehr') ?>" required>
<label for="app_url">URL</label><input id="app_url" name="app_url" type="url" placeholder="https://example.de" value="<?= $e($old['url'] ?? $old['app_url'] ?? '') ?>" required>
<label for="app_timezone">Zeitzone</label>
<select id="app_timezone" name="app_timezone">
<?php foreach (['Europe/Berlin','Europe/Vienna','Europe/Zurich','UTC'] as $tz): ?>
<option value="<?= $e($tz) ?>" <?= (($old['timezone'] ?? $old['app_timezone'] ?? 'Europe/Berlin') === $tz) ? 'selected' : '' ?>><?= $e($tz) ?></option>
<?php endforeach; ?>
</select>
<h2>Superadministrator</h2>
<label for="first_name">Vorname</label><input id="first_name" name="first_name" value="<?= $e($old['first_name'] ?? '') ?>" required>
<label for="last_name">Nachname</label><input id="last_name" name="last_name" value="<?= $e($old['last_name'] ?? '') ?>" required>
<label for="email">E-Mail</label><input id="email" name="email" type="email" value="<?= $e($old['email'] ?? '') ?>" required>
<label for="password">Passwort</label><input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>
<label for="password_confirmation">Passwort bestätigen</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
<button type="submit">MeldeVerkehr installieren</button>
</form>
</div></main></body></html>
