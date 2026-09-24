# Projektstatus

## Aktuelle Version
0.2.0-dev

## Phase
M2 – Bürgerportal / Vorgangskern

## M1 – Fundament
Abgeschlossen und auf `develop` integriert.

## In M2 umgesetzt
- parametrisierte Router-Pfade wie `/cases/{id}`
- Vorgangstabelle mit UUID und öffentlicher Nummer `OWI-YYYY-NNNNNN`
- atomarer Jahresnummernkreis
- vollständige Statuskonstanten und explizite Statusmaschine
- Statushistorie und Vorgangstimeline
- serverseitige Ownership-Prüfung für private Vorgänge
- verschlüsselte Kennzeichenspeicherung
- geheimer HMAC-Suchhash für Kennzeichen
- Fahrzeugdaten mit festen Fahrzeugtypen
- Standortdaten mit GPS oder Adresse
- strukturierter Verkehrsraum und PUBLIC/PRIVATE/UNCLEAR
- versionierte Tatbestände
- eigene Tatbestandskategorien als Stammdaten
- neutraler interner Entwurfs-Tatbestand ohne ungeprüfte Rechts-/Bußgeldwerte
- eigener Vorgangsbereich mit Liste, Statusfilter und Suche
- Suche nach Vorgangsnummer, Straße, Ort oder exaktem Kennzeichen
- adaptive Erfassung Fahrzeug → Standort → Tatbestand
- Browser-GPS-Übernahme
- Dashboard mit echten Vorgangszahlen und letzten Vorgängen
- automatische Fortschrittslogik DRAFT → CAPTURE_IN_PROGRESS → WAITING_FOR_EVIDENCE
- Integrationstests für Nummernkreis, Statusmaschine, Ownership, Fahrzeug, Standort, Suche, Tatbestandsversionierung und Dashboarddaten

## M2 noch offen
- Tatzeit-/Beobachtungszeit-UI
- weitere fachlich verifizierte Tatbestandsdaten
- Feinschliff des mobilen Wizards
- Review-Schritt als Vorbereitung auf M3
- ggf. Serien-/Gruppen-Grundstruktur erst in späterer Ausbaustufe

## Bekannte Einschränkungen
- Die produktive Tatbestandsdatenbank enthält bewusst noch keine ungeprüften Bußgeld- oder Rechtsangaben.
- Der aktuelle E-Mail-Transport verwendet PHP `mail()`; SMTP folgt mit dem Kommunikationsausbau.
- Beweisbilder, OCR, Anonymisierung und Beweismappe beginnen in M3.
- PWA-Offlinedaten/IndexedDB für Meldungsentwürfe folgen zusammen mit dem erweiterten Meldeworkflow.
- WebAuthn unterstützt derzeit ES256/P-256.

## Nächster Schritt
M2-Core per CI validieren und integrieren; anschließend Tatzeit/Review fertigstellen und M3 – Beweissystem beginnen.
