# Release-Checklist

## Vor dem Release

- [ ] Security Baseline grün
- [ ] PHP-Lint grün
- [ ] MySQL-Integrationstests grün
- [ ] keine offenen Migrationen im Zielsystem
- [ ] `APP_DEBUG=false`
- [ ] HTTPS-`APP_URL`
- [ ] Storage schreibbar
- [ ] Audit-Kette intakt
- [ ] Backup-Fähigkeit geprüft oder externes DB-Backup bestätigt
- [ ] Cronjobs aktiv und nicht veraltet
- [ ] SMTP/IMAP-Konfiguration geprüft
- [ ] PWA installierbar; Offline-Shell und Offline-Entwurf geprüft
- [ ] Tastaturfokus und Skip-Link auf Kernseiten geprüft
- [ ] Authority-/API-Scope-Regression grün
- [ ] Release-ZIP und SHA-256 erzeugt

## Deployment

- [ ] Wartungsfenster angekündigt
- [ ] `php update.php preflight`
- [ ] `php update.php backup`
- [ ] neuen Code einspielen
- [ ] `php update.php apply`
- [ ] `/health/live` = 200
- [ ] `/health/ready` = 200
- [ ] Login, Dashboard, Vorgang, Evidence und Behördenportal Smoke-Test
- [ ] Cronlauf `health` kontrolliert
- [ ] Logs auf neue Fatal Errors geprüft

## Rollback

- [ ] Maintenance aktiv lassen
- [ ] letzten verifizierten Code-Stand herstellen
- [ ] Backup mit `verify-backup` prüfen
- [ ] Restore nur mit explizitem `--yes`
- [ ] Migration-/Codekompatibilität prüfen
- [ ] Readiness erneut prüfen
- [ ] Maintenance erst danach deaktivieren
