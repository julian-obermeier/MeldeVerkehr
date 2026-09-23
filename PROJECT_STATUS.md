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
- PHP-Lint-, Security- und MySQL-Integrationstest-Workflows
- PHP-Front-Controller, Request/Response, Router und Fehlerhandling
- /health-Endpunkt und Shared-Hosting-.htaccess-Schutz
- private Storage-Grundstruktur
- Konfigurationssystem auf Basis von .env + config/*.php
- sicherer PDO-Datenbank-Layer
- versionierte Migration-Engine inklusive CLI-Befehl
- zweistufiger Webinstaller unter /install
- Registrierung, Login/Logout, E-Mail-Verifikation und Passwort-Reset
- DB-basiertes Login-Rate-Limiting
- Rollen/Permissions-Grundlage und PermissionService
- HMAC-signiertes, verkettetes Audit-System
- DB-basierte Jobqueue mit Locking, Retry und Backoff
- Cron-Registry mit Heartbeat-/Run-Historie
- Admin-Systemdashboard mit DB-, Queue-, Cron- und Audit-Status
- PWA-Manifest, Service Worker, Offline-Fallback und App-Icon
- datenschutzfreundliche Cache-Strategie ohne Caching privater Navigationsantworten
- MySQL-Integrationstests für Migrationen, Auth, Permissions, Rate-Limit, Audit und Queue

## In Arbeit
- Ausbau des Rollen-/Permission-Systems für resource-basierte Policies/Ownership
- vollständiges Bürger-Dashboard
- Passkeys/TOTP

## Offen in M1
- vollständige resource-basierte Policies/Ownership-Prüfungen
- vollständiges aktionsorientiertes Bürger-Dashboard
- Passkeys und TOTP

## Bekannte Einschränkungen
- Der Router unterstützt aktuell nur exakt registrierte Pfade.
- Der aktuelle E-Mail-Transport verwendet PHP mail(); konfigurierbares SMTP folgt mit dem Kommunikations-/Mail-Ausbau.
- Das Bürger-Dashboard enthält noch keine echten Vorgänge; diese beginnen in M2.
- Die Queue besitzt Infrastruktur, fachliche Job-Handler folgen mit den jeweiligen Modulen.
- PWA-Offlinedaten/IndexedDB für Meldungsentwürfe folgen mit dem Meldeworkflow.

## Nächster Schritt
M1 abschließen: resource-basierte Authorization-Grundlage, Dashboard-Struktur und optionale Passkeys/TOTP vorbereiten; anschließend M2 – Bürgerportal/Vorgänge starten.
