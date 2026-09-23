<?php $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Passwort vergessen</title></head>
<body><main style="max-width:520px;margin:50px auto;font-family:system-ui,sans-serif;padding:20px"><h1>Passwort zurücksetzen</h1>
<?php if($message):?><p><strong><?= $e($message) ?></strong></p><?php endif;?>
<form method="post" action="/forgot-password"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><label for="email">E-Mail</label><br><input id="email" type="email" name="email" required style="width:100%;padding:10px;box-sizing:border-box"><br><button type="submit" style="margin-top:14px">Link anfordern</button></form>
<p><a href="/login">Zurück zum Login</a></p></main></body></html>
