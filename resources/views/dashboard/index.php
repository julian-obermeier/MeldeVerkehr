<?php $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1000px;margin:35px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:16px;padding:24px}.actions{display:flex;gap:12px;flex-wrap:wrap}a.button,button{padding:11px 16px;border-radius:9px;border:0;background:#172033;color:#fff;text-decoration:none;font-weight:700}</style></head>
<body><main><div class="card"><h1>Willkommen, <?= $e($user['first_name']) ?></h1><p>Dein MeldeVerkehr-Konto ist aktiv und die E-Mail-Adresse ist bestätigt.</p>
<div class="actions"><a class="button" href="#">Neue Meldung</a><?php if($isSuperAdmin):?><a class="button" href="/admin">Administration</a><?php endif;?></div>
<form method="post" action="/logout" style="margin-top:24px"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">Abmelden</button></form></div></main></body></html>
