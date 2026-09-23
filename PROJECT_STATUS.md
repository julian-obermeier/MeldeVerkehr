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
- PHP-Front-Controller, Request/Response, Router und Fehlerhandling
- /health-Endpunkt und Shared-Hosting-.htaccess-Schutz
- private Storage-Grundstruktur
- Konfigurationssystem auf Basis von .env + config/*.php
- sicherer PDO-Datenbank-Layer
- versionierte Migration-Engine inklusive CLI-Befehl
- zweistufiger Webinstaller unter /install
- Superadmin-Erstellung und Installations-Lock
- Registrierung mit USER-Rolle
- Login/Logout mit Session-Rotation
- DB-basiertes Login-Rate-Limiting
- E-Mail-Verifikation mit gehashten, ablaufenden Tokens
- Passwort-Reset mit gehashten 30-Minuten-Tokens
- austauschbarer Mail-Transport mit Shared-Hosting-Fallback via PHP mail()
- PermissionService für Rollen und Berechtigungen
- geschütztes Dashboard als M1-Grundlage
- CSRF-Schutz für Auth- und Installer-Formulare

## In Arbeit
- Ausbau des zentralen Rollen-/Permission-Systems für Ressourcen- und Ownership-Prüfungen
- Audit-System
- automatisierte funktionale Tests

## Offen in M1
- vollständige resource-basierte Policies/Ownership-Prüfungen
- manipulationsgeschütztes Audit-System
- DB-basierte Queue
- Cron-System und Heartbeats
- Admin-Basisbereich
- vollständiges Bürger-Dashboard
- PWA-Grundstruktur
- vollständige Testinfrastruktur

## Bekannte Einschränkungen
- Der Router unterstützt aktuell nur exakt registrierte Pfade; Tokens werden daher per Query-Parameter verarbeitet.
- Der aktuelle E-Mail-Transport verwendet PHP mail(); konfigurierbares SMTP folgt mit dem Kommunikations-/Mail-Ausbau.
- Das Bürger-Dashboard ist noch eine M1-Grundlage und enthält noch keine Vorgänge.
- Passkeys und TOTP folgen nach dem Basis-Auth-Block.
- CI-Syntax- und Security-Checks sind vorhanden; funktionale Tests werden noch erweitert.

## Nächster Schritt
Manipulationsgeschütztes Audit-System sowie DB-basierte Queue und Cron-Heartbeats implementieren.
