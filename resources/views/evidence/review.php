<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$c=$summary['case']; $package=$summary['latest_package'];
$quality=['SUITABLE'=>'Geeignet','LIMITED'=>'Eingeschränkt','RETAKE_RECOMMENDED'=>'Neuaufnahme empfohlen','PENDING'=>'Ausstehend'];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><title>Evidence-Review – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1050px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.item{border:1px solid #e1e7ed;border-radius:10px;padding:14px}.missing,.warn,.ok,.message,.error{padding:12px;border-radius:9px;margin:8px 0}.missing,.error{border:1px solid #c99;background:#fff7f7}.warn{border:1px solid #d6b36b;background:#fffaf0}.ok,.message{border:1px solid #8fb799}.muted{color:#66758a}.button,button{display:inline-block;padding:10px 14px;border:0;border-radius:9px;background:#172033;color:white;text-decoration:none;font-weight:700}.hash{font-family:ui-monospace,monospace;font-size:.8rem;word-break:break-all}</style></head>
<body><main><p><a href="/cases/<?= rawurlencode($c['id']) ?>/evidence">← Beweiserfassung</a></p><h1>Evidence-Review – <?= $e($c['public_number']) ?></h1>
<p class="muted">Diese Prüfung bewertet technische Vollständigkeit, Privacy-Freigaben und Dateiintegrität. Sie ersetzt keine behördliche oder rechtliche Würdigung.</p>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Aktive Nachweise</h2><div class="grid"><?php foreach($summary['items'] as $item):?><article class="item"><strong><?= $e($item['category']) ?></strong><p><?= $e($item['original_filename']) ?></p><p>Qualität: <?= $e($quality[$item['quality_state']]??$item['quality_state']) ?></p><p>Privacy: <?= $item['public_version_no']!==null?'bestätigt · PUBLIC v'.$e($item['public_version_no']):'offen' ?></p><?php if($item['public_sha256']):?><p class="hash">PUBLIC SHA‑256: <?= $e($item['public_sha256']) ?></p><?php endif;?></article><?php endforeach;?></div><?php if(!$summary['items']):?><p class="muted">Keine aktiven Nachweise.</p><?php endif;?></section>

<?php if($summary['missing']):?><section class="card"><h2>Blockierende Punkte</h2><?php foreach($summary['missing'] as $item):?><div class="missing"><?= $e($item) ?></div><?php endforeach;?></section><?php endif;?>
<?php if($summary['warnings']):?><section class="card"><h2>Hinweise</h2><?php foreach($summary['warnings'] as $item):?><div class="warn"><?= $e($item) ?></div><?php endforeach;?><p class="muted">Diese Hinweise sind keine automatische rechtliche Bewertung. Sie können nach eigener Prüfung bestätigt werden.</p></section><?php endif;?>

<?php if($package):?><section class="card ok"><h2>Letzte eingefrorene Beweismappe</h2><p>Version <?= $e($package['version_no']) ?> · Status <?= $e($package['status']) ?></p><p class="hash">Manifest SHA‑256: <?= $e($package['manifest_sha256']) ?></p><p class="muted">Eingefroren: <?= $e($package['frozen_at']) ?></p></section><?php endif;?>

<section class="card"><h2>Beweismappe einfrieren</h2>
<?php if($c['status']===MeldeVerkehrCasesCaseStatus::WAITING_FOR_EVIDENCE && $summary['ready']):?><form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/evidence/review"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><?php if($summary['warnings']):?><label><input type="checkbox" name="acknowledge_warnings" value="1" required style="width:auto"> Ich habe alle Hinweise geprüft und möchte die Beweismappe mit diesem Stand einfrieren.</label><br><?php else:?><input type="hidden" name="acknowledge_warnings" value="0"><?php endif;?><button type="submit">Evidence-Review bestätigen und Beweismappe einfrieren</button></form>
<?php elseif($c['status']===MeldeVerkehrCasesCaseStatus::READY_FOR_REVIEW && $package):?><div class="ok">Die Beweismappe ist eingefroren. Der Vorgang ist bereit für den nächsten Gesamt-Review.</div>
<?php else:?><p>Vor dem Einfrieren müssen alle blockierenden Punkte behoben sein.</p><?php endif;?>
</section></main></body></html>
