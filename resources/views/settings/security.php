<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest"><title>Sicherheit – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:900px;margin:30px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:16px;padding:24px;margin-bottom:16px}label{display:block;font-weight:650;margin:12px 0 5px}input{width:100%;box-sizing:border-box;padding:11px;border:1px solid #bbc5d2;border-radius:9px}button{margin-top:12px;padding:11px 15px;border:0;border-radius:9px;background:#172033;color:#fff;font-weight:700}.danger{background:#8e1f1f}.message,.error{padding:12px;border-radius:9px;margin-bottom:14px}.message{border:1px solid #91b99c}.error{border:1px solid #c99999}.codes{columns:2;list-style:none;padding:0}.mono{font-family:ui-monospace,monospace;word-break:break-all;background:#eef2f6;padding:8px;border-radius:8px}.passkey{border-top:1px solid #eef1f4;padding-top:14px;margin-top:14px}.hint{color:#66758a}</style></head>
<body><main><p><a href="/dashboard">← Dashboard</a></p><h1>Kontosicherheit</h1>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?>
<?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>TOTP-Zwei-Faktor-Authentifizierung</h2>
<?php if($totpEnabled):?>
<p><strong>Aktiv.</strong> Nach einer Anmeldung mit Passwort oder Passkey wird zusätzlich ein Authenticator-Code oder Recovery-Code verlangt.</p>
<form method="post" action="/settings/security/totp/disable"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="danger" type="submit">TOTP deaktivieren</button></form>
<?php else:?>
<p class="hint">Für Änderungen an den Sicherheitseinstellungen muss die Anmeldung höchstens 10 Minuten zurückliegen.</p>
<form method="post" action="/settings/security/totp/start"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">TOTP einrichten</button></form>
<?php endif;?>

<?php if($setup):?>
<hr><h3>Authenticator-App verbinden</h3><p>Trage diesen Schlüssel in deiner Authenticator-App ein:</p><div class="mono"><?= $e($setup['secret']??'') ?></div>
<p class="hint">Alternativ kann eine App die folgende otpauth-URI übernehmen:</p><div class="mono"><?= $e($setup['uri']??'') ?></div>
<form method="post" action="/settings/security/totp/confirm"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><label>Aktueller 6-stelliger Code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" required><button type="submit">Einrichtung bestätigen</button></form>
<?php endif;?>

<?php if($recoveryCodes):?><h3>Recovery-Codes – nur jetzt sichtbar</h3><p>Speichere diese Codes sicher. Jeder Code kann nur einmal verwendet werden.</p><ul class="codes"><?php foreach($recoveryCodes as $code):?><li class="mono"><?= $e($code) ?></li><?php endforeach;?></ul><?php endif;?>
</section>

<section class="card"><h2>Passkeys</h2><p>Passkeys verwenden WebAuthn mit ES256/P-256 und erfordern eine Bestätigung auf dem Gerät.</p>
<label>Bezeichnung</label><input data-passkey-label value="Mein Passkey" maxlength="120">
<button type="button" data-passkey-register data-csrf="<?= $e($csrf) ?>">Neuen Passkey registrieren</button>
<div data-passkey-register-error aria-live="polite"></div>
<?php if(!$passkeys):?><p class="hint">Noch kein Passkey registriert.</p><?php endif;?>
<?php foreach($passkeys as $passkey):?><div class="passkey"><strong><?= $e($passkey['label']?:'Passkey') ?></strong><br><small>Erstellt: <?= $e($passkey['created_at']) ?><?php if($passkey['last_used_at']):?> · zuletzt genutzt: <?= $e($passkey['last_used_at']) ?><?php endif;?></small>
<form method="post" action="/settings/security/passkeys/delete"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="passkey_id" value="<?= $e($passkey['id']) ?>"><button class="danger" type="submit">Passkey entfernen</button></form></div><?php endforeach;?>
</section>
</main><script src="/assets/app.js" defer></script><script src="/assets/passkeys.js" defer></script></body></html>
