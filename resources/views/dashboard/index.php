<?php $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#172033"><link rel="manifest" href="/manifest.webmanifest"><title>Dashboard – MeldeVerkehr</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0}main{max-width:1050px;margin:30px auto;padding:20px}.hero,.card{background:#fff;border:1px solid #dfe5ec;border-radius:16px;padding:24px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-top:16px}.kpi{font-size:1.3rem;font-weight:800;margin-top:6px}.muted{color:#66758a}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}.button,button{padding:11px 16px;border-radius:9px;border:0;background:#172033;color:#fff;text-decoration:none;font-weight:700}.disabled{opacity:.55}.ok{font-weight:700}.warn{font-weight:700}.footer{margin-top:18px}</style></head>
<body><main>
<section class="hero"><h1>Guten Tag, <?= $e($user['first_name']) ?></h1><p class="muted">MeldeVerkehr ist bereit. Der eigentliche Vorgangsworkflow startet mit M2.</p>
<div class="actions"><span class="button disabled" aria-disabled="true">+ Neue Meldung – M2</span><a class="button" href="/settings/security">Sicherheit</a><?php if($canAdmin):?><a class="button" href="/admin">Administration</a><?php endif;?></div></section>
<div class="grid">
<section class="card"><div class="muted">Offene Vorgänge</div><div class="kpi">Noch nicht verfügbar</div><p class="muted">Vorgänge werden in M2 aktiviert.</p></section>
<section class="card"><div class="muted">E-Mail</div><div class="kpi ok">Bestätigt</div></section>
<section class="card"><div class="muted">TOTP-2FA</div><div class="kpi <?= $totpEnabled?'ok':'warn' ?>"><?= $totpEnabled?'Aktiv':'Nicht aktiv' ?></div></section>
<section class="card"><div class="muted">Passkeys</div><div class="kpi"><?= $e($passkeyCount) ?></div><p class="muted">registriert</p></section>
</div>
<section class="card" style="margin-top:16px"><h2>Jetzt erledigen</h2>
<?php if(!$totpEnabled && $passkeyCount===0):?><p>Erhöhe die Kontosicherheit mit TOTP oder einem Passkey.</p><a href="/settings/security">Sicherheitsoptionen öffnen</a><?php else:?><p class="muted">Aktuell keine konto-bezogenen Aufgaben offen.</p><?php endif;?>
</section>
<form method="post" action="/logout" class="footer"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit">Abmelden</button></form>
<p class="muted">MeldeVerkehr 0.1.0-dev · PWA aktiv</p></main><script src="/assets/app.js" defer></script></body></html>
