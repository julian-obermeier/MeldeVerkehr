# MeldeVerkehr

MeldeVerkehr ist eine webbasierte Plattform zur strukturierten Dokumentation und Weiterleitung von Verkehrsordnungswidrigkeiten im ruhenden Verkehr.

## Zielbild

Die Plattform verbindet vier Bereiche:

1. Bürgerportal für Erfassung, Beweissicherung und Vorgangsverwaltung
2. automatisierte Behördenkommunikation und Routing
3. Community mit Problemstellen, Reputation und Leaderboards
4. Behördenportal mit Authority-RBAC, strukturierten Schnittstellen und API v1

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
- `app/Release/` – Backup-, Update-, Readiness- und Maintenance-Logik
- `maintenance.php` – Shared-Hosting-CLI für Releasebetrieb
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
- [Release-/Deploy-/Restore-Runbook](docs/RELEASE_RUNBOOK.md)

## Entwicklungsreihenfolge

M1 Fundament → M2 Vorgangskern → M3 Beweissystem → M4 Sachverhalt/Final Review → M5 Behördenrouting/Versand → M6 Kommunikation → M7 KI/OCR → M8 Analyse/Problemstellen → M9 Community → M10 Suche/Dokumente/Notifications → M11 Behördenportal/API → M12 Produktionshärtung/Release Candidate → M13 Vorgangsversionierung/Lifecycle → M14 Moderation/Safety

## Status

Der aktuelle Entwicklungsstand ist `0.14.0-dev` – M14 komplettiert Moderation mit Einsprüchen, Abuse-Signalen, Eskalationen und Anti-Spam-/Bot-Erkennung. Siehe `PROJECT_STATUS.md`.


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
php cron.php inbound-mail
```

`health` schreibt einen erfolgreichen Heartbeat in `cron_runs`.  
`queue` verarbeitet verfügbare Jobs über die DB-basierte Queue, einschließlich Behördenversand und bestätigter Nutzerantworten.  
`inbound-mail` pollt das konfigurierte IMAP-Postfach; der Lauf bleibt wirkungslos, solange `COMM_INBOUND_ENABLED=false` gesetzt ist.  
Zusätzlich stehen `notifications`, `export-cleanup` und `retention-plan` zur Verfügung.

Auf Shared Hosting können diese Befehle über reguläre Cronjobs aufgerufen werden. Jeder Lauf wird mit Status, Start-/Endzeit sowie Fehler- und Verarbeitungszähler protokolliert.


## Tests

Neben PHP-Lint und Repository-Sicherheitsprüfungen läuft ein echter MySQL-Integrationstest in GitHub Actions. Er prüft unter anderem:

- vollständige Migrationen
- Registrierung und Login
- USER-/Admin-Permissions
- Login-Rate-Limiting
- Audit-Kettenintegrität
- Jobqueue Claim/Complete

Lokal kann der Foundation-Test gegen eine konfigurierte Testdatenbank ausgeführt werden:

```bash
php database/migrate.php migrate
php tests/run.php
```

## PWA

Die Anwendung enthält Manifest, Service Worker, Offline-Fallback und IndexedDB-Entwürfe für den Kern-Wizard. Private Navigationsantworten werden nicht persistiert. Lokale Entwürfe tragen `serverVersion`/`localVersion`; bei Konflikten gibt es keine stille Überschreibung, sondern nur explizites Übernehmen oder Verwerfen.


## M2 – Vorgänge

Der aktuelle Entwicklungsstand enthält den ersten echten Bürger-Vorgangsworkflow:

- neue Meldung als sicherer Entwurf
- öffentliche Nummer im Format `OWI-YYYY-NNNNNN`
- Fahrzeug und verschlüsseltes Kennzeichen
- GPS oder Adresse sowie Verkehrsraum
- versionierte Tatbestände
- adaptive Schrittfolge Fahrzeug → Standort → Tatbestand
- persönliche Vorgangsliste und Detailansicht
- Statusfilter sowie Suche nach Nummer, Ort oder Kennzeichen
- serverseitige Ownership-Prüfung

Die produktive Tatbestandsdatenbank enthält zunächst bewusst nur neutrale Kategorien und einen internen Entwurfs-Platzhalter. Konkrete rechtliche Angaben werden erst nach fachlicher Verifikation ergänzt.


## Releasebetrieb

Seit 0.12.0-rc1 steht eine Shared-Hosting-kompatible Release-CLI bereit:

~~~bash
php maintenance.php readiness
php maintenance.php backup
php maintenance.php backup:verify <BACKUP-ID>
php maintenance.php update --from=<ALT> --to=<NEU>
php maintenance.php restore <BACKUP-ID> --confirm
~~~

Das Admin-Releasecenter ist unter `/admin/system/update` verfügbar. Restore bleibt absichtlich CLI-only. Details stehen in [docs/RELEASE_RUNBOOK.md](docs/RELEASE_RUNBOOK.md).
