# Changelog

Alle relevanten Änderungen an MeldeVerkehr werden hier dokumentiert.

## [Unreleased]

### Added
- initiale GitHub-Projektstruktur
- Produkt-, Spezifikations- und Entwicklungsdokumentation
- GitHub-first Workflow
- Konfigurationssystem mit .env-Unterstützung
- sicherer PDO-Datenbank-Layer
- versionierte Migration-Engine und CLI-Migrationstool
- Initialmigrationen für Benutzer, Rollen, Permissions und Einstellungen
- zweistufiger Webinstaller mit Systemcheck und Datenbankprüfung
- Superadmin-Erstellung und Installations-Lock
- CSRF-Schutz für Installationsformulare

### Changed
- Bootstrap lädt zentrale Anwendungskonfiguration und sichere Sessionparameter
- Startseite leitet vor der Installation auf /install um
- /health zeigt zusätzlich den Installationsstatus

### Fixed
- komplexe, gequotete .env-Werte werden korrekt wieder eingelesen
- Installer-Seeding ist bei Wiederholungsversuchen idempotenter

### Security
- Installer wird nach erfolgreicher Installation dauerhaft gesperrt
- Datenbankzugriffe verwenden PDO mit deaktivierten emulierten Prepared Statements
- Runtime-Dateien unter storage/app werden nicht versioniert
