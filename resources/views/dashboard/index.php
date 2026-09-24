<?php
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$statusLabels=['DRAFT'=>'Entwurf','CAPTURE_IN_PROGRESS'=>'Erfassung läuft','WAITING_FOR_EVIDENCE'=>'Beweise fehlen','READY_FOR_REVIEW'=>'Prüfbereit','REVIEW_REQUIRED'=>'Prüfung erforderlich','READY_FOR_SUBMISSION'=>'Versandbereit','SUBMISSION_PENDING'=>'Versand läuft','SENT'=>'Gesendet','DELIVERED'=>'Zugestellt','DELIVERY_UNKNOWN'=>'Zustellung unklar','DELIVERY_FAILED'=>'Zustellfehler','AUTHORITY_REPLY'=>'Behördenantwort','USER_ACTION_REQUIRED'=>'Aktion erforderlich','AUTHORITY_PROCESSING'=>'Behörde bearbeitet','CORRECTION_PENDING'=>'Korrektur','WITHDRAWAL_PENDING'=>'Rücknahme','CLOSED'=>'Abgeschlossen','ARCHIVED'=>'Archiviert','DELETION_PENDING'=>'Löschung vorgemerkt'];
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest"><title>Dashboard – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1080px;margin:28px auto;padding:20px}.hero,.card{background:#fff;border:1px solid #dfe5ec;border-radius:16px;padding:24px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-top:16px}.kpi{font-size:1.65rem;font-weight:850;margin-top:6px}.muted{color:#66758a}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}.button,button{display:inline-block;padding:11px 16px;border-radius:9px;border:0;background:#172033;color:#fff;text-decoration:none;font-weight:700}.ok{font-weight:700}.warn{font-weight:700}.footer{margin-top:18px}.recent{display:grid;gap:10px}.recent a{display:flex;justify-content:space-between;gap:12px;padding:12px;border:1px solid #e2e7ed;border-radius:10px;text-decoration:none;color:inherit}.task{padding:10px 0;border-bottom:1px solid #edf0f4}</style></head>
<body><main>
<section class="hero"><h1>Guten Tag, <?= $e($user['first_name']) ?></h1><p class="muted">Erfasse neue Meldungen oder setze bestehende Vorgänge fort.</p>
<div class="actions"><form method="post" action="/cases"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">+ Neue Meldung</button></form><a class="button" href="/cases">Meine Vorgänge</a><a class="button" href="/map">Meine Karte</a><a class="button" href="/problem-areas">Problemstellen</a><a class="button" href="/analytics">Analytics</a><a class="button" href="/settings/security">Sicherheit</a><?php if($canAdmin):?><a class="button" href="/admin">Administration</a><?php endif;?></div></section>
<div class="grid">
<section class="card"><div class="muted">Offene Vorgänge</div><div class="kpi"><?= $e($caseSummary['open']) ?></div></section>
<section class="card"><div class="muted">Entwürfe / Erfassung</div><div class="kpi"><?= $e($caseSummary['drafts']) ?></div></section>
<section class="card"><div class="muted">Aktion erforderlich</div><div class="kpi"><?= $e($caseSummary['actions']) ?></div></section>
<section class="card"><div class="muted">Zustellfehler</div><div class="kpi"><?= $e($caseSummary['delivery_errors']) ?></div></section>
</div>
<div class="grid">
<section class="card"><h2>Jetzt erledigen</h2>
<?php if($caseSummary['drafts']>0):?><div class="task"><a href="/cases?status=CAPTURE_IN_PROGRESS">Unvollständige Meldungen fortsetzen (<?= $e($caseSummary['drafts']) ?>)</a></div><?php endif;?>
<?php if($caseSummary['actions']>0):?><div class="task"><a href="/cases?status=USER_ACTION_REQUIRED">Vorgänge mit erforderlicher Aktion prüfen</a></div><?php endif;?>
<?php if($caseSummary['delivery_errors']>0):?><div class="task"><a href="/cases?status=DELIVERY_FAILED">Zustellfehler prüfen</a></div><?php endif;?>
<?php if(!$totpEnabled && $passkeyCount===0):?><div class="task"><a href="/settings/security">Kontosicherheit mit TOTP oder Passkey erhöhen</a></div><?php endif;?>
<?php if($caseSummary['drafts']===0 && $caseSummary['actions']===0 && $caseSummary['delivery_errors']===0 && ($totpEnabled || $passkeyCount>0)):?><p class="muted">Aktuell keine offenen Aufgaben.</p><?php endif;?>
</section>
<section class="card"><h2>Letzte Vorgänge</h2><div class="recent"><?php if(!$caseSummary['recent']):?><p class="muted">Noch keine Vorgänge vorhanden.</p><?php endif;?><?php foreach($caseSummary['recent'] as $item):?><a href="/cases/<?= rawurlencode($item['id']) ?>"><span><strong><?= $e($item['public_number']) ?></strong><br><small class="muted"><?= $e($statusLabels[$item['status']]??$item['status']) ?></small></span><small class="muted"><?= $e($item['updated_at']) ?></small></a><?php endforeach;?></div></section>
</div>
<div class="grid"><section class="card"><div class="muted">E-Mail</div><div class="kpi ok">Bestätigt</div></section><section class="card"><div class="muted">TOTP-2FA</div><div class="kpi <?= $totpEnabled?'ok':'warn' ?>"><?= $totpEnabled?'Aktiv':'Aus' ?></div></section><section class="card"><div class="muted">Passkeys</div><div class="kpi"><?= $e($passkeyCount) ?></div></section></div>
<form method="post" action="/logout" class="footer"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">Abmelden</button></form>
<p class="muted">MeldeVerkehr 0.8.0-dev · Bürgerportal</p></main><script src="/assets/app.js" defer></script></body></html>