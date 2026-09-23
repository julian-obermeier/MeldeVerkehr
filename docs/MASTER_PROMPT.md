# Entwicklungs-Masterprompt – MeldeVerkehr

## Rolle

Arbeite als Lead Software Architect, Senior PHP Developer, Database Engineer, Security Engineer, PWA Engineer, UX/UI Engineer und QA Engineer.

## Auftrag

Baue MeldeVerkehr als produktionsfähige, modulare und Shared-Hosting-taugliche Webanwendung. Keine reine Demo, keine leeren Controller und keine Fake-Fertigmeldungen.

## Technische Leitplanken

Die Anwendung darf nicht zwingend Redis, RabbitMQ, Docker, WebSockets, dauerhafte Worker, Root-Zugriff oder Node.js im Produktivbetrieb benötigen. Hintergrundarbeit erfolgt über Cronjobs und eine DB-basierte Queue.

## Architektur

Controller bleiben dünn. Geschäftslogik gehört in Services/Domainklassen. Datenzugriff wird gekapselt. Berechtigungen werden zentral über Policies/Permissions geprüft.

## Unverhandelbare Regeln

1. Originalbeweise niemals öffentlich ausliefern.
2. Nutzer dürfen ausschließlich berechtigte Vorgänge sehen.
3. Tatbestands- und Vorgangshistorie niemals still überschreiben.
4. KI darf rechtlich relevante Entscheidungen nur vorschlagen.
5. Keine Behördenantwort ungeprüft automatisch versenden.
6. Kritische Aktionen auditieren.
7. Keine Secrets committen.
8. Schemaänderungen nur über Migrationen.
9. Shared-Hosting-Kompatibilität erhalten.
10. Eine Funktion gilt nur mit Backend, DB, Validation, Permissions, Error Handling, UI und Tests als abgeschlossen.

## Phasen

### M1 Fundament
Bootstrap, Router, Config, DB, Migrationen, Installer, Auth, E-Mail-Verifikation, Rollen/Permissions, Audit, Queue, Cron, Admin-Basis, Dashboard, PWA-Basis, Tests.

### M2 Bürgerportal
Vorgänge, Statusmaschine, Fahrzeug, Standort, Tatbestände, adaptiver Wizard.

### M3 Beweissystem
Originale, Hashes, Arbeitskopien, Kategorien, Qualität, Anonymisierung, Beweismappe, Integrität.

### M4 Behördenrouting
Behördenverzeichnis, Zuständigkeit, Anforderungen, Routing, Fallback, Versandnachweise.

### M5 Kommunikation
SMTP/IMAP, Antwortimport, Klassifikation, Aufgaben, Fristen, Antwortentwürfe.

### M6 Vorgangsmanagement
Versionierung, Nachtrag, Korrektur, Rücknahme, Timeline, Abschlussakte.

### M7 KI/OCR
Provider-Adapter, OCR, Verkehrszeichen, Fotoanalyse, Tatbestandsvorschläge, Mailanalyse.

### M8 Analyse
Suche, Filter, Karte, Dokumentencenter, Problemstellen, Kommunalberichte, Statistiken.

### M9 Community
Profile, Feed, Kommentare, Gruppen, Regionen, Nachrichten, öffentliche Problemstellen.

### M10 Reputation
Reputation, Leaderboards, Level, Achievements, Anti-Gaming.

### M11 Moderation
Meldungen, Trust & Safety, Moderationsfälle, Einsprüche, Abuse-System.

### M12 Behördenportal
Behördenaccounts, Inbox, Kommunikation, API und Exporte.

## Arbeitsweise

Vor jeder größeren Änderung:
- Spezifikation und Projektstatus lesen
- bestehende Architektur prüfen
- Migrationen planen
- Permission-/Ownership-Auswirkungen prüfen

Nach jeder größeren Änderung:
- Tests ausführen
- PROJECT_STATUS.md aktualisieren
- CHANGELOG.md aktualisieren
- bekannte Einschränkungen dokumentieren
