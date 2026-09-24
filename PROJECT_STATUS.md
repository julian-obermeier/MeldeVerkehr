# Projektstatus

## Aktuelle Version
0.10.0-dev

## Phase
M10 – Suche, Dokumentcenter, Benachrichtigungen, Exporte und Betriebsqualität

## M1–M9
Fundament, Vorgangskern, Beweissystem, Zeugenbericht/finaler Review, Behördenrouting/Dispatch, Behördenkommunikation, intelligente Assistenz, private Karten/Analytics sowie Community sind abgeschlossen und auf `develop` integriert.

## M10 – umgesetzt
- globale Suche ausschließlich in eigenen Vorgängen
- Suche nach Vorgangsnummer, Straße, Ort, Tatbestand, Kategorie, Behördenname und exaktem Kennzeichen
- Filter nach Status, Ort, Behörde und Beobachtungszeitraum
- exakte Kennzeichensuche nutzt denselben HMAC-Suchschlüssel wie der Vorgangskern
- Suchergebnisse können keine fremden Vorgänge liefern
- gespeicherte Filter pro Nutzer
- Live-Zähler je gespeichertem Filter
- fallübergreifendes Dokumentcenter
- Dokumentcenter aggregiert Beweismappen, Zeugenberichte, Versandpakete, Behördenanhänge, kommunale Reports und Exporte
- vorhandene Artefakte werden referenziert statt dupliziert
- In-App-Benachrichtigungscenter
- verschlüsselte Notification-Bodies
- Prioritäten LOW/NORMAL/HIGH/URGENT
- idempotente Unique-Keys gegen doppelte Notifications
- automatische Ableitung aus offenen Aufgaben, Fristen und kritischen Vorgangsstatus
- gelesen/ungelesen sowie „alle gelesen“
- Notification-Präferenzen auf Event-Ebene vorbereitet
- CSV- und JSON-Export eigener Vorgänge
- sensible Kennzeichenfelder sind im Export standardmäßig ausgeschlossen
- Kennzeichen werden nur nach ausdrücklichem Opt-in in einen konkreten Export aufgenommen
- Exportdateien liegen außerhalb des Webroots
- Exportdateien werden vor Download per SHA-256 geprüft
- Exporte laufen standardmäßig nach 7 Tagen ab
- Export-Downloads sind strikt an den Eigentümer gebunden
- Retention-Planung für temporäre Exportartefakte
- geschlossene Fallakten werden standardmäßig nicht automatisch gelöscht
- konfigurierbare Fall-Retention ist vorbereitet, aber default deaktiviert
- Account-Löschvorschau trennt sofort technisch löschbare Daten von prüfpflichtigen Fall-/Kommunikationsdaten
- erweiterte Admin-Diagnostik für Datenbank, Queue, Cron, Mail-Quarantäne, Dispatch-Fehler, Storage, Audit, Retention und Exporte
- Shared-Hosting-Cronjobs `notifications`, `export-cleanup` und `retention-plan`
- Dashboard-Shortcuts zu Suche, Dokumenten, Notifications und Aufbewahrung
- ungelesener Notification-Zähler im Dashboard
- Integrationstests für Suche, Ownership, Filter, Dokumentcenter, Exporte, Notifications, Retention und Diagnostik

## Datenschutz- und Sicherheitsprinzipien
- globale Suche ist immer auf `cases.user_id` des angemeldeten Nutzers begrenzt.
- gespeicherte Filter sind private Nutzerressourcen.
- exakte Kennzeichensuche arbeitet über HMAC; es gibt keinen öffentlichen Kennzeichenindex.
- Exportdateien sind standardmäßig ohne Kennzeichen.
- sensible Exportfelder benötigen ausdrückliches Opt-in pro Export.
- Exportdateien liegen im geschützten Runtime-Storage und werden per SHA-256 verifiziert.
- Export-Downloads prüfen die Eigentümerschaft.
- Notification-Bodies werden verschlüsselt gespeichert.
- Notification-Ziel-URLs müssen lokale Root-relative URLs sein.
- Dokumentcenter aggregiert nur Artefakte aus eigenen Vorgängen bzw. eigenen Exporten.
- Retention plant nur Kandidaten; automatische Falllöschung bleibt deaktiviert.
- konkrete produktive Aufbewahrungsfristen werden nicht als Rechtsannahme fest verdrahtet.
- Admin-Diagnostik verändert keine Daten.

## Bekannte Einschränkungen
- verschlüsselte Behördenkorrespondenz wird bewusst nicht per Datenbank-Volltext durchsucht.
- Dokumentcenter erzeugt noch keine neuen PDF-Dateien; es bündelt vorhandene Artefakte und Exporte.
- Notification-Präferenzen sind service-/datenmodellseitig vorhanden, aber noch nicht als eigene Einstellungsseite ausgebaut.
- Push-/E-Mail-Auslieferung nutzt noch nicht dieselben Präferenzdatensätze automatisiert.
- Fall-Retention braucht vor produktiver Aktivierung konkrete fachliche/rechtliche Fristen.
- Account-Löschung ist als Vorschau/Planung vorbereitet, nicht als unkontrollierte Ein-Klick-Harddelete-Funktion.

## Nächster Schritt
M10 per CI integrieren; anschließend M11 – API-/Behördenportal-Grundlage, strukturierte Importe/Exporte, Authority-RBAC und B2B-Betriebsfunktionen.
