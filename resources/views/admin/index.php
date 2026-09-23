<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest"><title>Administration – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f4f6f9;color:#172033;margin:0}main{max-width:1100px;margin:32px auto;padding:20px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}.card{background:white;border:1px solid #dce3eb;border-radius:14px;padding:20px;margin-bottom:16px}.kpi{font-size:1.7rem;font-weight:800}.muted{color:#657389}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px;border-bottom:1px solid #edf0f4}a{color:inherit}</style></head>
<body><main><p><a href="/dashboard">← Dashboard</a></p><h1>Systemadministration</h1>
<div class="grid">
<div class="card"><div class="muted">Version</div><div class="kpi"><?= $e($version) ?></div></div>
<div class="card"><div class="muted">PHP</div><div class="kpi"><?= $e($phpVersion) ?></div></div>
<div class="card"><div class="muted">Datenbank</div><div class="kpi" style="font-size:1rem"><?= $e($databaseVersion) ?></div></div>
<div class="card"><div class="muted">Benutzer</div><div class="kpi"><?= $e($userCount) ?></div></div>
<div class="card"><div class="muted">Audit-Integrität</div><div class="kpi"><?= $auditIntegrity['ok'] ? 'OK' : 'FEHLER' ?></div></div>
</div>
<div class="card"><h2>Queue</h2>
<?php if(!$jobCounts):?><p class="muted">Noch keine Jobs vorhanden.</p><?php else:?><div class="grid"><?php foreach($jobCounts as $status=>$count):?><div><strong><?= $e($status) ?></strong>: <?= $e($count) ?></div><?php endforeach;?></div><?php endif;?>
</div>
<div class="card"><h2>Letzte Cron-Läufe</h2>
<?php if(!$cronRuns):?><p class="muted">Noch keine Cron-Läufe protokolliert.</p><?php else:?><table><thead><tr><th>Task</th><th>Status</th><th>Start</th><th>Verarbeitet</th><th>Fehler</th></tr></thead><tbody><?php foreach($cronRuns as $run):?><tr><td><?= $e($run['task']) ?></td><td><?= $e($run['status']) ?></td><td><?= $e($run['started_at']) ?></td><td><?= $e($run['processed_count']) ?></td><td><?= $e($run['error_count']) ?></td></tr><?php endforeach;?></tbody></table><?php endif;?>
</div>
<script src="/assets/app.js" defer></script></main></body></html>
