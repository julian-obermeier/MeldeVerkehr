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

## In Arbeit
- vollständige Repository-Grundstruktur
- Konfigurationssystem
- automatisierte Tests für Bootstrap/Router

## Offen in M1
- Datenbank-Layer
- Migration-System
- Webinstaller
- Authentifizierung
- E-Mail-Verifikation
- Rollen und Permissions
- Audit-System
- DB-basierte Queue
- Cron-System und Heartbeats
- Admin-Basis
- Bürger-Dashboard
- PWA-Grundstruktur
- Testinfrastruktur

## Bekannte Einschränkungen
- Noch keine Datenbank oder Authentifizierung.
- Der Router unterstützt im ersten Grundgerüst nur exakt registrierte Pfade; parametrisierte Routen folgen im M1-Ausbau.
- CI-Syntaxprüfung ist eingerichtet; weitere Testworkflows folgen mit der Testinfrastruktur.

## Nächster Schritt
Konfigurations- und Datenbank-Layer sowie Migration-System implementieren.
