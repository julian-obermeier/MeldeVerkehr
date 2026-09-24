# Changelog

Alle relevanten Änderungen an MeldeVerkehr werden hier dokumentiert.

## [Unreleased]

### Added
- Beobachtungsbeginn und optionales Beobachtungsende im Vorgang
- UTC-Speicherung mit lokaler Zeitdarstellung
- automatische Ableitung der Beobachtungsdauer
- strukturierte Beobachtungsmerkmale für Behinderung, Gefährdung und Sachschaden
- separate Grunddaten-Review-Seite
- Review-Vollständigkeitsprüfung und Qualitätswarnungen
- explizite Bestätigung gelber Review-Hinweise
- sauberer M2→M3-Übergang über READY_FOR_REVIEW → WAITING_FOR_EVIDENCE
- Integrationstests für Beobachtungszeit und Review-Warnungen
- M2-Vorgangskern mit UUID und öffentlicher OWI-Jahresnummer
- Statusmaschine, Statushistorie und Vorgangstimeline
- Fahrzeug- und Standortmodell
- verschlüsselte Kennzeichen mit HMAC-Suchhash
- versionierte Tatbestände und Tatbestandskategorien
- neutrale Entwurfs-Tatbestandsversion ohne ungeprüfte Rechtsdaten
- private Vorgangsliste mit Statusfilter und Suche
- adaptive Vorgangserfassung für Fahrzeug, Standort und Tatbestand
- Browser-GPS-Übernahme
- reale Dashboard-Kennzahlen und letzte Vorgänge
- parametrisierte Router-Pfade
- M2-Integrationstests für Nummernkreis, Ownership, Suche, Status und Versionierung
- resource-basierte AuthorizationService-Grundlage mit Ownership-Prüfung
- TOTP-Zwei-Faktor-Authentifizierung nach RFC 6238
- verschlüsselte TOTP-Secrets und gehashte Recovery-Codes
- Passkeys/WebAuthn mit ES256/P-256
- dedizierte Passkey-Anmeldung
- Sicherheitsseite für TOTP- und Passkey-Verwaltung
- frische Re-Authentication für sensible Sicherheitsänderungen
- aktionsorientiertes Bürger-Dashboard mit Sicherheitsstatus
- Tests für Ownership, Secret-Verschlüsselung, TOTP-RFC-Vektor und WebAuthn-Helfer
- Admin-Systemdashboard mit Benutzer-, DB-, Queue-, Cron- und Audit-Status
- PWA-Manifest, Service Worker, Offline-Fallback und SVG-App-Icon
- sicherer Static-Cache ohne Speicherung privater Navigationsantworten
- MySQL-Integrationstest-Workflow in GitHub Actions
- Integrationstests für Migrationen, Registrierung, Login, Permissions, Rate-Limit, Audit und Queue
- rollenbezogene Basiszuweisungen für Admin-, Moderator- und Behördenrollen
- HMAC-signiertes, verkettetes Audit-System mit Integritätsprüfung
- Auditierung von Registrierung, Login, Logout, E-Mail-Verifikation und Passwort-Reset
- DB-basierte Jobqueue mit Prioritäten, Retry, Backoff und Locking
- Queue-Recovery für veraltete RUNNING-Jobs
- Cron-Registry mit Heartbeats und Laufhistorie
- CLI-Cron-Einstiegspunkt mit `queue` und `health`
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
- vollständige M2-Grunddaten wechseln jetzt zuerst zu READY_FOR_REVIEW
- WAITING_FOR_EVIDENCE wird erst nach expliziter Grunddatenbestätigung gesetzt
- nachträgliche Änderungen an bereits geprüften Grunddaten erzwingen erneut einen Review
- Bürger-Dashboard nutzt reale Vorgangsdaten statt Platzhalter
- Vorgänge wechseln nach erster Eingabe von DRAFT zu CAPTURE_IN_PROGRESS
- vollständig erfasste M2-Grunddaten wechseln bei konkretem Tatbestand zu WAITING_FOR_EVIDENCE
- Tatbestandskategorien werden als eigene Stammdaten geführt
- Passwort-Login fordert bei aktivem TOTP vor Sessionfreigabe den zweiten Faktor
- Passkey-Login wird bei aktivem TOTP ebenfalls um den zweiten Faktor ergänzt
- Dashboard zeigt Administration nur bei serverseitig bestätigter `admin.system`-Permission
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
- TOTP-Secrets werden mit AES-256-GCM verschlüsselt gespeichert
- Recovery-Codes werden ausschließlich gehasht gespeichert und nach Nutzung entfernt
- WebAuthn prüft Challenge, Origin, RP-ID-Hash, User Presence, User Verification und Signatur
- Passkey- und TOTP-Anmeldeversuche sind rate-limited
- sensible Änderungen an TOTP/Passkeys verlangen eine frische Anmeldung
- Audit-Einträge werden mit dem APP_KEY per HMAC signiert
- Audit-Metadaten speichern IP- und User-Agent-Bezug nur gehasht
- Installer wird nach erfolgreicher Installation dauerhaft gesperrt
- Datenbankzugriffe verwenden PDO mit deaktivierten emulierten Prepared Statements
- Runtime-Dateien unter storage/app werden nicht versioniert
- Session-ID wird bei Login und Logout rotiert
- Loginversuche werden pro E-Mail/IP-Kombination rate-limited
- Verifikations- und Reset-Tokens werden nur als SHA-256-Hash gespeichert
