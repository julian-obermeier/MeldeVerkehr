# Projektstatus

## Aktuelle Version
0.2.0-dev

## Phase
M2 – Bürgerportal / Vorgangskern

## M1 – Fundament
Abgeschlossen und auf `develop` integriert.

## M2 – umgesetzt
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
- Beobachtungsbeginn und optionales Beobachtungsende
- UTC-Speicherung und lokale Darstellung der Beobachtungszeit
- automatisch abgeleitete Beobachtungsdauer
- strukturierte Angaben zu Behinderung, Gefährdung und Sachschaden ohne automatische Rechtswertung
- versionierte Tatbestände und Tatbestandskategorien
- neutraler interner Entwurfs-Tatbestand ohne ungeprüfte Rechts-/Bußgeldwerte
- eigener Vorgangsbereich mit Liste, Statusfilter und Suche
- Suche nach Vorgangsnummer, Straße, Ort oder exaktem Kennzeichen
- adaptiver Wizard Fahrzeug → Standort → Beobachtung → Tatbestand → Review
- Browser-GPS-Übernahme
- separate Grunddaten-Review-Seite
- Pflichtprüfung fehlender Grunddaten vor M3
- gelbe Review-Hinweise mit expliziter Bestätigung
- Statusfolge bis `READY_FOR_REVIEW` und nach Bestätigung `WAITING_FOR_EVIDENCE`
- Dashboard mit echten Vorgangszahlen und letzten Vorgängen
- automatische Rücksetzung der Grunddatenprüfung bei nachträglichen Änderungen
- Integrationstests für Nummernkreis, Statusmaschine, Ownership, Fahrzeug, Standort, Suche, Zeit, Tatbestandsversionierung, Review und Dashboarddaten

## M2-Status
Der technische M2-Core ist abgeschlossen. Der Übergabepunkt zu M3 ist `WAITING_FOR_EVIDENCE`.

## Bewusst separat
- produktive, fachlich/rechtlich verifizierte Tatbestandsdaten werden getrennt gepflegt und versioniert; ungeprüfte Bußgeld- oder Rechtsangaben werden nicht in den technischen Core eingebaut.
- Serien-/Gruppenmeldungen folgen in einer späteren Ausbaustufe.

## Bekannte Einschränkungen
- Die produktive Tatbestandsdatenbank enthält bewusst noch keine ungeprüften Bußgeld- oder Rechtsangaben.
- Der aktuelle E-Mail-Transport verwendet PHP `mail()`; SMTP folgt mit dem Kommunikationsausbau.
- Beweisbilder, OCR, Anonymisierung und Beweismappe beginnen in M3.
- PWA-Offlinedaten/IndexedDB für Meldungsentwürfe folgen zusammen mit dem Beweis-/Meldeworkflow.
- WebAuthn unterstützt derzeit ES256/P-256.

## Nächster Schritt
M2-Core per CI integrieren; danach M3 – Beweissystem mit Dateiablage, Original-/Arbeitskopien, Metadaten, Hashes, Foto-Kategorien und Qualitätsprüfung.
