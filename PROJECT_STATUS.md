# Projektstatus

## Aktuelle Version
0.13.0-dev

## Phase
M13 – Vorgangsversionierung & Lifecycle

## Integrierter Stand bis M12
Fundament, Vorgangskern, Beweissystem, Zeugenbericht/Final Review, Behördenrouting/Dispatch, Behördenkommunikation, intelligente Assistenz, private Karten/Analytics, Community, Suche/Dokumentcenter/Notifications/Exporte/Retention, Behördenportal/API sowie Produktionshärtung und Release-Operations sind auf `develop` integriert.

## M13 – auf Feature-Branch umgesetzt
- neue Migration `20260924_023_create_case_lifecycle_tables.php`
- `case_versions` als unveränderliche, fortlaufende Vorgangsversionen
- kanonische JSON-Snapshots mit SHA-256-Integritätswert
- automatische Baseline-Version bei Vorgangsanlage
- automatische Versionierung bei Änderungen an Fahrzeug, Standort, Beobachtung, Tatbestand und bestätigtem Grunddaten-Review
- revisionssichere Nachträge als append-only Datensätze
- Korrekturworkflow mit eingefrorenem Vorher-Stand, getrennten bisherigen/korrigierten Angaben, Begründung und Abschlussvermerk
- Statusübergänge nach `CORRECTION_PENDING` aus relevanten Versand-/Behördenstatus
- Rücknahmeworkflow mit Vorher-Snapshot und `WITHDRAWAL_PENDING`
- Abschluss von Rücknahmen nach `CLOSED` inklusive Abschlussakte
- regulärer Abschlussworkflow aus fachlich zulässigen Status
- Abschlussakte mit Snapshot, Versionsmetadaten, Timeline und SHA-256
- geschützter JSON-Export einer Abschlussakte; Download nur nach erfolgreicher Hashprüfung
- Archivierung `CLOSED → ARCHIVED` mit eigener Archivversion
- Lifecycle-UI direkt aus der Vorgangsdetailansicht erreichbar
- Abschlussakten werden im Dokumentencenter geführt
- Ownership-/Permission-Prüfung für alle Lifecycle-Operationen
- Timeline- und manipulationsgeschütztes Auditlogging für kritische Aktionen
- Integrationstests für Versionierung, Nachträge, Korrekturen, Rücknahmen, Abschluss, Export, Ownership und Archivierung

## Synchronisationshinweis Zielserver
Der Entwicklungsstand auf `develop` enthält bereits die Migrationen `020`, `021` und `022`. Ein Server, der nur bis Migration `019` anzeigt, ist nicht auf dem aktuellen Repository-Stand. Vor Funktionstests muss deshalb zuerst der aktuelle Entwicklungsstand sauber deployed und anschließend der Migrationslauf ausgeführt werden. M13 ergänzt danach Migration `023`.

## Noch offen vor 1.0.0
- Moderation vollständig ausbauen: Appeals, Abuse Flags, Eskalationsworkflow sowie Anti-Spam-/Bot-/Mass-Report-Erkennung
- Reputation erweitern: Achievements, Daily Limits, Diminishing Returns, Anomalieerkennung und Admin-Korrekturen
- Authority-Inquiry-End-to-End-Workflow bis Bürgerantwort und behördlicher Prüfung abschließen
- Community um Follower, Favoriten und Gruppenchat ergänzen
- Notification-Präferenzen als vollständige UI bereitstellen und mit E-Mail/Push verbinden
- kontrollierte IndexedDB-Queue für Offline-Evidence evaluieren/umsetzen
- Inline-CSS/JS aus Legacy-Views entfernen und CSP anschließend ohne `unsafe-inline` betreiben
- produktive Retention-Fristen fachlich/rechtlich festlegen und Lösch-/Anonymisierungsengine aktivieren
- vollständigen Deploy-, Backup-, Restore- und Rollback-Test auf dem ALL-INKL-Zielsystem durchführen
- alle CI-Gates vor Stable grün

## Sicherheitsprinzipien
- Originalbeweise bleiben außerhalb des öffentlichen Webroots.
- Vorgangssnapshots überschreiben historische Aktenstände nicht.
- Korrekturen werden als eigene Datensätze dokumentiert; ursprüngliche Angaben bleiben nachvollziehbar.
- Abschlussakten und Vorgangsversionen tragen SHA-256-Integritätswerte.
- Abschlussakten werden vor Download erneut gegen ihren gespeicherten Hash geprüft.
- kritische Lifecycle-Aktionen werden in Timeline und Auditlog protokolliert.
- private Navigationen werden durch den Service Worker nicht persistiert.
- Production darf nicht mit `APP_DEBUG=true` als release-ready gelten.

## Release-Status
`0.13.0-dev` ist noch kein Stable-Release. Nach Integration von M13 müssen die verbleibenden 1.0-Punkte umgesetzt, CI vollständig ausgeführt und das Release-/Restore-Runbook auf der Zielumgebung erfolgreich durchgespielt werden.

Siehe `docs/RELEASE_RUNBOOK.md`.
