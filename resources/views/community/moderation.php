<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Moderation – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1150px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:20px;margin-bottom:14px}.item{border-top:1px solid #e5e9ee;padding:14px 0}.item:first-child{border-top:0}.muted{color:#66758a}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}button{padding:8px 12px;border:0;border-radius:8px;background:#172033;color:white;font-weight:700;cursor:pointer}select,input,textarea{padding:8px;border:1px solid #bac5d1;border-radius:8px;font:inherit;box-sizing:border-box}textarea{width:100%;min-height:75px}.message,.error{padding:10px;border-radius:8px;margin-bottom:12px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf1f5;font-size:.82rem}.risk-high{font-weight:800;color:#9d2733}.risk-mid{font-weight:800;color:#8a5a0a}.rowform{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.rowform>*{max-width:100%}h2{margin-bottom:6px}
</style></head><body><main>
<p><a href="/community">← Community</a></p><h1>Moderation & Safety</h1>
<p class="muted">Meldungen, Einsprüche, Abuse-Signale und Eskalationen werden getrennt behandelt. Automatische Risikosignale treffen keine endgültige Moderationsentscheidung.</p>
<?php if($message):?><div class="message" role="status"><?= $e($message) ?></div><?php endif;?>
<?php if($error):?><div class="error" role="alert"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Neue öffentliche Problemstellen</h2>
<?php if(!$pendingProblems):?><p class="muted">Keine offenen Freigaben.</p><?php endif;?>
<?php foreach($pendingProblems as $p):?><div class="item"><strong><?= $e($p['name']) ?></strong> · @<?= $e($p['username']??'') ?> · <?= $e($p['city']??'') ?>
<form method="post" action="/community/moderation/problems/<?= rawurlencode($p['id']) ?>/approve"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">Freigeben</button></form></div><?php endforeach;?></section>

<section class="card"><h2>Gemeldete Inhalte</h2>
<?php if(!$reports):?><p class="muted">Keine offenen Meldungen.</p><?php endif;?>
<?php foreach($reports as $r):?><div class="item">
<div><strong><?= $e($r['target_type']) ?></strong> · <?= $e($r['category']) ?> · gemeldet von @<?= $e($r['reporter_username']??'') ?>
<?php $risk=(int)($r['reporter_risk_score']??0); if($risk>0):?><span class="<?= $risk>=70?'risk-high':'risk-mid' ?>">Reporter-Risiko <?= $e($risk) ?>/100</span><?php endif;?></div>
<p><?= $e($r['reason']??'') ?></p>
<form method="post" action="/community/moderation/reports/<?= rawurlencode($r['id']) ?>/resolve" class="rowform">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<select name="action"><option>DISMISS</option><option>HIDE</option><option>WARN</option><option>RESTRICT</option></select>
<input name="reason" placeholder="Begründung"><button type="submit">Abschließen</button></form>
<form method="post" action="/community/moderation/escalations" class="rowform">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="source_type" value="REPORT"><input type="hidden" name="source_id" value="<?= $e($r['id']) ?>">
<select name="priority"><option>NORMAL</option><option>HIGH</option><option>URGENT</option></select><input name="reason" required placeholder="Eskalationsgrund"><button type="submit">Eskalieren</button></form>
</div><?php endforeach;?></section>

<section class="card"><h2>Einsprüche</h2>
<?php if(!$appeals):?><p class="muted">Keine offenen Einsprüche.</p><?php endif;?>
<?php foreach($appeals as $a):?><div class="item">
<strong>@<?= $e($a['appellant_username']??'') ?></strong> · <?= $e($a['target_type']) ?> · ursprüngliche Entscheidung: <?= $e($a['report_resolution']??'') ?>
<p><?= nl2br($e($a['reason'])) ?></p>
<form method="post" action="/community/moderation/appeals/<?= rawurlencode($a['id']) ?>/resolve">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<div class="grid"><div><label>Ergebnis</label><select name="outcome"><option value="UPHOLD">Bestätigen</option><option value="OVERTURN">Aufheben</option><option value="PARTIAL">Teilweise ändern</option></select></div><div><label>Begründung</label><textarea name="reason" minlength="5" maxlength="4000" required></textarea></div></div>
<button type="submit">Einspruch entscheiden</button></form>
<form method="post" action="/community/moderation/escalations" class="rowform">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="source_type" value="APPEAL"><input type="hidden" name="source_id" value="<?= $e($a['id']) ?>">
<select name="priority"><option>NORMAL</option><option>HIGH</option><option>URGENT</option></select><input name="reason" required placeholder="Eskalationsgrund"><button type="submit">Eskalieren</button></form>
</div><?php endforeach;?></section>

<section class="card"><h2>Abuse-/Anti-Spam-Signale</h2>
<p class="muted">Diese Hinweise entstehen aus Aktivitätsmustern wie Mass-Reports, Wiederholungsinhalten oder ungewöhnlicher Geschwindigkeit. Sie sind nur Prüfsignale.</p>
<?php if(!$abuseFlags):?><p class="muted">Keine offenen Abuse-Signale.</p><?php endif;?>
<?php foreach($abuseFlags as $f):?><div class="item">
<strong>@<?= $e($f['username']??'') ?></strong> · <span class="tag"><?= $e($f['signal_type']) ?></span> · <?= $e($f['severity']) ?> · Risiko <?= $e($f['risk_score']) ?>/100
<?php if(!empty($f['evidence'])):?><p class="muted"><?php foreach($f['evidence'] as $key=>$value):?><?= $e($key) ?>: <?= $e(is_scalar($value)?$value:json_encode($value)) ?> · <?php endforeach;?></p><?php endif;?>
<form method="post" action="/community/moderation/abuse/<?= rawurlencode($f['id']) ?>/resolve" class="rowform">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<select name="action"><option>DISMISS</option><option>WARN</option><option>RESTRICT_REPORTING</option><option>RESTRICT_COMMUNITY</option></select>
<input name="reason" minlength="5" required placeholder="Begründung"><button type="submit">Signal bearbeiten</button></form>
<form method="post" action="/community/moderation/escalations" class="rowform">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="source_type" value="ABUSE_FLAG"><input type="hidden" name="source_id" value="<?= $e($f['id']) ?>">
<select name="priority"><option>NORMAL</option><option>HIGH</option><option>URGENT</option></select><input name="reason" required placeholder="Eskalationsgrund"><button type="submit">Eskalieren</button></form>
</div><?php endforeach;?></section>

<section class="card"><h2>Eskalationen</h2>
<?php if(!$escalations):?><p class="muted">Keine offenen Eskalationen.</p><?php endif;?>
<?php foreach($escalations as $x):?><div class="item"><strong><?= $e($x['priority']) ?></strong> · <?= $e($x['source_type']) ?> · von @<?= $e($x['creator_username']??'') ?><p><?= nl2br($e($x['reason'])) ?></p>
<?php if($canResolveEscalations):?><form method="post" action="/community/moderation/escalations/<?= rawurlencode($x['id']) ?>/resolve"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><textarea name="resolution" minlength="5" maxlength="4000" required placeholder="Entscheidung / Übergabe / Abschluss"></textarea><button type="submit">Eskalation abschließen</button></form><?php else:?><p class="muted">Die endgültige Eskalationsentscheidung erfolgt durch eine übergeordnete Moderationsrolle.</p><?php endif;?>
</div><?php endforeach;?></section>
</main></body></html>