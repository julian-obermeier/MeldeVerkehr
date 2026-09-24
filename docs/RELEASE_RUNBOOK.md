# MeldeVerkehr – Release-, Deploy- und Restore-Runbook

## Ziel
Dieses Runbook beschreibt den produktiven Shared-Hosting-Betrieb ab 0.12.0-rc1.

Empfohlenes Domainziel:

~~~
/www/htdocs/<account>/verkehr.obermeier-it.de/public
~~~

Projektwurzel:

~~~
/www/htdocs/<account>/verkehr.obermeier-it.de
~~~

## 1. Voraussetzungen
- PHP 8.3/8.4+
- PDO MySQL
- OpenSSL
- mbstring
- fileinfo
- GD oder Imagick für Bildverarbeitung
- HTTPS
- schreibbares storage/
- Domain möglichst direkt auf public/
- APP_DEBUG=false in Produktion

Vor jedem Release:

~~~bash
cd /www/htdocs/<account>/verkehr.obermeier-it.de
php maintenance.php readiness
~~~

Ein Release darf nicht als bereit gelten, wenn blockierende Readiness-Checks fehlschlagen.

## 2. Backup vor Änderungen

Vollbackup:

~~~bash
php maintenance.php backup
~~~

Nur Datenbank:

~~~bash
php maintenance.php backup --database-only
~~~

Die Ausgabe enthält die Backup-ID. Danach immer verifizieren:

~~~bash
php maintenance.php backup:verify <BACKUP-ID>
~~~

Backups liegen unter storage/backups/ außerhalb des Webroots. Datenbank und Runtime-Dateien werden verschlüsselt gespeichert; das Manifest wird per SHA-256 abgesichert.

## 3. Update ab M12

Maintenance aktivieren:

~~~bash
php maintenance.php maintenance:on --reason="Deployment"
~~~

Code aktualisieren:

~~~bash
git checkout develop
git pull origin develop
~~~

Aktuelle Code-Version prüfen:

~~~bash
cat VERSION
~~~

Update ausführen:

~~~bash
php maintenance.php update --from=<ALTE_VERSION> --to=<NEUE_VERSION>
~~~

Beispiel:

~~~bash
php maintenance.php update --from=0.11.0-dev --to=0.12.0-rc1
~~~

Der Updater:
1. prüft den Migrationsstand,
2. aktiviert bzw. behält Maintenance,
3. erstellt ein vollständiges Backup,
4. verifiziert das Backup,
5. führt offene Migrationen aus,
6. schreibt den Runtime-Versionsstatus,
7. protokolliert den Update-Lauf,
8. deaktiviert Maintenance nur bei Erfolg.

Bei Fehler bleibt Maintenance absichtlich aktiv.

Danach:

~~~bash
php maintenance.php readiness
php maintenance.php status
~~~

## 4. Erstes Upgrade von M11 auf M12
M11 kennt maintenance.php noch nicht. Daher:

~~~bash
git checkout develop
git pull origin develop
php maintenance.php update --from=0.11.0-dev --to=0.12.0-rc1
~~~

Der M12-Updater legt seine Release-Operationstabellen idempotent selbst an, bevor das eigentliche Backup-/Migrationsverfahren beginnt.

## 5. Web-Releasecenter
Superadmins bzw. Benutzer mit admin.system können folgende Seite verwenden:

~~~
/admin/system/update
~~~

Dort verfügbar:
- Readiness
- Backup-Erstellung
- Backup-Verifikation
- Update-Lauf
- Update-Historie
- Maintenance-Status

Restore bleibt absichtlich CLI-only.

## 6. Restore

Zuerst Backup prüfen:

~~~bash
php maintenance.php backup:verify <BACKUP-ID>
~~~

Maintenance aktivieren:

~~~bash
php maintenance.php maintenance:on --reason="Restore"
~~~

Wenn das Backup zu einer älteren Anwendungsversion gehört, zuerst den passenden Git-Stand auschecken.

Restore nur mit expliziter Bestätigung:

~~~bash
php maintenance.php restore <BACKUP-ID> --confirm
~~~

Der Restore:
- verifiziert Manifest, DB-Payload und jede Runtime-Datei,
- entschlüsselt zunächst in einen Staging-Bereich,
- stellt danach die Datenbank wieder her,
- spielt die im Manifest enthaltenen Runtime-Dateien zurück.

Zusätzliche neuere Runtime-Dateien werden bewusst nicht automatisch gelöscht.

Nach erfolgreichem Restore:

~~~bash
php database/migrate.php status
php maintenance.php readiness
php maintenance.php maintenance:off
~~~

## 7. Cronjobs

Mindestens:

~~~bash
php cron.php queue
php cron.php inbound-mail
php cron.php notifications
php cron.php export-cleanup
php cron.php retention-plan
php cron.php rate-limit-cleanup
php cron.php health
~~~

Empfehlung:
- Queue: alle 5 Minuten
- Inbound-Mail: alle 5 Minuten
- Notifications: alle 5–15 Minuten
- Export-Cleanup: täglich
- Retention-Plan: täglich
- Rate-Limit-Cleanup: täglich
- Health: alle 15 Minuten

## 8. Production-Konfiguration

Empfohlen:

~~~env
APP_ENV=production
APP_DEBUG=false
BACKUP_MAX_FILE_BYTES=52428800
API_RATE_LIMIT_ATTEMPTS=120
API_RATE_LIMIT_WINDOW_SECONDS=60
~~~

APP_KEY, DB-Passwort, SMTP-/IMAP-Zugangsdaten und API-Schlüssel niemals in Git committen.

## 9. Health / Diagnose

HTTP:

~~~
/health
~~~

CLI:

~~~bash
php maintenance.php readiness
~~~

Admin:

~~~
/admin
/admin/system/update
~~~

## 10. Release-Abnahme
Vor Stable:
- Security Baseline grün
- PHP Lint grün
- MySQL Integration Tests grün
- Backup erzeugt und verifiziert
- Restore auf Test-/Stagingumgebung erfolgreich durchgespielt
- APP_DEBUG=false
- Install-Lock vorhanden
- Audit-Kette intakt
- Storage schreibbar
- Queue ohne FAILED-Stau
- Service Worker + Manifest vorhanden
- Authority-API-Rate-Limit geprüft
- Offline-Konfliktfall manuell geprüft
- Tastaturfokus/Skip-Link auf Kernseiten geprüft
