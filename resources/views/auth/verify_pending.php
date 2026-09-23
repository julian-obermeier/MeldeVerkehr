<?php $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>E-Mail bestätigen</title></head>
<body><main style="max-width:650px;margin:50px auto;font-family:system-ui,sans-serif;padding:20px"><h1>E-Mail-Adresse bestätigen</h1>
<?php if($message):?><p><strong><?= $e($message) ?></strong></p><?php endif;?>
<p>Dein Konto verwendet <strong><?= $e($email) ?></strong>. Vor dem vollständigen Zugriff muss die Adresse bestätigt werden.</p>
<form method="post" action="/verify-email/resend"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">Bestätigungsmail erneut senden</button></form>
<form method="post" action="/logout" style="margin-top:16px"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">Abmelden</button></form>
</main></body></html>
