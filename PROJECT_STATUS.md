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
- Rollen/Permissions inklusive resource-basierter Ownership-Autorisierung
- HMAC-signiertes, verkettetes Audit-System
- DB-basierte Jobqueue mit Locking, Retry und Backoff
- Cron-Registry mit Heartbeat-/Run-Historie
- Admin-Systemdashboard mit DB-, Queue-, Cron- und Audit-Status
- aktionsorientiertes Bürger-Dashboard-Grundgerüst
- PWA-Manifest, Service Worker, Offline-Fallback und App-Icon
- datenschutzfreundliche Cache-Strategie ohne Caching privater Navigationsantworten
- TOTP-Zwei-Faktor-Authentifizierung mit verschlüsseltem Secret und Einmal-Recovery-Codes
- Passkeys/WebAuthn mit ES256/P-256, RP-ID-/Origin-/Challenge-/Signaturprüfung
- frische Re-Authentication für sensible Sicherheitseinstellungen
- Rate-Limits für Passwort-, TOTP- und Passkey-Anmeldung
- MySQL-Integrationstests plus zusätzliche Tests für Ownership, TOTP, Secret-Verschlüsselung und WebAuthn-Helfer

## M1-Status
M1 – Fundament ist technisch abgeschlossen. Die fachlichen Vorgangsdaten beginnen in M2.

## Bekannte Einschränkungen
- Der Router unterstützt aktuell nur exakt registrierte Pfade; dynamische Parameter folgen mit M2.
- Der aktuelle E-Mail-Transport verwendet PHP mail(); konfigurierbares SMTP folgt mit dem Kommunikations-/Mail-Ausbau.
- Das Bürger-Dashboard zeigt noch keine Vorgangszahlen, da die Case-Tabellen erst in M2 entstehen.
- Die Queue besitzt Infrastruktur, fachliche Job-Handler folgen mit den jeweiligen Modulen.
- PWA-Offlinedaten/IndexedDB für Meldungsentwürfe folgen mit dem Meldeworkflow.
- WebAuthn unterstützt in M1 gezielt ES256/P-256; weitere Algorithmen können später ergänzt werden.

## Nächster Schritt
M2 – Bürgerportal/Vorgänge: Case-Modell, Statusmaschine, Fahrzeug, Standort, Tatbestände und adaptiver Melde-Wizard.
