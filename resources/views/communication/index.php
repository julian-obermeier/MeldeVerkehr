<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$c=$data['case_data']['case'];
$labels=[
 'RECEIPT'=>'Eingangsbestätigung','INQUIRY'=>'Rückfrage','DEADLINE'=>'Frist',
 'DEMAND'=>'Nachforderung','REJECTION'=>'Ablehnung/Einstellung','CLOSURE'=>'Abschluss',
 'OTHER'=>'Sonstiges','OUTBOUND_REPLY'=>'Antwort'
];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><title>Kommunikation – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1100px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.message,.error,.warn,.ok{padding:12px;border-radius:9px;margin:9px 0}.message,.ok{border:1px solid #8fb799}.error{border:1px solid #c99;background:#fff7f7}.warn{border:1px solid #d6b36b;background:#fffaf0}.muted{color:#66758a}.timeline{display:grid;gap:14px}.mail{border:1px solid #dfe5ec;border-radius:12px;padding:16px}.inbound{border-left:5px solid #8a5a0a}.outbound{border-left:5px solid #24683b}.body{white-space:pre-wrap;background:#f8fafc;border-radius:9px;padding:12px}.hash{font-family:ui-monospace,monospace;font-size:.8rem;word-break:break-all}button,.button{display:inline-block;padding:10px 14px;border:0;border-radius:9px;background:#172033;color:#fff;text-decoration:none;font-weight:700;margin-top:8px}.secondary{background:#fff;color:#172033;border:1px solid #9aa8b7}textarea{width:100%;min-height:180px;box-sizing:border-box;padding:10px;border:1px solid #bac5d1;border-radius:8px;font:inherit}.task{border:1px solid #e1e7ed;border-radius:10px;padding:12px}.open{border-left:5px solid #b06a00}.done{border-left:5px solid #2e7d45}label{display:block;margin:8px 0}</style></head>
<body><main>
<p><a href="/cases/<?= rawurlencode($c['id']) ?>">← Zurück zum Vorgang</a></p>
<h1>Behördenkommunikation</h1>
<p class="muted">Vorgang <?= $e($c['public_number']) ?></p>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>
<?php if(!$inboundEnabled):?><div class="warn"><strong>IMAP-Eingang ist deaktiviert.</strong> Eingehende Behördenmails werden erst nach bewusster Konfiguration und Cron-Aktivierung automatisch eingelesen.</div><?php endif;?>
<?php if(strtolower($transportMode)!=='smtp'&&strtolower($transportMode)!=='php_mail'):?><div class="warn"><strong>Testmodus:</strong> Antworten werden über <?= $e($transportMode) ?> verarbeitet und nicht real versendet.</div><?php endif;?>

<section class="card"><h2>Offene Aufgaben</h2><div class="grid">
<?php $openTasks=array_filter($data['tasks'],static fn(array $t):bool=>$t['status']==='OPEN');?>
<?php if(!$openTasks):?><p class="muted">Keine offenen Aufgaben.</p><?php endif;?>
<?php foreach($data['tasks'] as $task):?><div class="task <?= $task['status']==='OPEN'?'open':'done' ?>"><strong><?= $e($task['title']) ?></strong><p class="muted"><?= $e($task['task_type']) ?><?php if($task['due_at']):?> · fällig <?= $e($task['due_at']) ?> UTC<?php endif;?></p><?php if($task['status']==='OPEN'):?><form method="post" action="/communication-tasks/<?= rawurlencode($task['id']) ?>/complete"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><button type="submit" class="secondary">Als erledigt markieren</button></form><?php else:?><span class="muted">Erledigt <?= $e($task['completed_at']) ?></span><?php endif;?></div><?php endforeach;?>
</div></section>

<section class="card"><h2>Fristen</h2><div class="grid">
<?php if(!$data['deadlines']):?><p class="muted">Keine erkannten Fristen.</p><?php endif;?>
<?php foreach($data['deadlines'] as $deadline):?><div class="task <?= $deadline['status']==='OPEN'?'open':'done' ?>"><strong><?= $e($deadline['deadline_type']) ?></strong><p>Fällig: <?= $e($deadline['due_at']) ?> UTC</p><?php if($deadline['status']==='OPEN'):?><form method="post" action="/communication-deadlines/<?= rawurlencode($deadline['id']) ?>/resolve"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><button type="submit" class="secondary">Frist als erledigt markieren</button></form><?php else:?><span class="muted">Erledigt <?= $e($deadline['resolved_at']) ?></span><?php endif;?></div><?php endforeach;?>
</div></section>

<section class="card"><h2>Verlauf</h2><div class="timeline">
<?php if(!$data['messages']):?><p class="muted">Noch keine Behördenkommunikation eingelesen.</p><?php endif;?>
<?php foreach($data['messages'] as $mail):?>
<article class="mail <?= $mail['direction']==='INBOUND'?'inbound':'outbound' ?>">
<div class="grid"><div><strong><?= $mail['direction']==='INBOUND'?'Eingang':'Ausgang' ?></strong><br><?= $e($labels[$mail['classification']]??$mail['classification']) ?></div><div><strong>Von</strong><br><?= $e($mail['sender']) ?></div><div><strong>An</strong><br><?= $e($mail['recipient']) ?></div><div><strong>Zeit</strong><br><?= $e($mail['received_at']??$mail['sent_at']??$mail['created_at']) ?></div></div>
<h3><?= $e($mail['subject']!==''?$mail['subject']:'(ohne Betreff)') ?></h3>
<div class="body"><?= $e($mail['body_text']) ?></div>
<p class="hash">Body SHA-256: <?= $e($mail['body_sha256']) ?></p>
<?php if($mail['attachments']):?><p><strong>Anhänge:</strong></p><ul><?php foreach($mail['attachments'] as $attachment):?><li><a href="/communication-attachments/<?= rawurlencode($attachment['id']) ?>"><?= $e($attachment['original_filename']) ?></a> · <?= $e($attachment['mime_type']) ?> · <?= $e(round(((int)$attachment['file_size'])/1024,1)) ?> KB</li><?php endforeach;?></ul><?php endif;?>

<?php if($mail['direction']==='INBOUND'):?>
<?php $draft=$mail['reply_draft'];?>
<?php if(!$draft):?><form method="post" action="/authority-messages/<?= rawurlencode($mail['id']) ?>/reply-draft"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><button type="submit">Antwortentwurf erstellen</button></form>
<?php else:?><div class="card" style="margin-top:12px"><h4>Antwortentwurf Version <?= $e($draft['version_no']) ?> · <?= $e($draft['status']) ?></h4>
<?php if($draft['status']==='DRAFT'):?><form method="post" action="/authority-reply-drafts/<?= rawurlencode($draft['id']) ?>/save"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><textarea name="body" maxlength="50000" required><?= $e($draft['body']) ?></textarea><button type="submit" class="secondary">Als neue Version speichern</button></form>
<form method="post" action="/authority-reply-drafts/<?= rawurlencode($draft['id']) ?>/queue"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="case_id" value="<?= $e($c['id']) ?>"><label><input type="checkbox" name="confirm_send" value="1" required> Ich habe den Antworttext vollständig geprüft und bestätige den Versand.</label><button type="submit">Antwort in Versandqueue einstellen</button></form>
<?php else:?><div class="<?= $draft['status']==='SENT'?'ok':'warn' ?>">Status: <?= $e($draft['status']) ?><?php if($draft['sent_at']):?> · gesendet <?= $e($draft['sent_at']) ?><?php endif;?><?php if($draft['last_error']):?><br><?= $e($draft['last_error']) ?><?php endif;?></div><?php endif;?>
</div><?php endif;?>
<?php endif;?>
</article>
<?php endforeach;?>
</div></section>
</main></body></html>
