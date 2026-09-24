<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$route=$review['route']??null;
$selected=is_array($route)?($route['selected']??null):null;
$requirements=$review['requirements']??null;
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><title>Versand – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1000px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.message,.error,.warn,.ok{padding:12px;border-radius:9px;margin:9px 0}.message,.ok{border:1px solid #8fb799}.error{border:1px solid #c99;background:#fff7f7}.warn{border:1px solid #d6b36b;background:#fffaf0}.muted{color:#66758a}button,.button{display:inline-block;padding:11px 15px;border:0;border-radius:9px;background:#172033;color:#fff;text-decoration:none;font-weight:700}.hash{font-family:ui-monospace,monospace;font-size:.82rem;word-break:break-all}.candidate{border:1px solid #e1e7ed;border-radius:10px;padding:12px;margin:8px 0}</style></head><body><main>
<p><a href="/cases/<?= rawurlencode($caseId) ?>">← Zurück zum Vorgang</a></p><h1>Behördenrouting & Versand</h1>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>
<?php if(strtolower($transportMode)!=='php_mail'):?><div class="warn"><strong>Testmodus:</strong> DISPATCH_TRANSPORT ist <?= $e($transportMode) ?>. Es wird keine echte Nachricht an eine Behörde gesendet.</div><?php endif;?>

<?php if($latest):?><section class="card"><h2>Letzter Versandauftrag</h2><p><strong><?= $e($latest['status']) ?></strong> · <?= $e($latest['authority_name']) ?> · <?= $e($latest['channel']) ?></p><p class="muted">Erstellt <?= $e($latest['created_at']) ?><?php if($latest['sent_at']):?> · Transport angenommen <?= $e($latest['sent_at']) ?><?php endif;?></p><?php if($latest['last_error']):?><div class="error"><?= $e($latest['last_error']) ?></div><?php endif;?></section><?php endif;?>

<?php if($review):?>
<section class="card"><h2>Zuständigkeit</h2>
<p>Status: <strong><?= $e($route['status']) ?></strong></p>
<?php if($selected):?><div class="grid"><div><strong>Behörde</strong><br><?= $e($selected['authority_name']) ?></div><div><strong>Kanal</strong><br><?= $e($selected['channel']) ?></div><div><strong>Endpunkt</strong><br><?= $e($selected['endpoint_value']) ?></div><div><strong>Routing-Sicherheit</strong><br><?= $e($selected['certainty']) ?></div></div><p class="muted">Treffer über: <?= $e(implode(', ',$selected['matched_on'])) ?> · Routing-Score <?= $e($selected['score']) ?></p>
<?php elseif($route['candidates']):?><p>Mehrere gleichwertige Kandidaten:</p><?php foreach($route['candidates'] as $candidate):?><div class="candidate"><?= $e($candidate['authority_name']) ?> · <?= $e($candidate['channel']) ?> · <?= $e($candidate['certainty']) ?></div><?php endforeach;?>
<?php else:?><p>Keine zuständige Behörde im aktuellen Verzeichnis gefunden.</p><?php endif;?>
</section>

<?php if($requirements):?><section class="card"><h2>Behördenanforderungen</h2><p>Profil Version <?= $e($requirements['version_no']) ?></p><p>Pflichtfelder: <?= $e(implode(', ',$requirements['required_fields'])) ?></p><p>Akzeptierte Dateitypen: <?= $e(implode(', ',$requirements['accepted_mime'])) ?></p><?php if($requirements['max_attachment_bytes']):?><p>Max. Einzelanlage: <?= $e(round(((int)$requirements['max_attachment_bytes'])/1024/1024,2)) ?> MB</p><?php endif;?><?php if($requirements['max_total_bytes']):?><p>Max. Gesamtanlagen: <?= $e(round(((int)$requirements['max_total_bytes'])/1024/1024,2)) ?> MB</p><?php endif;?></section><?php endif;?>

<?php if($review['errors']):?><section class="card"><h2>Blockierende Punkte</h2><?php foreach($review['errors'] as $item):?><div class="error"><?= $e($item) ?></div><?php endforeach;?></section><?php endif;?>
<?php if($review['warnings']):?><section class="card"><h2>Hinweise</h2><?php foreach($review['warnings'] as $item):?><div class="warn"><?= $e($item) ?></div><?php endforeach;?></section><?php endif;?>

<?php if($review['ready']):?><section class="card"><h2>Versand bestätigen</h2><form method="post" action="/cases/<?= rawurlencode($caseId) ?>/dispatch"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><?php if($route['requires_confirmation']):?><label><input type="checkbox" name="route_confirmed" value="1" required> Ich habe die vorgeschlagene Zuständigkeit geprüft und bestätige dieses Ziel.</label><br><?php else:?><input type="hidden" name="route_confirmed" value="1"><?php endif;?><?php if($review['warnings']):?><label><input type="checkbox" name="warnings_acknowledged" value="1" required> Ich habe die Versandhinweise geprüft.</label><br><?php else:?><input type="hidden" name="warnings_acknowledged" value="1"><?php endif;?><button type="submit">Versandauftrag verbindlich in Queue einstellen</button></form></section><?php endif;?>
<?php elseif($reviewError):?><section class="card"><div class="warn"><?= $e($reviewError) ?></div></section><?php endif;?>
</main></body></html>
