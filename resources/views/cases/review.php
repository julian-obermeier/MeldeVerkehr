<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$data=$summary['data']; $c=$data['case']; $v=$data['vehicle']; $l=$data['location']; $o=$data['offenses'][0]??null;
$canConfirm=$summary['ready'] && $c['status']===\MeldeVerkehr\Cases\CaseStatus::READY_FOR_REVIEW;
$duration=$c['observation_duration_seconds']??null;
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest"><title>Grunddaten prüfen – <?= $e($c['public_number']) ?></title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:980px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}.label{color:#66758a;font-size:.9rem}.value{font-weight:700;margin-top:4px}.ok,.warn,.missing{padding:12px;border-radius:9px;margin:10px 0}.ok{border:1px solid #8fb799}.warn{border:1px solid #d6b36b;background:#fffaf0}.missing{border:1px solid #c99;background:#fff7f7}.button,button{display:inline-block;padding:11px 15px;border:0;border-radius:9px;background:#172033;color:white;text-decoration:none;font-weight:700}.secondary{background:white;color:#172033;border:1px solid #9aa8b7}.checks label{display:flex;gap:8px;align-items:flex-start}.checks input{margin-top:4px}</style></head>
<body><main>
<p><a href="/cases/<?= rawurlencode($c['id']) ?>">← Zurück zum Vorgang</a></p>
<h1>Grunddaten prüfen</h1><p>Prüfe die Angaben, bevor der Vorgang in die Beweiserfassung übergeht.</p>
<?php if($message):?><div class="ok"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="missing"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Fahrzeug</h2><div class="grid"><div><div class="label">Kennzeichen</div><div class="value"><?= $e($v['license_plate']??'–') ?></div></div><div><div class="label">Fahrzeugart</div><div class="value"><?= $e($v['vehicle_type']??'–') ?></div></div><div><div class="label">Hersteller / Modell</div><div class="value"><?= $e(trim(($v['make']??'').' '.($v['model']??''))?:'–') ?></div></div></div></section>

<section class="card"><h2>Standort</h2><div class="grid"><div><div class="label">Adresse</div><div class="value"><?= $e(trim(($l['street']??'').' '.($l['house_number']??'').', '.($l['postal_code']??'').' '.($l['city']??''),', '))?:'–' ?></div></div><div><div class="label">GPS</div><div class="value"><?= $e(($l['latitude']??'–').' / '.($l['longitude']??'–')) ?></div></div><div><div class="label">Verkehrsraum</div><div class="value"><?= $e($l['traffic_space_type']??'–') ?></div></div><div><div class="label">Öffentlich / privat</div><div class="value"><?= $e($l['access_type']??'–') ?></div></div></div></section>

<section class="card"><h2>Beobachtung</h2><div class="grid"><div><div class="label">Beginn</div><div class="value"><?= $e($c['observed_from_local']??'–') ?></div></div><div><div class="label">Ende</div><div class="value"><?= $e($c['observed_until_local']??'–') ?></div></div><div><div class="label">Dauer</div><div class="value"><?= $duration!==null?$e(round($duration/60,1).' Minuten'):'–' ?></div></div></div><p>Behinderung: <strong><?= !empty($c['obstruction'])?'Ja':'Nein' ?></strong> · Gefährdung: <strong><?= !empty($c['endangerment'])?'Ja':'Nein' ?></strong> · Sachschaden: <strong><?= !empty($c['damage'])?'Ja':'Nein' ?></strong></p></section>

<section class="card"><h2>Tatbestand</h2><div class="value"><?= $e($o['title']??'Nicht ausgewählt') ?></div><?php if(!empty($o['category'])):?><p><?= $e($o['category']) ?></p><?php endif;?><p class="label">Die Auswahl dokumentiert den vom Nutzer bestätigten Tatbestand. Die spätere behördliche Würdigung erfolgt unabhängig davon.</p></section>

<?php if($summary['missing']):?><section class="card"><h2>Fehlende Angaben</h2><?php foreach($summary['missing'] as $item):?><div class="missing"><?= $e($item) ?></div><?php endforeach;?><a class="button secondary" href="/cases/<?= rawurlencode($c['id']) ?>">Angaben ergänzen</a></section><?php endif;?>

<?php if($summary['warnings']):?><section class="card"><h2>Hinweise</h2><?php foreach($summary['warnings'] as $item):?><div class="warn"><?= $e($item) ?></div><?php endforeach;?></section><?php endif;?>

<section class="card"><h2>Übergang zur Beweiserfassung</h2>
<?php if($canConfirm):?><form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/review"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><?php if($summary['warnings']):?><div class="checks"><label><input type="checkbox" name="acknowledge_warnings" value="1" required><span>Ich habe die Hinweise geprüft und möchte mit diesen Grunddaten zur Beweiserfassung weitergehen.</span></label></div><?php else:?><input type="hidden" name="acknowledge_warnings" value="0"><?php endif;?><button type="submit">Grunddaten bestätigen und weiter</button></form>
<?php elseif($c['status']===\MeldeVerkehr\Cases\CaseStatus::WAITING_FOR_EVIDENCE):?><div class="ok">Die Grunddaten wurden bereits bestätigt. Der Vorgang ist bereit für M3.</div>
<?php else:?><p>Der Vorgang kann erst bestätigt werden, wenn alle Pflichtangaben vollständig sind und der Status „Grunddaten prüfen“ erreicht ist.</p><?php endif;?>
</section>
</main><script src="/assets/app.js" defer></script></body></html>
