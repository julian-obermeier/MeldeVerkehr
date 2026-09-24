# Projektstatus

## Aktuelle Version
0.7.0-dev

## Phase
M7 – Intelligente Assistenz

## M1–M6
Fundament, Vorgangskern, Beweissystem, Zeugenbericht/finaler Review, Behördenrouting/Dispatch und Behördenkommunikation sind abgeschlossen und auf `develop` integriert.

## M7 – umgesetzt
- lokale deterministische Fotoqualitätsanalyse ohne externen Dienst
- Metriken für Auflösung, mittlere Helligkeit, Helligkeitskontrast und lokale Schärfe
- technische Einstufungen `SUITABLE`, `LIMITED`, `RETAKE_RECOMMENDED`
- versionierte Speicherung der Qualitätsmetriken je Evidence-Item
- lokale Qualitätsanalyse aktualisiert ausschließlich den technischen Evidence-Qualitätsstatus
- provider-neutrale Vision/OCR-Schnittstelle
- sicherer Default `ASSIST_PROVIDER=disabled`
- keine externe Bildübertragung ohne explizite Konfiguration
- optionaler HTTPS-JSON-Provider
- externer Provider akzeptiert nur HTTPS ohne eingebettete Zugangsdaten
- Provider-Ziel darf nicht auf private/reservierte IP-Adressen auflösen
- validierte DNS-Auflösung wird für den Request gepinnt
- Uploadlimit für externe Analyse 12 MB
- Provider-Antwortlimit 2 MB
- Kennzeichen-OCR als verschlüsselter Vorschlag mit Confidence und optionaler Bildregion
- Verkehrszeichen- und Zusatzzeichen-Vorschläge
- Tatbestandsvorschläge ausschließlich auf Basis zuvor bestätigter Schild-/Zusatzzeichen-Hinweise
- Tatbestandsvorschläge müssen auf lokal vorhandene, versionierte Tatbestände verweisen
- Vorschlagsdaten werden verschlüsselt gespeichert
- Vorschläge besitzen getrennte Zustände `PENDING`, `CONFIRMED`, `REJECTED`, `APPLIED`
- Bestätigen allein verändert keine Vorgangsdaten
- Kennzeichen/Tatbestand werden erst über einen separaten Übernahme-Schritt geändert
- Übernahme rechtlich relevanter Daten erzwingt erneut den Grunddaten-Review
- jeder externe Assistenzlauf protokolliert Provider, Zweck, Input-Hash, Output-Hash, Status und minimierte Metadaten
- Provider-Rohmetadaten werden nicht vollständig persistiert; nur Schlüssel/technische Zusammenfassung
- Assistenzcenter im Bürgerportal
- Providerstatus, lokale Qualitätsmetriken, Confidence, Vorschläge und Run-Historie sichtbar
- explizites Bestätigen/Verwerfen von Vorschlägen
- Integrationstests mit lokalem Fake-Provider ohne externe Netzwerkzugriffe

## M7-Status
Der technische Assistenzkern ist abgeschlossen. Intelligente Funktionen sind strikt assistiv: Kein OCR-, Vision-, Schild- oder Tatbestandsvorschlag verändert rechtlich relevante Vorgangsdaten ohne separate Nutzeraktion.

## Sicherheitsprinzipien
- externe Vision/OCR ist standardmäßig deaktiviert.
- für externe Analyse wird bevorzugt die getrennte WORKING-Kopie statt des geschützten Originals verwendet.
- die Analysequelle wird vor Übertragung per SHA-256 geprüft.
- Providerantworten gelten als untrusted input und werden normalisiert/whitelisted.
- Kennzeichen-, Schild- und Tatbestandsvorschläge werden verschlüsselt gespeichert.
- Tatbestandsvorschläge brauchen bestätigte Signalgrundlagen.
- ein externer Vorschlag ersetzt weder Nutzerbestätigung noch behördliche Würdigung.
- lokale Qualitätsmetriken sind technische Heuristiken und keine Aussage über rechtliche Beweiskraft.

## Bekannte Einschränkungen
- die lokale Schärfemetrik ist eine technische Laplace-Heuristik und keine motivbezogene Bildbewertung.
- konkrete Vision/OCR-Qualität hängt vom später konfigurierten Provider ab.
- produktive Tatbestandsdaten müssen weiterhin fachlich/rechtlich gepflegt werden.
- automatische Verkehrszeichenlogik für Zeiträume/Ausnahmen wird in einer späteren Fachlogik-Ausbaustufe vertieft.
- es gibt bewusst keine automatische Übernahme eines KI-Ergebnisses.

## Nächster Schritt
M7 per CI integrieren; danach M8 – Karten, persönliche Problemstellen, Analytics und strukturierte kommunale Problemberichte.
