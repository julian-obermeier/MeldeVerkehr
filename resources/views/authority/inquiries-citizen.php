<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$c=$data['case'];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Behördenanfragen – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:950px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:20px;margin-bottom:14px}.message,.error{padding:11px;border-radius:8px;margin-bottom:12px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}.muted{color:#66758a}.status{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf1f5}.response{border-left:4px solid #66758a;padding-left:12px;margin:12px 0}textarea{width:100%;box-sizing:border-box;min-height:140px;padding:10px;border:1px solid #bac5d1;border-radius:8px;font:inherit}button{padding:10px 14px;border:0;border-radius:9px;background:#172033;color:#fff;font-weight:700}</style></head><body><main>
<p><a href="/cases/<?= rawurlencode($c['id']) ?>">← Zum Vorgang</a></p>
<h1>Behördenanfragen</h1><p class="muted">Vorgang <?= $e($c['public_number']) ?></p>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>
<?php if(!$data['inquiries']):?><section class="card"><p class="muted">Aktuell liegen keine strukturierten Behördenanfragen vor.</p></section><?php endif;?>
<?php foreach($data['inquiries'] as $inq):?><section class="card">
<h2><?= $e($inq['subject']) ?></h2>
<p><span class="status"><?= $e($inq['status']) ?></span> · <?= $e($inq['authority_name']) ?> · <?= $e($inq['inquiry_type']) ?></p>
<p><?= nl2br($e($inq['body'])) ?></p>
<?php if($inq['due_at']):?><p><strong>Frist:</strong> <?= $e($inq['due_at']) ?> UTC</p><?php endif;?>
<?php foreach($inq['responses'] as $response):?><div class="response"><strong>Antwort Version <?= $e($response['version_no']) ?> · <?= $e($response['status']) ?></strong><p><?= nl2br($e($response['body'])) ?></p><?php if($response['review_note']!==''):?><p><strong>Prüfhinweis:</strong> <?= nl2br($e($response['review_note'])) ?></p><?php endif;?><small class="muted"><?= $e($response['submitted_at']) ?></small></div><?php endforeach;?>
<?php if(in_array($inq['status'],['OPEN','REVISION_REQUIRED'],true)):?><form method="post" action="/authority-inquiries/<?= rawurlencode($inq['id']) ?>/responses"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><label><strong><?= $inq['status']==='REVISION_REQUIRED'?'Überarbeitete Antwort':'Antwort an die Behörde' ?></strong></label><textarea name="body" maxlength="50000" required></textarea><button type="submit">Antwort verbindlich einreichen</button></form><?php elseif($inq['status']==='AWAITING_REVIEW'):?><p class="muted">Deine Antwort wurde eingereicht und wartet auf die Prüfung der Behörde.</p><?php elseif($inq['status']==='CLOSED'):?><p class="muted">Diese Anfrage ist abgeschlossen.</p><?php endif;?>
</section><?php endforeach;?>
</main></body></html>
