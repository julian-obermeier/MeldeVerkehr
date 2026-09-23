<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest"><title>Anmelden – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:500px;margin:60px auto;padding:22px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:16px;padding:26px}label{display:block;font-weight:650;margin:14px 0 6px}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #bbc5d2;border-radius:9px;font:inherit}button{width:100%;margin-top:20px;padding:13px;border:0;border-radius:9px;background:#172033;color:white;font-weight:700}.error,.message{padding:12px;border:1px solid #c9a0a0;border-radius:9px;margin:12px 0}.message{border-color:#9bb7a0}.links{display:flex;justify-content:space-between;gap:12px;margin-top:18px;flex-wrap:wrap}</style></head>
<body><main><div class="card"><h1>Anmelden</h1>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?>
<?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>
<form method="post" action="/login"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>E-Mail</label><input type="email" name="email" value="<?= $e($email) ?>" autocomplete="email" required>
<label>Passwort</label><input type="password" name="password" autocomplete="current-password" required>
<button type="submit">Anmelden</button></form>
<div class="links"><a href="/register">Registrieren</a><a href="/forgot-password">Passwort vergessen?</a></div></div></main><script src="/assets/app.js" defer></script></body></html>
