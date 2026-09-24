<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Behördenportal – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1150px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:20px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}input,select{width:100%;box-sizing:border-box;padding:9px;border:1px solid #bac5d1;border-radius:8px}button,.button{display:inline-block;padding:9px 13px;border:0;border-radius:9px;background:#172033;color:#fff;text-decoration:none;font-weight:700}.secondary{background:#fff;color:#172033;border:1px solid #9aa8b7}.message,.error{padding:10px;border-radius:8px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}table{width:100%;border-collapse:collapse}td,th{padding:9px;border-bottom:1px solid #e5e9ee;text-align:left}.muted{color:#66758a}.actions{display:flex;gap:8px;flex-wrap:wrap}</style></head><body><main>
<p><a href="/dashboard">Dashboard</a> · <a href="/authority/admin?authority=<?= rawurlencode($authorityId) ?>">Authority-Administration</a></p>
<h1>Behördenportal</h1>
<?php if($message):?><div class="message"><?= $e($message) ?></div><?php endif;?><?php if($error):?><div class="error"><?= $e($error) ?></div><?php endif;?>
<section class="card"><form method="get" action="/authority"><div class="grid">
<div><label>Behörde</label><select name="authority"><?php foreach($scopes as $scope):?><option value="<?= $e($scope['authority_id']) ?>" <?= $scope['authority_id']===$authorityId?'selected':'' ?>><?= $e($scope['authority_name']) ?> · <?= $e($scope['scope_role']) ?></option><?php endforeach;?></select></div>
<div><label>Suche</label><input name="q" value="<?= $e($query) ?>" placeholder="Vorgangsnummer, Straße, Ort"></div>
<div><label>Status</label><input name="status" value="<?= $e($status) ?>" placeholder="z. B. SENT"></div>
</div><p><button type="submit">Filtern</button></p></form>
<div class="actions"><a class="button secondary" href="/authority/<?= rawurlencode($authorityId) ?>/export/csv?q=<?= rawurlencode($query) ?>&status=<?= rawurlencode($status) ?>">CSV</a><a class="button secondary" href="/authority/<?= rawurlencode($authorityId) ?>/export/json?q=<?= rawurlencode($query) ?>&status=<?= rawurlencode($status) ?>">JSON</a><a class="button secondary" href="/authority/<?= rawurlencode($authorityId) ?>/export/xml?q=<?= rawurlencode($query) ?>&status=<?= rawurlencode($status) ?>">XML</a></div>
</section>
<section class="card"><h2>Übermittelte Vorgänge</h2><table><thead><tr><th>Vorgang</th><th>Status</th><th>Ort</th><th>Übermittelt</th><th>Anfragen</th></tr></thead><tbody>
<?php foreach($cases as $row):?><tr><td><a href="/authority/cases/<?= rawurlencode($row['id']) ?>"><strong><?= $e($row['public_number']) ?></strong></a></td><td><?= $e($row['status']) ?></td><td><?= $e(trim(($row['street']??'').' '.($row['house_number']??'').', '.($row['postal_code']??'').' '.($row['city']??''),', ')) ?></td><td><?= $e($row['sent_at']) ?></td><td><?= $e($row['open_inquiries']) ?></td></tr><?php endforeach;?>
</tbody></table><?php if(!$cases):?><p class="muted">Keine übermittelten Vorgänge für diesen Scope gefunden.</p><?php endif;?></section>
</main><script src="/assets/app.js" defer></script></body></html>