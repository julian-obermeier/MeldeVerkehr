<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registrieren – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:520px;margin:50px auto;padding:22px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:16px;padding:26px}label{display:block;font-weight:650;margin:14px 0 6px}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #bbc5d2;border-radius:9px;font:inherit}button{width:100%;margin-top:20px;padding:13px;border:0;border-radius:9px;background:#172033;color:white;font-weight:700}.error{padding:12px;border:1px solid #c99;border-radius:9px}.links{text-align:center;margin-top:18px}</style></head>
<body><main><div class="card"><h1>Konto erstellen</h1>
<?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>
<form method="post" action="/register"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Vorname</label><input name="first_name" value="<?= $e($old['first_name']??'') ?>" autocomplete="given-name" required>
<label>Nachname</label><input name="last_name" value="<?= $e($old['last_name']??'') ?>" autocomplete="family-name" required>
<label>E-Mail</label><input type="email" name="email" value="<?= $e($old['email']??'') ?>" autocomplete="email" required>
<label>Passwort</label><input type="password" name="password" minlength="12" autocomplete="new-password" required>
<label>Passwort bestätigen</label><input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required>
<button type="submit">Registrieren</button></form>
<div class="links"><a href="/login">Bereits registriert? Anmelden</a></div></div></main></body></html>
