<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reputationsverwaltung – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1100px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:20px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.item{border-top:1px solid #e5e9ee;padding:14px 0}.item:first-child{border-top:0}.muted{color:#66758a}.message,.error{padding:10px;border-radius:8px;margin-bottom:12px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}input,select,textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #bac5d1;border-radius:8px;font:inherit}textarea{min-height:80px}button{padding:9px 13px;border:0;border-radius:8px;background:#172033;color:#fff;font-weight:700;margin-top:8px}.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf1f5;font-size:.82rem}.risk{font-weight:800}
</style></head><body><main>
<p><a href="/community/moderation">← Moderation</a></p><h1>Reputationsverwaltung</h1>
<p class="muted">Korrekturen werden als eigene Reputationsereignisse protokolliert. Bestehende Events werden nicht nachträglich verändert.</p>
<?php if($message):?><div class="message" role="status"><?= $e($message) ?></div><?php endif;?>
<?php if($error):?><div class="error" role="alert"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Admin-Korrektur</h2>
<form method="post" action="/community/moderation/reputation/corrections">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<div class="grid"><div><label>Community-Benutzername</label><input name="username" required></div>
<div><label>Kategorie</label><select name="category"><option>COMMUNITY</option><option>REPORT_QUALITY</option><option>PROBLEM_REPORT</option><option>AUTHORITY_INFO</option></select></div>
<div><label>Punkte</label><input type="number" name="points" min="-500" max="500" required></div></div>
<label>Begründung</label><textarea name="reason" minlength="5" maxlength="1000" required></textarea>
<button type="submit">Korrektur buchen</button></form></section>

<section class="card"><h2>Aktive Regeln</h2><div class="grid">
<?php foreach($policies as $p):?><div><strong><?= $e($p['category']) ?></strong><p class="muted">Tagesmaximum: <?= $e($p['daily_positive_cap']) ?> · Voll: <?= $e($p['full_rate_events']) ?> Events · reduziert bis <?= $e($p['reduced_rate_events']) ?> · Faktor <?= $e($p['reduced_multiplier']) ?> / <?= $e($p['tail_multiplier']) ?></p></div><?php endforeach;?>
</div></section>

<section class="card"><h2>Offene Anomalien</h2>
<?php if(!$anomalies):?><p class="muted">Keine offenen Reputationsanomalien.</p><?php endif;?>
<?php foreach($anomalies as $a):?><div class="item">
<strong>@<?= $e($a['username']??'') ?></strong> · <span class="tag"><?= $e($a['anomaly_type']) ?></span> · <?= $e($a['severity']) ?> · <span class="risk"><?= $e($a['risk_score']) ?>/100</span>
<?php if(!empty($a['evidence'])):?><p class="muted"><?php foreach($a['evidence'] as $key=>$value):?><?= $e($key) ?>: <?= $e(is_scalar($value)?$value:json_encode($value)) ?> · <?php endforeach;?></p><?php endif;?>
<form method="post" action="/community/moderation/reputation/anomalies/<?= rawurlencode($a['id']) ?>/resolve"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><textarea name="resolution" minlength="5" maxlength="1000" required placeholder="Prüfergebnis"></textarea><button type="submit">Anomalie abschließen</button></form>
</div><?php endforeach;?></section>

<section class="card"><h2>Letzte Admin-Korrekturen</h2>
<?php if(!$corrections):?><p class="muted">Noch keine Korrekturen.</p><?php endif;?>
<?php foreach($corrections as $row):?><div class="item"><strong>@<?= $e($row['username']??'') ?></strong> · <?= $e($row['category']) ?> · <?= ((int)$row['points']>0?'+':'') . $e($row['points']) ?> Punkte<p><?= $e($row['reason']) ?></p><p class="muted"><?= $e($row['created_at']) ?> · Admin: <?= $e($row['admin_email']) ?></p></div><?php endforeach;?>
</section>
</main></body></html>