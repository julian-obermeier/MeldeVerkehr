<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$lats=array_column($points,'latitude'); $lons=array_column($points,'longitude');
$minLat=$lats?min($lats):0; $maxLat=$lats?max($lats):1; $minLon=$lons?min($lons):0; $maxLon=$lons?max($lons):1;
$latSpan=max(0.0001,$maxLat-$minLat); $lonSpan=max(0.0001,$maxLon-$minLon);
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Meine Karte – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1100px;margin:28px auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.map{width:100%;height:520px;background:#eef2f6;border-radius:12px;border:1px solid #d5dde6}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}.item{border:1px solid #e1e7ed;border-radius:10px;padding:12px}.muted{color:#66758a}.nav a{margin-right:14px}</style></head><body><main>
<p class="nav"><a href="/dashboard">Dashboard</a><a href="/problem-areas">Problemstellen</a><a href="/analytics">Analytics</a></p>
<h1>Meine Vorgangskarte</h1><p class="muted">Nur deine eigenen Vorgänge werden dargestellt. Diese Ansicht lädt keine externen Kartentiles und übermittelt keine Standortdaten an Kartendienste.</p>
<section class="card">
<?php if($points):?><svg class="map" viewBox="0 0 1000 520" role="img" aria-label="Private schematische Karte eigener Vorgänge">
<rect x="0" y="0" width="1000" height="520" fill="transparent"/>
<?php foreach($points as $p):$x=40+(($p['longitude']-$minLon)/$lonSpan)*920;$y=480-(($p['latitude']-$minLat)/$latSpan)*440;?>
<a href="/cases/<?= rawurlencode($p['case_id']) ?>"><circle cx="<?= $e(round($x,2)) ?>" cy="<?= $e(round($y,2)) ?>" r="7"><title><?= $e($p['public_number'].' · '.($p['street']??'').' '.($p['city']??'')) ?></title></circle></a>
<?php endforeach;?></svg>
<?php else:?><p class="muted">Noch keine eigenen Vorgänge mit GPS-Koordinaten vorhanden.</p><?php endif;?>
</section>
<section class="card"><h2>Vorgänge mit Position</h2><div class="grid"><?php foreach($points as $p):?><article class="item"><strong><?= $e($p['public_number']) ?></strong><p><?= $e(trim(($p['street']??'').' '.($p['house_number']??'').', '.($p['postal_code']??'').' '.($p['city']??''),', ')) ?></p><p><?= $e($p['offense_title']??'Ohne Tatbestand') ?></p><small class="muted"><?= $e($p['status']) ?> · <?= $e($p['observed_at']??'') ?></small></article><?php endforeach;?></div></section>
<section class="card"><h2>Erkannte private Hotspots</h2><?php if(!$hotspots):?><p class="muted">Noch keine Cluster mit mindestens zwei eigenen Vorgängen.</p><?php endif;?><?php foreach($hotspots as $h):?><p><strong><?= $e($h['case_count']) ?> Vorgänge</strong> · <?= $e(trim(($h['street']??'').' '.($h['city']??''))) ?> · <?= $e($h['latitude']) ?> / <?= $e($h['longitude']) ?></p><?php endforeach;?></section>
</main></body></html>