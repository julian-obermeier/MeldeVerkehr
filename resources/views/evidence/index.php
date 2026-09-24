<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$labels=[
'OVERVIEW'=>'Übersicht','PLATE'=>'Kennzeichen','VEHICLE'=>'Fahrzeug','VEHICLE_POSITION'=>'Fahrzeugposition',
'TRAFFIC_SIGN'=>'Verkehrszeichen','ADDITIONAL_SIGN'=>'Zusatzzeichen','OBSTRUCTION'=>'Behinderung',
'DANGER'=>'Gefährdung','PROPERTY_DAMAGE'=>'Sachschaden','CONTEXT'=>'Umfeld','PERMIT'=>'Mögliche Ausnahme/Berechtigung',
'TEMPORARY_SIGN'=>'Temporäre Beschilderung','OTHER'=>'Sonstiges'
];
$quality=['SUITABLE'=>'Geeignet','LIMITED'=>'Eingeschränkt','RETAKE_RECOMMENDED'=>'Neuaufnahme empfohlen','PENDING'=>'Prüfung ausstehend'];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest"><title>Beweise – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1000px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}label{display:block;font-weight:650;margin:10px 0 5px}input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #bac5d1;border-radius:8px}button{padding:10px 14px;border:0;border-radius:9px;background:#172033;color:#fff;font-weight:700;margin-top:12px}.danger{background:#8e1f1f}.muted{color:#66758a}.message,.error{padding:12px;border-radius:9px;margin-bottom:14px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}.item{border:1px solid #e1e7ed;border-radius:12px;padding:15px}.hash{font-family:ui-monospace,monospace;font-size:.8rem;word-break:break-all}.removed{opacity:.55}.tag{display:inline-block;padding:4px 8px;background:#edf1f5;border-radius:999px;font-size:.85rem}</style></head>
<body><main><p><a href="/cases/<?= rawurlencode($caseId) ?>">← Zurück zum Vorgang</a></p><h1>Beweiserfassung <?= !empty($case['public_number'])?'– '.$e($case['public_number']):'' ?></h1>
<p class="muted">Originaldateien werden geschützt außerhalb des öffentlichen Webverzeichnisses gespeichert und mit SHA‑256 dokumentiert.</p>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Bildnachweis hinzufügen</h2>
<form method="post" enctype="multipart/form-data" action="/cases/<?= rawurlencode($caseId) ?>/evidence"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Kategorie</label><select name="category" required><?php foreach($categories as $category):?><option value="<?= $e($category) ?>"><?= $e($labels[$category]??$category) ?></option><?php endforeach;?></select>
<label>Bilddatei</label><input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" capture="environment" required>
<p class="muted">JPEG, PNG oder WebP · maximal 20 MB. Die Kamera kann auf unterstützten Mobilgeräten direkt geöffnet werden.</p><button type="submit">Original sicher speichern</button></form></section>

<section class="card"><h2>Gespeicherte Nachweise</h2>
<?php if(!$items):?><p class="muted">Noch keine Bildnachweise gespeichert.</p><?php endif;?>
<div class="grid"><?php foreach($items as $item):?><article class="item <?= $item['status']==='REMOVED'?'removed':'' ?>"><span class="tag"><?= $e($labels[$item['category']]??$item['category']) ?></span><h3><?= $e($item['original_filename']) ?></h3><p><strong><?= $e($quality[$item['quality_state']]??$item['quality_state']) ?></strong></p><p class="muted"><?= $e($item['width']) ?> × <?= $e($item['height']) ?> px · <?= $e(round(((int)$item['file_size'])/1024,1)) ?> KB · <?= $e($item['mime_type']) ?></p><p class="muted">Arbeitskopie: <?= !empty($item['has_working_copy'])?'vorhanden':'nicht verfügbar' ?></p><p class="hash">SHA‑256: <?= $e($item['sha256']) ?></p><p class="muted">Status: <?= $e($item['status']) ?> · gespeichert: <?= $e($item['created_at']) ?></p>
<?php if($item['status']==='ACTIVE'):?><form method="post" action="/evidence/<?= rawurlencode($item['id']) ?>/remove"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($caseId) ?>"><button class="danger" type="submit">Aus aktivem Beweissatz entfernen</button></form><?php endif;?></article><?php endforeach;?></div>
</section></main><script src="/assets/app.js" defer></script></body></html>
