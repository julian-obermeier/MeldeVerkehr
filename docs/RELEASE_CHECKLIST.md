# Release-Checklist

Diese Checkliste ergänzt das ausführliche [Release-/Deploy-/Restore-Runbook](RELEASE_RUNBOOK.md).

## Vor Freigabe

- [ ] Security Baseline grün
- [ ] PHP-Lint grün
- [ ] MySQL-Integrationstests grün
- [ ] keine offenen Migrationen
- [ ] `APP_DEBUG=false` in Produktion
- [ ] HTTPS aktiv
- [ ] Audit-Kette intakt
- [ ] Queue ohne FAILED-Jobs
- [ ] temporäre Exporte bereinigt
- [ ] verschlüsseltes Backup erstellt und verifiziert
- [ ] Restore-Guard und Maintenance-Status geprüft
- [ ] PWA/IndexedDB-Konfliktflow getestet
- [ ] Authority/API-Rate-Limit geprüft
- [ ] Tastaturfokus, Skip-Link und ARIA-Smoke-Test durchgeführt
- [ ] Release-ZIP und SHA-256-Artefakt erzeugt

## Deployment

- [ ] `php maintenance.php readiness`
- [ ] `php maintenance.php backup`
- [ ] Backup-ID notiert
- [ ] neuen Code einspielen
- [ ] `php maintenance.php update --from=<ALT> --to=<NEU>`
- [ ] `/health/live` liefert HTTP 200
- [ ] `/health/ready` liefert HTTP 200
- [ ] Login/Dashboard/Vorgang/Evidence/Behördenportal Smoke-Test
- [ ] Cronjobs kontrolliert
- [ ] Logs auf neue Fatal Errors geprüft

## Bei Fehlern

- [ ] Maintenance nicht vorschnell deaktivieren
- [ ] Backup mit `backup:verify` erneut prüfen
- [ ] letzten passenden Code-Stand wiederherstellen
- [ ] Restore nur per CLI und expliziter Bestätigung
- [ ] Readiness erneut prüfen
- [ ] Maintenance erst nach erfolgreicher Prüfung deaktivieren
