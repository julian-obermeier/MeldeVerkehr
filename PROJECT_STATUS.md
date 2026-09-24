# Projektstatus

## Aktuelle Version
0.17.0-dev

## Phase
M17 – Community Social

## Integrierter Stand bis M13
Fundament, Vorgangskern, Beweissystem, Zeugenbericht/Final Review, Behördenrouting/Dispatch, Behördenkommunikation, intelligente Assistenz, private Karten/Analytics, Community, Suche/Dokumentcenter/Notifications/Exporte/Retention, Behördenportal/API Produktionshärtung/Release-Operations sowie M13-Vorgangsversionierung und Lifecycle sind auf `develop` integriert.

## M13 – in `develop` integriert
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
Der Entwicklungsstand auf `develop` enthält bereits die Migrationen `020`, `021` und `022`. Ein Server, der nur bis Migration `019` anzeigt, ist nicht auf dem aktuellen Repository-Stand. Vor Funktionstests muss deshalb zuerst der aktuelle Entwicklungsstand sauber deployed und anschließend der Migrationslauf ausgeführt werden. M13 ergänzt Migration `023`; M14 Migration `024`; M15 Migration `025`; M16 Migration `026`; M17 Migration `027`.

## M14 – in `develop` integriert
- Appeals/Einsprüche für HIDE, WARN und RESTRICT
- gespeicherte Vorher-/Nachher-Snapshots für reversible Moderationsmaßnahmen
- Abuse Flags und transparente Risikosignale
- Reporter-Risikoscore in der Moderationsqueue
- Eskalationsworkflow mit Prioritäten und Senior-Abschluss
- zeitlich begrenzte Posting-, Messaging- und Reporting-Restriktionen
- Duplicate-Report-Schutz
- Duplicate-Content-, Velocity-, Bot-Burst- und Mass-Report-Erkennung
- automatische Signale dienen ausschließlich als Prüfhinweise; endgültige Maßnahmen bleiben moderationsgesteuert
- End-to-End-Tests für Einspruch, Wiederherstellung, Eskalation, Spam und Restriktion

## M15 – auf Feature-Branch umgesetzt
- Achievements mit transparentem Fortschritt
- Tageslimits je Reputationskategorie
- Diminishing Returns nach täglicher Eventanzahl
- append-only Event-Details für angeforderte/effektive Punkte und Multiplikatoren
- Anomalieerkennung für Velocity, Burst-Muster und Tagescaps
- Admin-Korrekturen als separate, auditierbare Reputationsevents
- Admin-Oberfläche für Regeln, Anomalien und Korrekturen
- Integrationstests für Governance-Regeln

## M16 – auf Feature-Branch umgesetzt
- strukturierte Behördenanfragen werden Bürgern im jeweiligen Vorgang angezeigt
- Bürgerantworten werden verschlüsselt, SHA-256-geprüft und versioniert gespeichert
- Statusfluss OPEN/REVISION_REQUIRED → AWAITING_REVIEW → CLOSED
- Behörde kann Antworten annehmen oder mit Prüfvermerk zur Überarbeitung zurückgeben
- zugehörige Aufgaben werden bei Einreichung erledigt und bei Überarbeitungsbedarf wieder geöffnet
- getrennte Bürger- und Behördenoberflächen
- Auditlogging für Einreichung und behördliche Prüfung
- neue Migration `20260924_026_create_authority_inquiry_response_tables.php`

## M17 – auf Feature-Branch umgesetzt
- Follower-Beziehungen zwischen Community-Profilen inklusive Follow/Unfollow und Netzwerkansicht
- Follower-/Following-Zähler auf Profilen
- Blockierungen verhindern neue Follow-Beziehungen und filtern Netzwerklisten
- Favoriten für Community-Beiträge inklusive eigener Favoritenansicht
- Favoritenaktionen direkt im Feed und in der Beitragsdetailansicht
- Gruppenchat ausschließlich für aktive Gruppenmitglieder
- Gruppennachrichten verschlüsselt gespeichert und per SHA-256 auf Integrität geprüft
- Gruppenchat nutzt bestehende Messaging-Berechtigung und Anti-Spam-/Bot-Signale
- Auditlogging für Follow-, Favoriten- und Gruppenchat-Aktionen
- Integrationstests für Follow, Favoriten und verschlüsselten Gruppenchat
- neue Migration `20260924_027_create_community_social_tables.php`

## Noch offen vor 1.0.0
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
`0.17.0-dev` ist noch kein Stable-Release. Nach Integration von M17 müssen die verbleibenden 1.0-Punkte umgesetzt, CI vollständig ausgeführt und das Release-/Restore-Runbook auf der Zielumgebung erfolgreich durchgespielt werden.

Siehe `docs/RELEASE_RUNBOOK.md`.
