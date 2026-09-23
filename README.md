# MeldeVerkehr

MeldeVerkehr ist eine webbasierte Plattform zur strukturierten Dokumentation und Weiterleitung von Verkehrsordnungswidrigkeiten im ruhenden Verkehr.

## Zielbild

Die Plattform verbindet vier Bereiche:

1. Bürgerportal für Erfassung, Beweissicherung und Vorgangsverwaltung
2. automatisierte Behördenkommunikation und Routing
3. Community mit Problemstellen, Reputation und Leaderboards
4. späteres Behördenportal mit strukturierten Schnittstellen

## Technische Leitplanken

- PHP 8.3/8.4+
- MySQL oder MariaDB
- HTML5, CSS3, JavaScript
- PWA mit Service Worker und IndexedDB
- Shared-Hosting-kompatibel
- keine zwingenden Dauer-Worker, kein Redis/RabbitMQ erforderlich
- Hintergrundverarbeitung über Cronjobs und DB-basierte Queue
- SMTP für Ausgang, IMAP für Behördenantworten
- Originalbeweise außerhalb des öffentlichen Webroots
- KI/OCR ausschließlich assistierend; rechtlich relevante Angaben bestätigt der Nutzer

## Repository-Struktur

- `app/` – Anwendung und Domainlogik
- `config/` – Konfiguration
- `database/` – Migrationen, Seeder, Fixtures
- `public/` – öffentlicher Webroot/PWA
- `resources/` – Views, CSS, JS, Icons
- `storage/` – private Dateien, Beweise, Dokumente, Logs
- `cron/` – Shared-Hosting-Cronjobs
- `install/` – Webinstaller
- `update/` – Update-/Migrationslogik
- `tests/` – Tests
- `docs/` – Architektur und Spezifikation

## Branches

- `main` – stabile Releases
- `develop` – integrierter Entwicklungsstand
- `feature/*` – neue Funktionen
- `fix/*` – Bugfixes
- `release/*` – Releasevorbereitung
- `hotfix/*` – kritische Produktionsfixes

## Dokumentation

- [Produktplan](docs/PRODUCT_PLAN.md)
- [Technische Spezifikation](docs/SPECIFICATION.md)
- [Entwicklungsauftrag](docs/MASTER_PROMPT.md)
- [Projektstatus](PROJECT_STATUS.md)
- [Security Policy](SECURITY.md)

## Entwicklungsreihenfolge

M1 Fundament → M2 Bürgerportal → M3 Beweissystem → M4 Behördenrouting → M5 Kommunikation → M6 Vorgangsmanagement → M7 KI/OCR → M8 Analyse → M9 Community → M10 Reputation → M11 Moderation → M12 Behördenportal

## Status

Das Repository befindet sich im initialen Aufbau. Siehe `PROJECT_STATUS.md`.


## Installation (Entwicklungsstand)

1. Repository auf den Webspace deployen.
2. Domain nach Möglichkeit auf `public/` zeigen lassen. Alternativ greift die Root-`.htaccess`.
3. Schreibrechte für das Projektverzeichnis (zur Erzeugung von `.env`) und `storage/` sicherstellen.
4. Eine leere MySQL-/MariaDB-Datenbank anlegen.
5. `/install` im Browser öffnen.
6. Systemcheck durchführen und Datenbankzugang testen.
7. Anwendungsdaten und ersten Superadministrator anlegen.
8. Nach erfolgreicher Installation wird `/install` automatisch gesperrt.

Migrationen können bei vorhandenem CLI-Zugriff zusätzlich ausgeführt werden mit:

```bash
php database/migrate.php migrate
php database/migrate.php status
```


## Cronjobs

Die M1-Infrastruktur stellt einen CLI-Einstiegspunkt bereit:

```bash
php cron.php health
php cron.php queue
```

`health` schreibt einen erfolgreichen Heartbeat in `cron_runs`.  
`queue` verarbeitet verfügbare Jobs über die DB-basierte Queue. Fachliche Job-Handler werden durch spätere Module registriert.

Auf Shared Hosting können diese Befehle über reguläre Cronjobs aufgerufen werden. Jeder Lauf wird mit Status, Start-/Endzeit sowie Fehler- und Verarbeitungszähler protokolliert.
