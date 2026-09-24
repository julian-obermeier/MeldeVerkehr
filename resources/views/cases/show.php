<?php
$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$c=$data['case']; $v=$data['vehicle']; $l=$data['location'];
$statusLabels=['DRAFT'=>'Entwurf','CAPTURE_IN_PROGRESS'=>'Erfassung läuft','WAITING_FOR_EVIDENCE'=>'Beweise fehlen','READY_FOR_REVIEW'=>'Prüfbereit','REVIEW_REQUIRED'=>'Prüfung erforderlich','READY_FOR_SUBMISSION'=>'Versandbereit','SUBMISSION_PENDING'=>'Versand läuft','SENT'=>'Gesendet','DELIVERED'=>'Zugestellt','DELIVERY_UNKNOWN'=>'Zustellung unklar','DELIVERY_FAILED'=>'Zustellfehler','AUTHORITY_REPLY'=>'Behördenantwort','USER_ACTION_REQUIRED'=>'Aktion erforderlich','AUTHORITY_PROCESSING'=>'Behörde bearbeitet','CORRECTION_PENDING'=>'Korrektur','WITHDRAWAL_PENDING'=>'Rücknahme','CLOSED'=>'Abgeschlossen','ARCHIVED'=>'Archiviert','DELETION_PENDING'=>'Löschung vorgemerkt'];
$editable=in_array($c['status'],['DRAFT','CAPTURE_IN_PROGRESS','WAITING_FOR_EVIDENCE','READY_FOR_REVIEW','REVIEW_REQUIRED'],true);
$vehicleDone=$v!==null;
$locationDone=$l!==null;
$primaryOffense=$data['offenses'][0]??null;
$offenseDone=$primaryOffense!==null && ($primaryOffense['stable_key']??'')!=='UNCLASSIFIED_PARKING';
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest"><title><?= $e($c['public_number']) ?> – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1050px;margin:26px auto;padding:20px}.hero,.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.number{font-weight:850;font-size:1.4rem}.muted{color:#66758a}.message,.error{padding:12px;border-radius:9px;margin-bottom:14px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}label{display:block;font-weight:650;margin-bottom:5px}input,select,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #bac5d1;border-radius:8px;font:inherit}textarea{min-height:80px}button{margin-top:12px;padding:10px 14px;border:0;border-radius:9px;background:#172033;color:#fff;font-weight:700}.secondary{background:#fff;color:#172033;border:1px solid #9aa8b7}.step{font-weight:800}.done{color:#24683b}.todo{color:#8a5a0a}.locked{opacity:.62}.timeline{border-left:2px solid #d8dfe7;padding-left:15px}.event{margin-bottom:12px}.next{border-left:5px solid #172033}.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf1f5;font-size:.85rem}</style></head>
<body><main><p><a href="/cases">← Vorgänge</a></p>
<section class="hero"><div class="number"><?= $e($c['public_number']) ?></div><p><strong><?= $e($statusLabels[$c['status']]??$c['status']) ?></strong></p><p class="muted">Angelegt: <?= $e($c['created_at']) ?> · Zuletzt geändert: <?= $e($c['updated_at']) ?></p></section>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Erfassungsfortschritt</h2><div class="grid">
<div><span class="step <?= $vehicleDone?'done':'todo' ?>">1. Fahrzeug <?= $vehicleDone?'✓':'' ?></span></div>
<div><span class="step <?= $locationDone?'done':'todo' ?>">2. Standort <?= $locationDone?'✓':'' ?></span></div>
<div><span class="step <?= $offenseDone?'done':'todo' ?>">3. Tatbestand <?= $offenseDone?'✓':'' ?></span></div>
<div><span class="step todo">4. Beweise – M3</span></div>
</div>
<?php if(!$vehicleDone):?><p class="muted">Als Nächstes: Fahrzeug erfassen.</p><?php elseif(!$locationDone):?><p class="muted">Als Nächstes: Standort erfassen.</p><?php elseif(!$offenseDone):?><p class="muted">Als Nächstes: konkreten Tatbestand auswählen. Der aktuell verfügbare neutrale Platzhalter macht einen Vorgang noch nicht versandbereit.</p><?php else:?><p class="muted">Grunddaten vollständig. Die Beweisdokumentation folgt im nächsten Modul.</p><?php endif;?>
</section>

<?php if($editable):?>
<section class="card <?= !$vehicleDone?'next':'' ?>"><h2>1. Fahrzeug</h2><form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/vehicle"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><div class="fields"><div><label>Kennzeichen</label><input name="license_plate" value="<?= $e($v['license_plate']??'') ?>" autocomplete="off" autocapitalize="characters" required></div><div><label>Fahrzeugart</label><select name="vehicle_type" required><?php foreach(['PKW','MOTORRAD','TRANSPORTER','LKW','BUS','ANHÄNGER','WOHNMOBIL','SONSTIGES'] as $type):?><option value="<?= $e($type) ?>" <?= (($v['vehicle_type']??'PKW')===$type)?'selected':'' ?>><?= $e($type) ?></option><?php endforeach;?></select></div><div><label>Farbe optional</label><input name="color" value="<?= $e($v['color']??'') ?>"></div><div><label>Hersteller optional</label><input name="make" value="<?= $e($v['make']??'') ?>"></div><div><label>Modell optional</label><input name="model" value="<?= $e($v['model']??'') ?>"></div></div><button type="submit">Fahrzeug speichern</button></form></section>

<?php if($vehicleDone):?>
<section class="card <?= !$locationDone?'next':'' ?>"><h2>2. Standort</h2><form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/location"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<div class="fields"><div><label>Breitengrad</label><input id="latitude" name="latitude" inputmode="decimal" value="<?= $e($l['latitude']??'') ?>"></div><div><label>Längengrad</label><input id="longitude" name="longitude" inputmode="decimal" value="<?= $e($l['longitude']??'') ?>"></div></div>
<button type="button" class="secondary" id="useGps">Aktuellen Standort übernehmen</button><span id="gpsStatus" class="muted" role="status"></span>
<div class="fields" style="margin-top:12px"><div><label>Straße</label><input name="street" value="<?= $e($l['street']??'') ?>"></div><div><label>Hausnummer</label><input name="house_number" value="<?= $e($l['house_number']??'') ?>"></div><div><label>PLZ</label><input name="postal_code" value="<?= $e($l['postal_code']??'') ?>"></div><div><label>Ort</label><input name="city" value="<?= $e($l['city']??'') ?>"></div><div><label>Land</label><input name="country" value="<?= $e($l['country']??'DE') ?>" maxlength="2"></div><div><label>Verkehrsraum</label><select name="traffic_space_type"><?php foreach(['UNKNOWN'=>'Unklar','ROADWAY'=>'Fahrbahn','SIDEWALK'=>'Gehweg','BIKE_LANE'=>'Radfahrstreifen','BIKE_PATH'=>'Radweg','SHOULDER'=>'Seitenstreifen','PARKING_AREA'=>'Parkfläche','PEDESTRIAN_ZONE'=>'Fußgängerzone','PRIVATE_PROPERTY'=>'Privatfläche'] as $key=>$label):?><option value="<?= $e($key) ?>" <?= (($l['traffic_space_type']??'UNKNOWN')===$key)?'selected':'' ?>><?= $e($label) ?></option><?php endforeach;?></select></div><div><label>Öffentlich/privat</label><select name="access_type"><?php foreach(['UNCLEAR'=>'Unklar','PUBLIC'=>'Öffentlich','PRIVATE'=>'Privat'] as $key=>$label):?><option value="<?= $e($key) ?>" <?= (($l['access_type']??'UNCLEAR')===$key)?'selected':'' ?>><?= $e($label) ?></option><?php endforeach;?></select></div></div>
<label style="margin-top:10px">Lagebeschreibung optional</label><textarea name="location_description"><?= $e($l['location_description']??'') ?></textarea><button type="submit">Standort speichern</button></form></section>
<?php else:?><section class="card locked"><h2>2. Standort</h2><p>Wird freigeschaltet, sobald das Fahrzeug gespeichert wurde.</p></section><?php endif;?>

<?php if($vehicleDone && $locationDone):?>
<section class="card <?= !$offenseDone?'next':'' ?>"><h2>3. Tatbestand</h2><form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/offense"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><label>Tatbestand</label><select name="offense_version_id" required><?php foreach($offenses as $offense):?><option value="<?= $e($offense['id']) ?>" <?= (($primaryOffense['offense_version_id']??'')===$offense['id'])?'selected':'' ?>><?= $e($offense['category'].' – '.$offense['title']) ?></option><?php endforeach;?></select><p class="muted">Die Datenbank enthält aktuell bewusst nur einen neutralen Entwurfs-Platzhalter. Konkrete rechtliche Tatbestände werden erst nach fachlicher Verifikation versioniert ergänzt.</p><button type="submit">Tatbestand speichern</button></form>
<?php if($primaryOffense):?><p><span class="tag"><?= $e($primaryOffense['category']) ?></span> <?= $e($primaryOffense['title']) ?></p><?php endif;?></section>
<?php else:?><section class="card locked"><h2>3. Tatbestand</h2><p>Wird freigeschaltet, sobald Fahrzeug und Standort vollständig erfasst sind.</p></section><?php endif;?>
<?php else:?><section class="card"><p>Dieser Vorgang ist in seinem aktuellen Status nicht direkt bearbeitbar.</p></section><?php endif;?>

<section class="card"><h2>Statushistorie</h2><div class="timeline"><?php foreach(array_reverse($data['history']) as $item):?><div class="event"><strong><?= $e($statusLabels[$item['new_status']]??$item['new_status']) ?></strong><br><small class="muted"><?= $e($item['created_at']) ?><?php if($item['reason']):?> · <?= $e($item['reason']) ?><?php endif;?></small></div><?php endforeach;?></div></section>
</main><script src="/assets/app.js" defer></script><script>
(() => {
 const button=document.getElementById('useGps');
 if(!button) return;
 const status=document.getElementById('gpsStatus');
 button.addEventListener('click',()=>{
   if(!navigator.geolocation){status.textContent=' Geolocation wird von diesem Browser nicht unterstützt.';return;}
   button.disabled=true; status.textContent=' Standort wird ermittelt …';
   navigator.geolocation.getCurrentPosition(
     pos=>{
       document.getElementById('latitude').value=pos.coords.latitude.toFixed(7);
       document.getElementById('longitude').value=pos.coords.longitude.toFixed(7);
       status.textContent=' Standort übernommen.';
       button.disabled=false;
     },
     ()=>{status.textContent=' Standort konnte nicht ermittelt werden.';button.disabled=false;},
     {enableHighAccuracy:true,timeout:10000,maximumAge:30000}
   );
 });
})();
</script></body></html>