<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$data=$detail['case_data'];
$c=$data['case'];
$o=$detail['observation'];
$n=$detail['narrative'];
$r=$detail['report'];
$package=$data['evidence_package']??null;
$reportConfirmed=is_array($r)&&$r['confirmed_at']!==null&&($r['current']??false)===true&&is_array($r['declaration']??null);
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><title>Sachverhalt & Zeugenbericht – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1050px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.message,.error,.ok,.warn{padding:12px;border-radius:9px;margin:10px 0}.message,.ok{border:1px solid #8fb799}.error{border:1px solid #c99;background:#fff7f7}.warn{border:1px solid #d6b36b;background:#fffaf0}label{display:block;font-weight:650;margin:10px 0 5px}textarea{width:100%;box-sizing:border-box;min-height:120px;padding:11px;border:1px solid #bac5d1;border-radius:8px;font:inherit}button,.button{display:inline-block;padding:10px 14px;border:0;border-radius:9px;background:#172033;color:#fff;text-decoration:none;font-weight:700;margin-top:10px}.secondary{background:#fff;color:#172033;border:1px solid #9aa8b7}.muted{color:#66758a}.hash{font-family:ui-monospace,monospace;font-size:.82rem;word-break:break-all}.step{font-weight:800}.done{color:#24683b}.todo{color:#8a5a0a}.snapshot{white-space:pre-wrap;background:#f8fafc;border:1px solid #e1e7ed;border-radius:10px;padding:14px}
</style></head><body><main>
<p><a href="/cases/<?= rawurlencode($c['id']) ?>">← Zurück zum Vorgang</a></p>
<h1>Sachverhalt & Zeugenbericht</h1>
<p class="muted">Vorgang <?= $e($c['public_number']) ?> · Beweismappe Version <?= $e($package['version_no']??'–') ?></p>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Fortschritt</h2><div class="grid">
<div class="step <?= $o?'done':'todo' ?>">1. Eigene Beobachtung <?= $o?'✓':'' ?></div>
<div class="step <?= $n?'done':'todo' ?>">2. Sachverhaltstext <?= $n?'✓':'' ?></div>
<div class="step <?= $r?'done':'todo' ?>">3. Zeugenbericht <?= $r?'✓':'' ?></div>
<div class="step <?= $reportConfirmed?'done':'todo' ?>">4. Erklärung bestätigt <?= $reportConfirmed?'✓':'' ?></div>
</div></section>

<section class="card"><h2>1. Eigene Beobachtung</h2>
<p class="muted">Beschreibe nur, was du selbst wahrgenommen hast. Vermutungen oder Aussagen anderer Personen sollten nicht als eigene Beobachtung formuliert werden.</p>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/witness/observation"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Eigene Beobachtung</label><textarea name="observation_text" maxlength="10000" required><?= $e($o['observation_text']??'') ?></textarea>
<label>Beobachtete Auswirkung optional</label><textarea name="impact_text" maxlength="10000"><?= $e($o['impact_text']??'') ?></textarea>
<label>Ergänzender Kontext optional</label><textarea name="context_text" maxlength="10000"><?= $e($o['context_text']??'') ?></textarea>
<button type="submit"><?= $o?'Neue Version speichern':'Beobachtung speichern' ?></button>
<?php if($o):?><p class="muted">Aktuelle Version: <?= $e($o['version_no']) ?> · gespeichert <?= $e($o['created_at']) ?></p><?php endif;?>
</form></section>

<section class="card"><h2>2. Neutraler Sachverhaltstext</h2>
<?php if(!$o):?><p>Speichere zuerst deine eigene Beobachtung.</p>
<?php else:?>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/witness/narrative/generate"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit" class="secondary">Neutralen Text aus bestätigten Daten erzeugen</button></form>
<?php if($n):?>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/witness/narrative"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><label>Bearbeitbarer Sachverhalt</label><textarea name="narrative_text" maxlength="15000" required><?= $e($n['final_text']) ?></textarea><button type="submit">Bearbeiteten Text als neue Version speichern</button></form>
<p class="muted">Aktuelle Narrative-Version <?= $e($n['version_no']) ?> · Generator <?= $e($n['generator_version']) ?></p>
<?php endif;?>
<?php endif;?></section>

<section class="card"><h2>3. Zeugenbericht-Snapshot</h2>
<?php if(!$o||!$n):?><p>Beobachtung und Sachverhalt müssen vollständig sein.</p>
<?php else:?><form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/witness/report"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">Zeugenbericht aus aktuellem Aktenstand erzeugen</button></form><?php endif;?>

<?php if($r):?>
<?php if(!($r['current']??false)):?><div class="warn">Dieser Bericht gehört nicht mehr zum aktuellen Beobachtungs-/Sachverhaltsstand. Er muss neu erzeugt werden.</div><?php endif;?>
<p><strong>Version <?= $e($r['version_no']) ?></strong> · erstellt <?= $e($r['created_at']) ?></p>
<p class="hash">Snapshot SHA‑256: <?= $e($r['snapshot_sha256']) ?></p>
<?php $snap=$r['snapshot']??[];?>
<div class="snapshot"><?= $e($snap['narrative']['text']??'') ?></div>
<p class="muted">Beweismappe im Snapshot: Version <?= $e($snap['evidence_package']['version_no']??'–') ?> · Manifest <?= $e($snap['evidence_package']['manifest_sha256']??'–') ?></p>
<?php endif;?>
</section>

<?php if($r&&($r['current']??false)):?><section class="card"><h2>4. Elektronische Erklärung</h2>
<?php if($reportConfirmed):?><div class="ok"><strong>Bestätigt.</strong> <?= $e($r['confirmed_at']) ?></div><p><?= $e($r['declaration']['declaration_text']??$declarationText) ?></p><p><a class="button" href="/cases/<?= rawurlencode($c['id']) ?>/final-review">Finalen Qualitätsreview öffnen</a></p>
<?php else:?><form method="post" action="/witness-reports/<?= rawurlencode($r['id']) ?>/confirm"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><p><?= $e($declarationText) ?></p><label style="font-weight:500"><input type="checkbox" name="declaration_accepted" value="1" required style="width:auto"> Ich bestätige diese Erklärung und den oben dargestellten Bericht.</label><br><button type="submit">Zeugenbericht elektronisch bestätigen</button></form><?php endif;?>
</section><?php endif;?>
</main></body></html>
