<?php $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Neues Passwort</title></head>
<body><main style="max-width:520px;margin:50px auto;font-family:system-ui,sans-serif;padding:20px"><h1>Neues Passwort festlegen</h1>
<?php if($error):?><p><strong><?= $e($error) ?></strong></p><?php endif;?>
<form method="post" action="/reset-password"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="token" value="<?= $e($token) ?>">
<label>Neues Passwort</label><br><input type="password" name="password" minlength="12" autocomplete="new-password" required style="width:100%;padding:10px;box-sizing:border-box"><br>
<label>Passwort bestätigen</label><br><input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required style="width:100%;padding:10px;box-sizing:border-box"><br>
<button type="submit" style="margin-top:14px">Passwort ändern</button></form></main></body></html>
