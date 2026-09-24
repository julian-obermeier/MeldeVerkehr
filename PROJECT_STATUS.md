# Projektstatus

## Aktuelle Version
0.12.0-rc1

## Phase
M12 – Produktionshärtung & Release Candidate

## M1–M11
Fundament, Vorgangskern, Beweissystem, Zeugenbericht/Final Review, Behördenrouting/Dispatch, Behördenkommunikation, intelligente Assistenz, private Karten/Analytics, Community, Suche/Dokumentcenter/Notifications/Exporte/Retention sowie Behördenportal/API sind abgeschlossen und auf develop integriert.

## M12 – umgesetzt
- Release-Operations-Datenmodell für Backups, Updates und generisches Request-Rate-Limiting
- idempotentes Release-Repository, damit der Updater nicht von seiner eigenen Migration abhängt
- verschlüsselte Datenbank-Backups außerhalb des Webroots
- verschlüsselte Runtime-Dateisicherung für storage/app
- SHA-256-Integrität für Datenbank- und Runtime-Payloads
- manifestbasierte Backup-Verifikation
- Restore mit ausdrücklicher Bestätigung und vollständiger Verifikation vor Schreibzugriff
- Release-/Rate-Limit-Tabellen werden nicht in ihre eigenen Restores zurückgespielt
- konfigurierbare sichere Dateigrößenobergrenze; kein stilles Teilbackup
- Maintenance-Modus mit persistiertem Grund und HTTP 503
- Update-Workflow: Preflight → Maintenance → Backup → Verify → Migration → Versionsstatus → Audit → Maintenance off
- fehlgeschlagenes Update lässt Maintenance absichtlich aktiv
- CLI maintenance.php für Status, Readiness, Backup, Verify, Restore und Update
- Admin-Releasecenter unter /admin/system/update
- Restore bleibt bewusst CLI-only
- Release-Readiness-Checks für DB, Storage, Audit, Production-Debug, Install-Lock, PWA, Backups, Queue und temporäre Exporte
- zentrale Security-Header mit CSP, HSTS bei HTTPS, Frame-/MIME-/Referrer-/Permissions-Policy
- maintenance.php wird durch Apache explizit vom Webzugriff ausgeschlossen
- generisches serverseitiges Request-Rate-Limiting
- Authority API v1 ist burst-limitiert und liefert 429 + Retry-After
- PWA-Offline-Entwürfe via IndexedDB für den Kern-Wizard
- serverVersion/localVersion-Konfliktmodell
- lokale Entwürfe werden niemals still auf Serverdaten angewendet
- explizite Aktionen „lokal übernehmen“ oder „verwerfen“
- Offline-Submit wird abgefangen und klar als nur lokal gespeichert gekennzeichnet
- Service Worker speichert keine privaten Navigationsantworten
- PWA-Shortcuts für Vorgänge, Karte und Benachrichtigungen
- Accessibility-Basis mit Skip-Link, :focus-visible und ARIA-Status/Alert
- gemeinsame Accessibility-Helfer auf Bürger-, Authority- und Operations-Flächen
- Dashboard-Version kommt dynamisch aus VERSION
- M12-Regressionsblock für Backup, Verschlüsselung, Verify, Restore-Guard, Rate-Limit, Maintenance, Update-Preflight, Readiness, PWA und Security-Invarianten

## Sicherheitsprinzipien
- Backups liegen außerhalb von public und speichern DB-/Runtime-Payloads verschlüsselt.
- jedes Backup wird vor Restore vollständig gegen Manifest und SHA-256 geprüft.
- Restore ist destruktiv und deshalb nur per CLI mit expliziter Bestätigung möglich.
- fehlgeschlagene Updates deaktivieren Maintenance nicht automatisch.
- Release-Betrieb schreibt keine Secrets in Git.
- Production darf nicht mit APP_DEBUG=true release-ready sein.
- private Navigationen werden durch den Service Worker nie persistiert.
- Offline-Entwürfe überschreiben keine neueren Serverstände ohne Nutzeraktion.
- Authority-API-Burstschutz arbeitet serverseitig und speichert nur HMAC-Schlüssel.
- Security-Header verbieten Fremdframes und externe Standardressourcen; bestehende Inline-UI bleibt vorerst CSP-kompatibel.

## Bekannte Einschränkungen vor Stable
- Backup-Verschlüsselung arbeitet dateiweise und begrenzt Einzeldateien sowie DB-Dump über BACKUP_MAX_FILE_BYTES; sehr große Installationen benötigen später Streaming-Backup.
- Restore stellt die im Manifest enthaltenen Runtime-Dateien wieder her, entfernt aber bewusst keine zusätzlichen neueren Runtime-Dateien automatisch.
- CSP erlaubt aktuell noch Inline-Scripts/Styles, weil bestehende Legacy-Views teilweise inline arbeiten; externe Origins bleiben dennoch gesperrt.
- Offline-Modus speichert Form-Entwürfe, versendet aber bewusst nichts automatisch nach Wiederverbindung.
- Evidence-Dateiuploads werden offline nicht gepuffert.
- Authority-API nutzt weiterhin statische, gehashte Bearer-Tokens statt OAuth2/OIDC.
- produktive Aufbewahrungsfristen müssen weiterhin fachlich/rechtlich festgelegt werden.

## Release-Status
0.12.0-rc1 ist der erste Release Candidate. Vor 1.0.0 müssen alle CI-Gates grün sein und das Deploy-/Restore-Runbook einmal auf der Zielumgebung durchgespielt werden.

Siehe docs/RELEASE_RUNBOOK.md.
