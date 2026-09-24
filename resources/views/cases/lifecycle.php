<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$c=$data['case'];
$statusLabels=[
'DRAFT'=>'Entwurf','CAPTURE_IN_PROGRESS'=>'Erfassung läuft','WAITING_FOR_EVIDENCE'=>'Beweise fehlen',
'READY_FOR_REVIEW'=>'Grunddaten prüfen','REVIEW_REQUIRED'=>'Prüfung erforderlich','READY_FOR_SUBMISSION'=>'Versandbereit',
'SUBMISSION_PENDING'=>'Versand läuft','SENT'=>'Gesendet','DELIVERED'=>'Zugestellt','DELIVERY_UNKNOWN'=>'Zustellung unklar',
'DELIVERY_FAILED'=>'Zustellfehler','AUTHORITY_REPLY'=>'Behördenantwort','USER_ACTION_REQUIRED'=>'Aktion erforderlich',
'AUTHORITY_PROCESSING'=>'Behörde bearbeitet','CORRECTION_PENDING'=>'Korrektur läuft','WITHDRAWAL_PENDING'=>'Rücknahme läuft',
'CLOSED'=>'Abgeschlossen','ARCHIVED'=>'Archiviert','DELETION_PENDING'=>'Löschung vorgemerkt'
];
$categoryLabels=[
'VEHICLE'=>'Fahrzeug/Kennzeichen','LOCATION'=>'Standort','OBSERVATION'=>'Beobachtung','OFFENSE'=>'Tatbestand',
'EVIDENCE'=>'Beweis','NARRATIVE'=>'Sachverhalt','PERSON_DATA'=>'Personenangaben','OTHER'=>'Sonstiges'
];
$closureLabels=[
'AUTHORITY_COMPLETED'=>'Behördliche Bearbeitung abgeschlossen','NO_FURTHER_ACTION'=>'Keine weitere Bearbeitung',
'RESOLVED'=>'Erledigt','WITHDRAWN'=>'Zurückgenommen','OTHER'=>'Sonstiger Abschluss'
];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest">
<title>Vorgangsmanagement <?= $e($c['public_number']) ?> – MeldeVerkehr</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1120px;margin:26px auto;padding:20px}
.hero,.card{background:#fff;border:1px solid #dfe5ec;border-radius:15px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
.muted{color:#66758a}.message,.error{padding:12px;border-radius:9px;margin-bottom:14px}.message{border:1px solid #8fb799}.error{border:1px solid #c99}
label{display:block;font-weight:700;margin:8px 0 5px}input,select,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #bac5d1;border-radius:8px;font:inherit}
textarea{min-height:105px}button,.button{display:inline-block;margin-top:10px;padding:10px 14px;border:0;border-radius:9px;background:#172033;color:#fff;font-weight:750;text-decoration:none;cursor:pointer}
.secondary{background:#fff;color:#172033;border:1px solid #9aa8b7}.danger{background:#8c2f39}.warn{background:#8a5a0a}.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf1f5;font-size:.85rem}
.ok{color:#24683b}.bad{color:#9d2733}.item{border-top:1px solid #e6ebf0;padding:14px 0}.item:first-child{border-top:0}.hash{font-family:ui-monospace,monospace;font-size:.8rem;word-break:break-all}.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:700px){.two{grid-template-columns:1fr}}
</style></head>
<body><main>
<p><a href="/cases/<?= rawurlencode($c['id']) ?>">← Zurück zum Vorgang</a></p>
<section class="hero">
<h1>Vorgangsmanagement</h1>
<p><strong><?= $e($c['public_number']) ?></strong> · <?= $e($statusLabels[$c['status']]??$c['status']) ?></p>
<p class="muted">Nachträge, Korrekturen und Rücknahmen werden revisionssicher dokumentiert. Bestehende Aktenstände werden dabei nicht still überschrieben.</p>
</section>

<?php if($message):?><div class="message" role="status"><?= $e($message) ?></div><?php endif;?>
<?php if($error):?><div class="error" role="alert"><?= $e($error) ?></div><?php endif;?>

<div class="grid">
<?php if($data['can_amend']):?>
<section class="card"><h2>Nachtrag anlegen</h2>
<p class="muted">Ein Nachtrag ergänzt die Akte append-only und erzeugt automatisch eine neue Vorgangsversion.</p>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/lifecycle/amendments">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Titel</label><input name="title" maxlength="190" required>
<label>Nachtrag</label><textarea name="content" maxlength="10000" required></textarea>
<button type="submit">Nachtrag revisionssicher speichern</button>
</form></section>
<?php endif;?>

<?php if($data['can_correct']):?>
<section class="card"><h2>Korrektur anfordern</h2>
<p class="muted">Der aktuelle Aktenstand wird vor dem Antrag eingefroren. Die ursprünglichen Angaben bleiben historisch erhalten.</p>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/lifecycle/corrections">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Bereich</label><select name="category"><?php foreach($data['correction_categories'] as $key):?><option value="<?= $e($key) ?>"><?= $e($categoryLabels[$key]??$key) ?></option><?php endforeach;?></select>
<label>Bisherige Angabe optional</label><textarea name="original_value" maxlength="10000"></textarea>
<label>Korrigierte Angabe</label><textarea name="corrected_value" maxlength="10000" required></textarea>
<label>Begründung</label><textarea name="reason" maxlength="10000" required></textarea>
<button class="warn" type="submit">Korrekturworkflow starten</button>
</form></section>
<?php endif;?>

<?php if($data['can_withdraw']):?>
<section class="card"><h2>Rücknahme starten</h2>
<p class="muted">Vor der Rücknahme wird ein unveränderlicher Aktenstand gespeichert.</p>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/lifecycle/withdrawals">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Begründung</label><textarea name="reason" maxlength="10000" required></textarea>
<button class="danger" type="submit">Rücknahme anfordern</button>
</form></section>
<?php endif;?>

<?php if($data['can_close']):?>
<section class="card"><h2>Vorgang abschließen</h2>
<p class="muted">Beim Abschluss wird eine vollständige Abschlussakte mit SHA-256-Integritätsnachweis erzeugt.</p>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/lifecycle/close">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Abschlussgrund</label><select name="closure_reason">
<?php foreach($data['closure_reasons'] as $key): if($key==='WITHDRAWN') continue;?><option value="<?= $e($key) ?>"><?= $e($closureLabels[$key]??$key) ?></option><?php endforeach;?>
</select>
<label>Abschlussvermerk optional</label><textarea name="closure_note" maxlength="10000"></textarea>
<button type="submit">Abschlussakte erzeugen</button>
</form></section>
<?php endif;?>

<?php if($data['can_archive']):?>
<section class="card"><h2>Archivieren</h2>
<p class="muted">Der Abschluss bleibt unverändert erhalten; zusätzlich wird ein eigener Archivstand versioniert.</p>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/lifecycle/archive">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<button type="submit">Vorgang archivieren</button>
</form></section>
<?php endif;?>
</div>

<section class="card"><h2>Korrekturverlauf</h2>
<?php if(!$data['corrections']):?><p class="muted">Noch keine Korrekturen.</p><?php else:?>
<?php foreach($data['corrections'] as $row):?><div class="item">
<strong><?= $e($categoryLabels[$row['category']]??$row['category']) ?></strong> <span class="tag"><?= $e($row['status']) ?></span>
<p><?= nl2br($e($row['corrected_value'])) ?></p>
<p class="muted">Begründung: <?= nl2br($e($row['reason'])) ?><br>Angefordert: <?= $e($row['requested_at']) ?> · Ausgangsstatus: <?= $e($statusLabels[$row['previous_status']]??$row['previous_status']) ?></p>
<?php if($row['status']==='OPEN' && $c['status']==='CORRECTION_PENDING'):?>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/lifecycle/corrections/<?= rawurlencode($row['id']) ?>/complete">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Abschluss-/Übermittlungsvermerk optional</label><textarea name="completion_note" maxlength="10000"></textarea>
<button type="submit">Korrektur dokumentiert abschließen</button>
</form>
<?php elseif($row['completed_at']):?><p class="ok">Abgeschlossen: <?= $e($row['completed_at']) ?><?php if($row['completion_note']):?> · <?= nl2br($e($row['completion_note'])) ?><?php endif;?></p><?php endif;?>
</div><?php endforeach;?>
<?php endif;?></section>

<section class="card"><h2>Rücknahmen</h2>
<?php if(!$data['withdrawals']):?><p class="muted">Noch keine Rücknahme.</p><?php else:?>
<?php foreach($data['withdrawals'] as $row):?><div class="item">
<strong>Rücknahme</strong> <span class="tag"><?= $e($row['status']) ?></span>
<p><?= nl2br($e($row['reason'])) ?></p>
<p class="muted">Angefordert: <?= $e($row['requested_at']) ?> · Ausgangsstatus: <?= $e($statusLabels[$row['previous_status']]??$row['previous_status']) ?></p>
<?php if($row['status']==='OPEN' && $c['status']==='WITHDRAWAL_PENDING'):?>
<form method="post" action="/cases/<?= rawurlencode($c['id']) ?>/lifecycle/withdrawals/<?= rawurlencode($row['id']) ?>/complete">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Abschlussvermerk optional</label><textarea name="completion_note" maxlength="10000"></textarea>
<button class="danger" type="submit">Rücknahme abschließen und Abschlussakte erzeugen</button>
</form>
<?php elseif($row['completed_at']):?><p class="ok">Abgeschlossen: <?= $e($row['completed_at']) ?></p><?php endif;?>
</div><?php endforeach;?>
<?php endif;?></section>

<section class="card"><h2>Nachträge</h2>
<?php if(!$data['amendments']):?><p class="muted">Noch keine Nachträge.</p><?php else:?>
<?php foreach($data['amendments'] as $row):?><div class="item">
<strong>#<?= $e($row['amendment_no']) ?> · <?= $e($row['title']) ?></strong>
<p><?= nl2br($e($row['content'])) ?></p><p class="muted"><?= $e($row['created_at']) ?></p>
</div><?php endforeach;?>
<?php endif;?></section>

<section class="card"><h2>Abschlussakten</h2>
<?php if(!$data['closures']):?><p class="muted">Noch keine Abschlussakte.</p><?php else:?>
<?php foreach($data['closures'] as $row):?><div class="item">
<strong>Abschlussakte #<?= $e($row['closure_no']) ?> · <?= $e($closureLabels[$row['closure_reason']]??$row['closure_reason']) ?></strong>
<p class="<?= $row['integrity_valid']?'ok':'bad' ?>"><?= $row['integrity_valid']?'Integrität geprüft ✓':'Integritätsprüfung fehlgeschlagen' ?></p>
<?php if($row['closure_note']):?><p><?= nl2br($e($row['closure_note'])) ?></p><?php endif;?>
<p class="hash">SHA-256: <?= $e($row['dossier_sha256']) ?></p>
<p><a class="button secondary" href="/cases/<?= rawurlencode($c['id']) ?>/lifecycle/closures/<?= rawurlencode($row['id']) ?>/export">Abschlussakte herunterladen</a></p>
<p class="muted">Abgeschlossen: <?= $e($row['closed_at']) ?><?php if($row['archived_at']):?> · Archiviert: <?= $e($row['archived_at']) ?><?php endif;?></p>
</div><?php endforeach;?>
<?php endif;?></section>

<section class="card"><h2>Vorgangsversionen</h2>
<?php if(!$data['versions']):?><p class="muted">Noch keine revisionssichere Version vorhanden. Die erste Version wird beim nächsten Nachtrag, Korrektur-, Rücknahme- oder Abschlussvorgang automatisch erzeugt.</p><?php else:?>
<?php foreach($data['versions'] as $row):?><div class="item">
<strong>Version <?= $e($row['version_no']) ?> · <?= $e($row['version_type']) ?></strong>
<span class="<?= $row['integrity_valid']?'ok':'bad' ?>"><?= $row['integrity_valid']?' ✓':' ✕' ?></span>
<?php if($row['reason']):?><p><?= $e($row['reason']) ?></p><?php endif;?>
<p class="hash">SHA-256: <?= $e($row['snapshot_sha256']) ?></p>
<p class="muted"><?= $e($row['created_at']) ?></p>
</div><?php endforeach;?>
<?php endif;?></section>
</main><script src="/assets/app.js" defer></script></body></html>
