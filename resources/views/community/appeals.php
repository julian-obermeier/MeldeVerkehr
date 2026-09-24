<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$actionLabels=['HIDE'=>'Inhalt ausgeblendet','WARN'=>'Warnung','RESTRICT'=>'Community-Nutzung eingeschränkt'];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Moderationsentscheidungen – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:900px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:20px;margin-bottom:14px}.item{border-top:1px solid #e5e9ee;padding:16px 0}.item:first-child{border-top:0}.muted{color:#66758a}.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf1f5;font-size:.85rem}.message,.error{padding:10px;border-radius:8px;margin-bottom:12px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}textarea{width:100%;box-sizing:border-box;min-height:100px;padding:10px;border:1px solid #bac5d1;border-radius:8px;font:inherit}button{padding:9px 13px;border:0;border-radius:8px;background:#172033;color:#fff;font-weight:700}
</style></head><body><main>
<p><a href="/community">← Community</a></p><h1>Moderationsentscheidungen & Einsprüche</h1>
<p class="muted">Hier erscheinen Moderationsentscheidungen zu deinen Community-Inhalten. Gegen ausgeblendete Inhalte, Warnungen und Einschränkungen kann einmal begründet Einspruch eingelegt werden.</p>
<?php if($message):?><div class="message" role="status"><?= $e($message) ?></div><?php endif;?>
<?php if($error):?><div class="error" role="alert"><?= $e($error) ?></div><?php endif;?>
<section class="card">
<?php if(!$items):?><p class="muted">Keine anfechtbaren Moderationsentscheidungen vorhanden.</p><?php endif;?>
<?php foreach($items as $row):?><div class="item">
<strong><?= $e($actionLabels[$row['action_type']]??$row['action_type']) ?></strong>
<span class="tag"><?= $e($row['target_type']) ?></span>
<p class="muted">Entscheidung: <?= $e($row['resolution']??'') ?></p>
<?php if(!empty($row['appeal_id'])):?>
<p><strong>Einspruch:</strong> <?= $e($row['appeal_status']) ?></p>
<p><?= nl2br($e($row['appeal_reason']??'')) ?></p>
<?php if(!empty($row['appeal_resolution'])):?><p class="muted">Entscheidung zum Einspruch: <?= nl2br($e($row['appeal_resolution'])) ?></p><?php endif;?>
<?php else:?>
<form method="post" action="/community/appeals/<?= rawurlencode($row['id']) ?>">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label for="appeal-<?= $e($row['id']) ?>"><strong>Begründung des Einspruchs</strong></label>
<textarea id="appeal-<?= $e($row['id']) ?>" name="reason" minlength="10" maxlength="4000" required></textarea>
<button type="submit">Einspruch einreichen</button>
</form>
<?php endif;?>
</div><?php endforeach;?>
</section>
</main></body></html>