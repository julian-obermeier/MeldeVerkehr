<?php $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Analytics – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1050px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.kpi{font-size:2rem;font-weight:850}.bar{height:9px;background:#dce3eb;border-radius:999px;overflow:hidden}.fill{height:100%;background:#172033}.muted{color:#66758a}table{width:100%;border-collapse:collapse}td,th{padding:8px;border-bottom:1px solid #e5e9ee;text-align:left}</style></head><body><main>
<p><a href="/dashboard">Dashboard</a> · <a href="/map">Karte</a> · <a href="/problem-areas">Problemstellen</a></p><h1>Meine Analytics</h1>
<section class="card"><div class="kpi"><?= $e($analytics['total']) ?></div><div class="muted">eigene Vorgänge im gewählten Zeitraum</div></section>
<div class="grid">
<section class="card"><h2>Status</h2><?php foreach($analytics['status'] as $r):?><p><?= $e($r['label']) ?> <strong><?= $e($r['count']) ?></strong></p><?php endforeach;?></section>
<section class="card"><h2>Orte</h2><?php foreach($analytics['cities'] as $r):?><p><?= $e($r['label']) ?> <strong><?= $e($r['count']) ?></strong></p><?php endforeach;?></section>
<section class="card"><h2>Tatbestandskategorien</h2><?php foreach($analytics['offense_categories'] as $r):?><p><?= $e($r['label']) ?> <strong><?= $e($r['count']) ?></strong></p><?php endforeach;?></section>
</div>
<section class="card"><h2>Monatsverlauf</h2><table><tr><th>Monat</th><th>Vorgänge</th></tr><?php foreach($analytics['months'] as $r):?><tr><td><?= $e($r['label']) ?></td><td><?= $e($r['count']) ?></td></tr><?php endforeach;?></table></section>
</main></body></html>