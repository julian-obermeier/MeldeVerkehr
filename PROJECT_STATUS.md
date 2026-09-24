# Projektstatus

## Aktuelle Version
1.0.0-rc.1

## Phase
M12 – Produktionshärtung & Release Candidate

## M1–M11
Fundament, Vorgangskern, Beweissystem, Zeugenbericht/finaler Review, Behördenrouting/Dispatch, Behördenkommunikation, Assistenz, private Karten/Analytics, Community, Suche/Dokumentcenter/Notifications/Retention sowie Behördenportal/API sind abgeschlossen.

## M12 – umgesetzt
- CLI-Backup-Service mit Manifest, SHA-256 und restriktiven Dateirechten
- Datenbankbackup via `mysqldump`/`mariadb-dump` wenn verfügbar
- Backup-Verifikation vor Update und Restore
- expliziter Restore-Befehl mit `--yes`
- Maintenance-Mode mit persistiertem Status
- Update-Transaktion mit Preflight, Backup, Maintenance, Migrationen und Post-Check
- bei Updatefehler bleibt Maintenance absichtlich aktiv
- automatischer Updatepfad erfordert verifiziertes lokales DB-Backup
- externer DB-Backup-Pfad nur mit explizitem `--external-db-backup-confirmed`
- Release-Health-Service für Installation, APP_KEY, Debug, HTTPS, Storage, Migrationen, Audit, Queue, Cron, Mail und Backupfähigkeit
- `/health/live` für Liveness
- `/health/ready` für Readiness; Maintenance ergibt 503
- Offline-Entwürfe mit IndexedDB
- lokale Fotoablage bis zur expliziten Synchronisierung
- Versionskonflikterkennung zwischen lokalem und Server-Entwurf
- explizite Konfliktentscheidung USE_LOCAL / USE_SERVER
- Offline-Sync-API mit CSRF und Ownership
- Offline-Foto-Upload in den bestehenden Evidence-Service
- PWA-Shortcut für neue/offline Meldung und offene Vorgänge
- Service-Worker-Cache für Offline-Draft-Shell und Script
- zentrale Accessibility-Härtung für serverseitig gerenderte Views
- Skip-Link und fokussierbares Main-Ziel
- sichtbarer Tastaturfokus
- Reduced-Motion-Unterstützung
- idempotente Accessibility-Injektion
- reproduzierbares Shared-Hosting-Release-ZIP in GitHub Actions
- SHA-256-Datei für Release-ZIP
- Release-Paket schließt `.env` und sensible Runtime-Daten aus
- Produktions-Deployment-/Restore-Dokumentation
- Release-Checkliste
- RC-Regressionstests für Maintenance, Backup-Manipulation, Accessibility und PWA
- Batch-/Grenztest für 250 Notifications
- Clamp-Regressions für Suche und Authority-Inbox

## Produktionsprinzipien
- `public/` ist der bevorzugte DocumentRoot.
- `APP_DEBUG=false` in Produktion.
- Updates werden nicht ohne Datenbanksicherung durchgeführt.
- bei unbekanntem Updatezustand bleibt Maintenance aktiv.
- Backups und Runtime-Evidence liegen außerhalb des öffentlichen Webroots.
- Readiness ist strenger als Liveness.
- Shared Hosting benötigt keine dauerhaften Worker.
- Queue, Notifications, Mailimport, Cleanup und Retention laufen per Cron.
- Offline-Synchronisierung überschreibt Serveränderungen niemals still.
- Release-Pakete enthalten keine produktiven Secrets oder Nutzerdaten.
- Accessibility darf nicht ausschließlich auf Farbe oder Mausbedienung angewiesen sein.

## Vor RC→Stable noch manuell zu prüfen
- Installation des Release-ZIPs auf einem leeren ALL-INKL-Ziel
- Update von der aktuell installierten Version über `update.php`
- reales externes oder lokales DB-Backup und Test-Restore
- SMTP/IMAP mit den später produktiven Postfächern
- PWA-Installation auf mindestens Android/Chrome und iOS/Safari
- Tastatur-/Screenreader-Smoke-Test der Kernflows
- produktive Cronfrequenzen
- tatsächliche Dateirechte des Hosters
- Logs nach einem kompletten Smoke-Test

## Nächster Schritt
M12 vollständig durch Security Baseline, PHP-Lint und MySQL-Integrationstest laufen lassen. Nach erfolgreichem Merge kann `1.0.0-rc.1` auf dem Test-Webspace deployed und anschließend als Stable-Kandidat bewertet werden.
