<?php
$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statusLabels = [
 'DRAFT'=>'Entwurf','CAPTURE_IN_PROGRESS'=>'Erfassung läuft','WAITING_FOR_EVIDENCE'=>'Beweise fehlen',
 'READY_FOR_REVIEW'=>'Prüfbereit','REVIEW_REQUIRED'=>'Prüfung erforderlich','READY_FOR_SUBMISSION'=>'Versandbereit',
 'SUBMISSION_PENDING'=>'Versand läuft','SENT'=>'Gesendet','DELIVERED'=>'Zugestellt',
 'DELIVERY_UNKNOWN'=>'Zustellung unklar','DELIVERY_FAILED'=>'Zustellfehler','AUTHORITY_REPLY'=>'Behördenantwort',
 'USER_ACTION_REQUIRED'=>'Aktion erforderlich','AUTHORITY_PROCESSING'=>'Behörde bearbeitet',
 'CORRECTION_PENDING'=>'Korrektur','WITHDRAWAL_PENDING'=>'Rücknahme','CLOSED'=>'Abgeschlossen',
 'ARCHIVED'=>'Archiviert','DELETION_PENDING'=>'Löschung vorgemerkt'
];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest"><title>Vorgänge – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1100px;margin:28px auto;padding:20px}.toolbar,.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:20px}.toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}.button,button{display:inline-block;padding:11px 15px;border:0;border-radius:9px;background:#172033;color:#fff;text-decoration:none;font-weight:700}.filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap}select{padding:10px;border:1px solid #bac5d1;border-radius:9px}.list{display:grid;gap:12px;margin-top:16px}.case{display:grid;grid-template-columns:1fr auto;gap:12px}.meta{color:#66758a}.number{font-weight:800}.status{font-size:.9rem;font-weight:700}.empty{text-align:center;padding:38px}.plate{font-family:ui-monospace,monospace;font-weight:700}</style></head>
<body><main><p><a href="/dashboard">← Dashboard</a></p><div class="toolbar"><div><h1 style="margin:0">Meine Vorgänge</h1><p class="meta">Entwürfe und laufende Meldungen</p></div><form method="post" action="/cases"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">+ Neue Meldung</button></form></div>
<div class="toolbar" style="margin-top:12px"><form class="filter" method="get" action="/cases"><label for="status">Status</label><select id="status" name="status"><option value="">Alle</option><?php foreach($statuses as $item):?><option value="<?= $e($item) ?>" <?= $status===$item?'selected':'' ?>><?= $e($statusLabels[$item]??$item) ?></option><?php endforeach;?></select><button type="submit">Filtern</button></form></div>
<div class="list">
<?php if(!$cases):?><div class="card empty"><h2>Noch keine Vorgänge</h2><p class="meta">Mit „Neue Meldung“ wird zunächst ein sicherer Entwurf angelegt.</p></div><?php endif;?>
<?php foreach($cases as $case):?><article class="card case"><div><div class="number"><?= $e($case['public_number']) ?></div><div class="status"><?= $e($statusLabels[$case['status']]??$case['status']) ?></div><p class="meta"><?php if($case['license_plate']):?><span class="plate"><?= $e($case['license_plate']) ?></span> · <?php endif;?><?= $e(trim(($case['street']??'').' '.($case['house_number']??'').' '.($case['city']??''))) ?: 'Ort noch nicht erfasst' ?></p><small class="meta">Zuletzt geändert: <?= $e($case['updated_at']) ?></small></div><div><a class="button" href="/cases/<?= rawurlencode($case['id']) ?>">Öffnen</a></div></article><?php endforeach;?>
</div></main><script src="/assets/app.js" defer></script></body></html>