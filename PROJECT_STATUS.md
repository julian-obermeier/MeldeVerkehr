# Projektstatus

## Aktuelle Version
0.1.0-dev

## Phase
M1 – Fundament

## Umgesetzt
- GitHub-first Repository eingerichtet
- main und develop vorhanden
- Produktplan, Spezifikation und Entwicklungs-Masterprompt im Repository
- README, Security-, Contribution- und Agent-Regeln
- GitHub Issue-/PR-Templates
- PHP-Lint- und Security-Baseline-Workflows
- M1-Arbeitspakete als GitHub Issues
- PHP-Front-Controller
- leichtgewichtiger Autoloader
- Request-/Response-Abstraktion
- Basis-Router mit 404-Behandlung
- Application-Kernel mit Request-ID und Fehlerlogging
- /health-Endpunkt
- Shared-Hosting-.htaccess-Schutz
- private Storage-Grundstruktur
- typisiertes Konfigurationssystem auf Basis von .env + config/*.php
- sicherer PDO-Datenbank-Layer
- versionierte Migration-Engine inklusive CLI-Befehl
- Initialmigrationen für Benutzer, Rollen, Permissions und Einstellungen
- zweistufiger Webinstaller unter /install
- Datenbank-Verbindungstest im Installer
- Superadmin-Erstellung mit password_hash()
- Installations-Lock und automatische Sperre von /install
- CSRF-Schutz im Installer

## In Arbeit
- automatisierte Tests für Bootstrap, Config, Migrationen und Installer
- weitere M1-Sicherheits- und Authentifizierungsfunktionen

## Offen in M1
- vollständige Authentifizierung
- E-Mail-Verifikation
- Rollen- und Permission-Service
- Audit-System
- DB-basierte Queue
- Cron-System und Heartbeats
- Admin-Basis
- Bürger-Dashboard
- PWA-Grundstruktur
- vollständige Testinfrastruktur

## Bekannte Einschränkungen
- Der Router unterstützt aktuell nur exakt registrierte Pfade; parametrisierte Routen folgen im M1-Ausbau.
- Der Webinstaller benötigt eine bereits angelegte MySQL/MariaDB-Datenbank und passende Zugangsdaten.
- SMTP/IMAP werden erst nach Installation konfiguriert.
- CI-Syntaxprüfung ist eingerichtet; funktionale Tests folgen mit der Testinfrastruktur.

## Nächster Schritt
Authentifizierung, E-Mail-Verifikation und das zentrale Rollen-/Permission-System implementieren.
