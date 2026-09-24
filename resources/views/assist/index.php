<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$data=$overview['case_data']; $c=$data['case']; $provider=$overview['provider']; $editable=$overview['editable'];
$qualityLabels=['SUITABLE'=>'Geeignet','LIMITED'=>'Eingeschränkt','RETAKE_RECOMMENDED'=>'Neuaufnahme empfohlen'];
$typeLabels=['LICENSE_PLATE'=>'Kennzeichen','TRAFFIC_SIGN'=>'Verkehrszeichen','ADDITIONAL_SIGN'=>'Zusatzzeichen','OFFENSE'=>'Tatbestand'];
$statusLabels=['PENDING'=>'Offen','CONFIRMED'=>'Bestätigt','REJECTED'=>'Verworfen','APPLIED'=>'Übernommen'];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><title>Intelligente Assistenz – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1100px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:14px}.item{border:1px solid #e1e7ed;border-radius:12px;padding:15px}.message,.error,.warn,.ok{padding:12px;border-radius:9px;margin:10px 0}.message,.ok{border:1px solid #8fb799}.error{border:1px solid #c99;background:#fff7f7}.warn{border:1px solid #d6b36b;background:#fffaf0}.muted{color:#66758a}.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf1f5;font-size:.84rem}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}button,.button{display:inline-block;padding:9px 12px;border:0;border-radius:9px;background:#172033;color:#fff;text-decoration:none;font-weight:700}.secondary{background:#fff;color:#172033;border:1px solid #9aa8b7}.danger{background:#8e1f1f}.metric{font-family:ui-monospace,monospace;font-size:.85rem}.suggestion{white-space:pre-wrap;background:#f8fafc;border-radius:9px;padding:10px}</style></head><body><main>
<p><a href="/cases/<?= rawurlencode($c['id']) ?>">← Zurück zum Vorgang</a></p>
<h1>Intelligente Assistenz</h1>
<p class="muted">Alle Ergebnisse sind Vorschläge. Rechtlich relevante Vorgangsdaten werden niemals ohne deine ausdrückliche Bestätigung geändert.</p>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Providerstatus</h2>
<?php if($provider['enabled']):?><div class="warn"><strong>Externe Analyse aktiviert:</strong> <?= $e($provider['name']) ?>. Nur geschützte Analysekopien werden entsprechend der Konfiguration übertragen.</div>
<?php else:?><div class="ok"><strong>Externe Analyse deaktiviert.</strong> Aktuell werden keine Bilder an externe Vision-/OCR-Dienste übertragen.</div><?php endif;?>
<p>Lokale Qualitätsanalyse: <strong>serverseitig / ohne externen Dienst</strong></p>
</section>

<section class="card"><h2>Bildnachweise & Qualität</h2><div class="grid">
<?php foreach($overview['evidence'] as $item):?><article class="item">
<span class="tag"><?= $e($item['category']) ?></span><h3><?= $e($item['original_filename']) ?></h3>
<p>Technischer Status: <strong><?= $e($qualityLabels[$item['overall_state']??$item['quality_state']]??($item['overall_state']??$item['quality_state'])) ?></strong></p>
<?php if($item['created_at']):?><div class="metric">Helligkeit: <?= $e($item['brightness_mean']) ?> · Kontrast: <?= $e($item['contrast_stddev']) ?> · Schärfe: <?= $e($item['sharpness_score']) ?></div><?php endif;?>
<?php foreach($item['warnings'] as $warning):?><div class="warn"><?= $e($warning) ?></div><?php endforeach;?>
<?php if($editable):?><div class="actions">
<form method="post" action="/assist/evidence/<?= rawurlencode($item['evidence_id']) ?>/quality"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><button type="submit" class="secondary">Lokale Qualität prüfen</button></form>
<?php if($provider['enabled']):?>
<form method="post" action="/assist/evidence/<?= rawurlencode($item['evidence_id']) ?>/analyze"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><input type="hidden" name="purpose" value="PLATE_OCR"><button type="submit">Kennzeichen OCR</button></form>
<form method="post" action="/assist/evidence/<?= rawurlencode($item['evidence_id']) ?>/analyze"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><input type="hidden" name="purpose" value="TRAFFIC_SIGNS"><button type="submit">Schilder analysieren</button></form>
<?php endif;?></div><?php endif;?>
</article><?php endforeach;?>
<?php if(!$overview['evidence']):?><p class="muted">Noch keine Bildnachweise vorhanden.</p><?php endif;?>
</div></section>

<section class="card"><h2>Assistenzvorschläge</h2>
<?php if(!$overview['suggestions']):?><p class="muted">Noch keine Vorschläge vorhanden.</p><?php endif;?>
<div class="grid"><?php foreach($overview['suggestions'] as $s):?><article class="item">
<span class="tag"><?= $e($typeLabels[$s['suggestion_type']]??$s['suggestion_type']) ?></span>
<p>Status: <strong><?= $e($statusLabels[$s['status']]??$s['status']) ?></strong><?php if($s['confidence']!==null):?> · Confidence <?= $e(round($s['confidence']*100,1)) ?> %<?php endif;?></p>
<div class="suggestion"><?= $e(json_encode($s['value'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></div>
<?php if($editable&&$s['status']==='PENDING'):?><div class="actions">
<form method="post" action="/assist/suggestions/<?= rawurlencode($s['id']) ?>/confirm"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><button type="submit">Bestätigen</button></form>
<form method="post" action="/assist/suggestions/<?= rawurlencode($s['id']) ?>/reject"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><button class="danger" type="submit">Verwerfen</button></form>
</div><?php endif;?>
<?php if($editable&&$s['status']==='CONFIRMED'&&$s['suggestion_type']==='LICENSE_PLATE'):?><form method="post" action="/assist/suggestions/<?= rawurlencode($s['id']) ?>/apply-plate"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><button type="submit">Bestätigtes Kennzeichen übernehmen</button></form><?php endif;?>
<?php if($editable&&$s['status']==='CONFIRMED'&&$s['suggestion_type']==='OFFENSE'):?><form method="post" action="/assist/suggestions/<?= rawurlencode($s['id']) ?>/apply-offense"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><button type="submit">Bestätigten Tatbestand übernehmen</button></form><?php endif;?>
</article><?php endforeach;?></div>
<?php if($editable&&$provider['enabled']):?><p class="muted">Tatbestandsvorschläge werden erst erzeugt, nachdem Schild-/Zusatzzeichen-Vorschläge bestätigt wurden.</p><?php endif;?>
</section>

<section class="card"><h2>Analyseprotokoll</h2><?php foreach($overview['runs'] as $run):?><p><strong><?= $e($run['purpose']) ?></strong> · <?= $e($run['provider']) ?> · <?= $e($run['status']) ?> · <?= $e($run['created_at']) ?></p><?php endforeach;?><?php if(!$overview['runs']):?><p class="muted">Noch keine externen Analyse-Läufe.</p><?php endif;?></section>
</main></body></html>