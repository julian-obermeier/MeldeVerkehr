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
- Registrierung, Login und Logout
- E-Mail-Verifikation mit ablaufenden, gehashten Tokens
- Passwort-Reset mit 30-Minuten-Token
- DB-basiertes Login-Rate-Limiting
- PermissionService für Rollen und Berechtigungen
- Shared-Hosting-Mailtransport via PHP mail()
- geschütztes Dashboard-Grundgerüst
- CSRF-Schutz für Installations- und Authentifizierungsformulare

### Changed
- Bootstrap lädt zentrale Anwendungskonfiguration und sichere Sessionparameter
- Application stellt Datenbankverbindung lazy bereit
- Startseite leitet vor der Installation auf /install und danach auf /dashboard um
- /health zeigt zusätzlich den Installationsstatus
- Installer setzt eine initiale Absenderadresse für Systemmails

### Fixed
- komplexe, gequotete .env-Werte werden korrekt wieder eingelesen
- Installer-Seeding ist bei Wiederholungsversuchen idempotenter
- native Mailheader werden gegen Zeilenumbrüche abgesichert und UTF-8-Betreffzeilen kodiert

### Security
- Installer wird nach erfolgreicher Installation dauerhaft gesperrt
- Datenbankzugriffe verwenden PDO mit deaktivierten emulierten Prepared Statements
- Runtime-Dateien unter storage/app werden nicht versioniert
- Session-ID wird bei Login und Logout rotiert
- Loginversuche werden pro E-Mail/IP-Kombination rate-limited
- Verifikations- und Reset-Tokens werden nur als SHA-256-Hash gespeichert
