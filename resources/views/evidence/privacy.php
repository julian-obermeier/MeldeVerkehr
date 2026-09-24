<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$item=$detail['evidence']; $regions=$detail['regions']; $review=$detail['review'];
$editable=($item['case_status']??'')===\MeldeVerkehr\Cases\CaseStatus::WAITING_FOR_EVIDENCE;
$types=['FACE'=>'Gesicht','FOREIGN_PLATE'=>'Fremdes Kennzeichen','PERSON'=>'Person','SENSITIVE'=>'Sensibles Detail','OTHER'=>'Sonstiges'];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><title>Privacy-Prüfung – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1050px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.message,.error{padding:12px;border-radius:9px;margin-bottom:14px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}.stage{position:relative;display:inline-block;max-width:100%;touch-action:none;user-select:none}.stage img{display:block;max-width:100%;max-height:70vh;border-radius:10px}.region,.selection{position:absolute;border:2px solid #b00020;background:rgba(176,0,32,.18);box-sizing:border-box;pointer-events:none}.selection{border-style:dashed;background:rgba(23,32,51,.14);border-color:#172033}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-top:12px}label{display:block;font-weight:650;margin-bottom:5px}input,select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #bac5d1;border-radius:8px}button,.button{display:inline-block;padding:10px 14px;border:0;border-radius:9px;background:#172033;color:white;font-weight:700;text-decoration:none;margin-top:10px}.danger{background:#8e1f1f}.muted{color:#66758a}.region-list{display:grid;gap:8px}.region-row{border:1px solid #e1e7ed;border-radius:10px;padding:12px}.ok{border-left:5px solid #2e7d45}.warn{border-left:5px solid #c58a1f}</style></head>
<body><main>
<p><a href="/cases/<?= rawurlencode($item['case_id']) ?>/evidence">← Zurück zur Beweiserfassung</a></p>
<h1>Privacy-Prüfung</h1><p><strong><?= $e($item['original_filename']) ?></strong> · Kategorie <?= $e($item['category']) ?></p>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Arbeitskopie prüfen</h2><p class="muted">Ziehe auf dem Bild ein Rechteck über Gesichter, fremde Kennzeichen oder andere sensible Bereiche. Die Originaldatei wird dabei niemals verändert.</p>
<div class="stage" id="privacyStage">
<img id="privacyImage" draggable="false" src="/evidence/<?= rawurlencode($item['id']) ?>/preview?variant=WORKING" alt="Geschützte Arbeitskopie">
<?php foreach($regions as $region):?><span class="region" style="left:<?= $e(((float)$region['x'])*100) ?>%;top:<?= $e(((float)$region['y'])*100) ?>%;width:<?= $e(((float)$region['width'])*100) ?>%;height:<?= $e(((float)$region['height'])*100) ?>%"></span><?php endforeach;?>
<span class="selection" id="selection" hidden></span>
</div>
<?php if($editable):?><form method="post" action="/evidence/<?= rawurlencode($item['id']) ?>/privacy/regions" id="regionForm"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<div class="fields"><div><label>Typ</label><select name="region_type"><?php foreach($regionTypes as $type):?><option value="<?= $e($type) ?>"><?= $e($types[$type]??$type) ?></option><?php endforeach;?></select></div>
<div><label>X %</label><input id="xPct" name="x_pct" type="number" min="0" max="100" step="0.01" required></div>
<div><label>Y %</label><input id="yPct" name="y_pct" type="number" min="0" max="100" step="0.01" required></div>
<div><label>Breite %</label><input id="wPct" name="width_pct" type="number" min="0.01" max="100" step="0.01" required></div>
<div><label>Höhe %</label><input id="hPct" name="height_pct" type="number" min="0.01" max="100" step="0.01" required></div></div>
<button type="submit">Privacy-Bereich speichern</button></form><?php else:?><p class="muted">Die Beweismappe ist eingefroren; Privacy-Bereiche sind nur noch lesbar.</p><?php endif;?></section>

<section class="card"><h2>Markierte Bereiche</h2>
<?php if(!$regions):?><p class="muted">Keine Redaktionsbereiche markiert. Das ist zulässig, wenn auf der Arbeitskopie keine fremden personenbezogenen Details sichtbar sind.</p><?php endif;?>
<div class="region-list"><?php foreach($regions as $region):?><div class="region-row"><strong><?= $e($types[$region['region_type']]??$region['region_type']) ?></strong><br><small class="muted">X <?= $e(round(((float)$region['x'])*100,2)) ?> %, Y <?= $e(round(((float)$region['y'])*100,2)) ?> %, Breite <?= $e(round(((float)$region['width'])*100,2)) ?> %, Höhe <?= $e(round(((float)$region['height'])*100,2)) ?> %</small>
<?php if($editable):?><form method="post" action="/privacy-regions/<?= rawurlencode($region['id']) ?>/dismiss"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="evidence_id" value="<?= $e($item['id']) ?>"><button class="danger" type="submit">Bereich entfernen</button></form><?php endif;?></div><?php endforeach;?></div></section>

<section class="card <?= $review?'ok':'warn' ?>"><h2>Privacy-Prüfung bestätigen</h2>
<?php if($review):?><p><strong>Bestätigt.</strong> PUBLIC-Version <?= $e($review['public_version_no']) ?> · geprüft <?= $e($review['reviewed_at']) ?></p><p><a class="button" href="/evidence/<?= rawurlencode($item['id']) ?>/preview?variant=PUBLIC" target="_blank" rel="noopener">Redigierte Kopie ansehen</a></p><?php else:?><p>Prüfe das vollständige Bild und bestätige anschließend die Privacy-Freigabe. Auch bei null markierten Bereichen wird eine getrennte PUBLIC-Kopie erzeugt.</p><?php endif;?>
<?php if($editable):?><form method="post" action="/evidence/<?= rawurlencode($item['id']) ?>/privacy/confirm"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><label style="font-weight:500"><input type="checkbox" name="privacy_confirm" value="1" required style="width:auto"> Ich habe die Arbeitskopie vollständig auf Gesichter, fremde Kennzeichen, Personen und sensible Details geprüft.</label><br><button type="submit">Privacy-Prüfung bestätigen und PUBLIC-Kopie erzeugen</button></form><?php endif;?></section>
</main>
<script>
(() => {
 const stage=document.getElementById('privacyStage'), img=document.getElementById('privacyImage'), selection=document.getElementById('selection');
 if(!stage||!img||!selection) return;
 let start=null;
 const values={x:document.getElementById('xPct'),y:document.getElementById('yPct'),w:document.getElementById('wPct'),h:document.getElementById('hPct')};
 if(!values.x||!values.y||!values.w||!values.h) return;
 const point=e=>{const r=img.getBoundingClientRect();return{x:Math.max(0,Math.min(r.width,e.clientX-r.left)),y:Math.max(0,Math.min(r.height,e.clientY-r.top)),r};};
 stage.addEventListener('pointerdown',e=>{if(e.button!==0)return;start=point(e);stage.setPointerCapture(e.pointerId);selection.hidden=false;});
 stage.addEventListener('pointermove',e=>{if(!start)return;const p=point(e),x=Math.min(start.x,p.x),y=Math.min(start.y,p.y),w=Math.abs(p.x-start.x),h=Math.abs(p.y-start.y);selection.style.left=(x/p.r.width*100)+'%';selection.style.top=(y/p.r.height*100)+'%';selection.style.width=(w/p.r.width*100)+'%';selection.style.height=(h/p.r.height*100)+'%';});
 stage.addEventListener('pointerup',e=>{if(!start)return;const p=point(e),x=Math.min(start.x,p.x),y=Math.min(start.y,p.y),w=Math.abs(p.x-start.x),h=Math.abs(p.y-start.y);values.x.value=(x/p.r.width*100).toFixed(2);values.y.value=(y/p.r.height*100).toFixed(2);values.w.value=(w/p.r.width*100).toFixed(2);values.h.value=(h/p.r.height*100).toFixed(2);start=null;});
})();
</script></body></html>
