<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$c=$summary['case_data']['case'];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><title>Finaler Review – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1000px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.message,.error{padding:12px;border-radius:9px;margin-bottom:14px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}.check{padding:12px;border-radius:9px;margin:8px 0}.red{background:#fff3f3;border:1px solid #d6a0a0}.yellow{background:#fffaf0;border:1px solid #d6b36b}.green{background:#f2fbf4;border:1px solid #8fb799}.dot{display:inline-block;width:11px;height:11px;border-radius:50%;margin-right:8px;background:currentColor}.red{color:#8b1e1e}.yellow{color:#8a5a0a}.green{color:#24683b}.muted{color:#66758a}button,.button{display:inline-block;padding:11px 15px;border:0;border-radius:9px;background:#172033;color:#fff;text-decoration:none;font-weight:700}.hash{font-family:ui-monospace,monospace;font-size:.82rem;word-break:break-all}
</style></head><body><main>
<p><a href="/cases/<?= rawurlencode($c['id']) ?>/witness">← Sachverhalt & Zeugenbericht</a></p>
<h1>Finaler Qualitätsreview</h1>
<p class="muted">Vorgang <?= $e($c['public_number']) ?>. Die Ampel bewertet Vollständigkeit und technische Konsistenz – nicht die behördliche Entscheidung.</p>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>

<section class="card"><h2>Rot – blockierend</h2>
<?php if(!$summary['red']):?><div class="green"><span class="dot"></span>Keine blockierenden Punkte.</div><?php endif;?>
<?php foreach($summary['red'] as $item):?><div class="check red"><span class="dot"></span><?= $e($item) ?></div><?php endforeach;?>
</section>

<section class="card"><h2>Gelb – prüfen und bestätigen</h2>
<?php if(!$summary['yellow']):?><div class="green"><span class="dot"></span>Keine offenen Hinweise.</div><?php endif;?>
<?php foreach($summary['yellow'] as $item):?><div class="check yellow"><span class="dot"></span><?= $e($item) ?></div><?php endforeach;?>
</section>

<section class="card"><h2>Grün – vollständig</h2>
<?php foreach($summary['green'] as $item):?><div class="check green"><span class="dot"></span><?= $e($item) ?></div><?php endforeach;?>
</section>

<?php if($summary['package']):?><section class="card"><h2>Beweismappe</h2><p>Version <?= $e($summary['package']['version_no']) ?> · eingefroren <?= $e($summary['package']['frozen_at']) ?></p><p class="hash">Manifest SHA‑256: <?= $e($summary['package']['manifest_sha256']) ?></p></section><?php endif;?>
<?php if($summary['report']):?><section class="card"><h2>Zeugenbericht</h2><p>Version <?= $e($summary['report']['version_no']) ?> · bestätigt <?= $e($summary['report']['confirmed_at']) ?></p><p class="hash">Snapshot SHA‑256: <?= $e($summary['report']['snapshot_sha256']) ?></p></section><?php endif;?>

<section class="card"><h2>Versandbereitschaft</h2>
<?php if($summary['completed']):?><div class="green"><span class="dot"></span><strong>Abgeschlossen.</strong> Der Vorgang ist als versandbereit markiert.</div><?php if($summary['latest_quality_review']):?><p class="muted">Qualitätsreview Version <?= $e($summary['latest_quality_review']['version_no']) ?> · bestätigt <?= $e($summary['latest_quality_review']['confirmed_at']) ?></p><?php endif;?>
<?php elseif($summary['ready']):?><form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/final-review"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><?php if($summary['yellow']):?><label style="font-weight:500"><input type="checkbox" name="acknowledge_yellow" value="1" required style="width:auto"> Ich habe die gelben Hinweise geprüft und möchte den Vorgang mit diesem Stand als versandbereit markieren.</label><br><?php else:?><input type="hidden" name="acknowledge_yellow" value="0"><?php endif;?><button type="submit">Finalen Review bestätigen</button></form>
<?php else:?><p>Der Vorgang kann erst als versandbereit markiert werden, wenn alle roten Punkte behoben sind.</p><?php endif;?>
</section>
</main></body></html>
