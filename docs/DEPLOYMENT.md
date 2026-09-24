# Produktions-Deployment

## Ziel

MeldeVerkehr ist für PHP 8.3/8.4 und MySQL/MariaDB auf Shared Hosting ausgelegt. Der Webroot soll auf `public/` zeigen.

## Erstinstallation

1. Release-Paket oder Git-Checkout in das Zielverzeichnis kopieren.
2. Domain-DocumentRoot auf `<projekt>/public` setzen.
3. PHP 8.3 oder 8.4 aktivieren.
4. Leere Datenbank anlegen.
5. `storage/app`, `storage/logs` und `storage/backups` für den PHP-Prozess schreibbar machen.
6. `https://<domain>/install` aufrufen.
7. Nach Abschluss `/health/live` und `/health/ready` prüfen.
8. Cronjobs aus der Installer-Ausgabe einrichten.

## Update

Vor jedem Update:

```bash
php update.php preflight
php update.php backup
```

Code aktualisieren und anschließend:

```bash
php update.php apply
```

Falls der Webspace kein `mysqldump`/`mariadb-dump` bereitstellt, muss **vorher extern** ein vollständiges Datenbankbackup erzeugt und geprüft werden. Erst dann darf bewusst ausgeführt werden:

```bash
php update.php apply --external-db-backup-confirmed
```

Das Flag ist eine ausdrückliche Operator-Bestätigung; MeldeVerkehr behauptet damit nicht, das externe Backup selbst geprüft zu haben.

`apply` aktiviert Maintenance, prüft ein vollständiges DB-Backup, führt Migrationen aus und lässt Maintenance nur bei erfolgreichem Post-Check automatisch fallen.

Bei einem fehlgeschlagenen Update bleibt Maintenance aktiv. Erst Ursache prüfen, dann gegebenenfalls:

```bash
php update.php verify-backup <backup-id>
php update.php restore <backup-id> --yes
```

Nach einem Restore bleibt Maintenance absichtlich aktiv, bis Code- und Datenbankstand manuell geprüft wurden.

## Health-Probes

- `/health/live`: PHP-Prozess und Router antworten.
- `/health/ready`: Datenbank, Migrationen, Storage, Audit und Produktionskonfiguration sind einsatzbereit; Maintenance führt zu HTTP 503.
- `/health`: einfacher Kompatibilitäts-Endpunkt.

## Shared-Hosting-Hinweise

- Keine dauerhaften Worker starten.
- Queue, Notifications, Inbound-Mail, Export-Cleanup und Retention über Cron ausführen.
- `.env`, Backups, Evidence und Mail-Storage nie in den Webroot verschieben.
- `APP_DEBUG=false` in Produktion.
- `APP_URL` mit HTTPS konfigurieren.
- Nach jedem Deployment `php update.php status` prüfen.
