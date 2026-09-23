<?php $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($title) ?></title></head>
<body><main style="max-width:620px;margin:60px auto;font-family:system-ui,sans-serif;padding:20px"><h1><?= $e($title) ?></h1><p><?= $e($message) ?></p><p><a href="<?= $e($link) ?>"><?= $e($linkText) ?></a></p></main></body></html>
