# Projektstatus

## Aktuelle Version
0.3.0-dev

## Phase
M3 – Beweissystem

## M1 – Fundament
Abgeschlossen und auf `develop` integriert.

## M2 – Bürgerportal / Vorgangskern
Abgeschlossen und auf `develop` integriert.

## M3 – bereits umgesetzt
- eigenes Evidence-Datenmodell mit Evidence-Items, Versionen, Metadaten und Ereignissen
- strukturierte Foto-Kategorien
- geschützte Originaldateien außerhalb des öffentlichen Webroots
- zufällige Evidence-IDs statt nutzerbestimmter Storage-Dateinamen
- SHA-256-Hash je gespeicherter Dateiversion
- tatsächliche MIME-Prüfung über `finfo`
- serverseitige Beschränkung auf JPEG, PNG und WebP
- Größenlimit von 20 MB
- Bildabmessungen über serverseitige Bildprüfung
- technische Qualitätsstufen `SUITABLE`, `LIMITED`, `RETAKE_RECOMMENDED`
- getrennte `ORIGINAL`- und `WORKING`-Varianten
- Arbeitskopien über GD mit Maximaldimension 1920 px
- Re-Encoding der Arbeitskopie statt Veränderung des Originals
- geschützter Bürger-Upload nur nach abgeschlossenem M2-Grunddaten-Review
- Ownership-Prüfung für Evidence-Zugriffe
- Evidence-Center pro Vorgang
- Mobilkamera-Unterstützung über File-Capture
- logisches Entfernen aus dem aktiven Beweissatz ohne stille Vernichtung des Originals
- Integritätsprüfung des Originals gegen gespeicherten SHA-256-Hash
- Audit-/Evidence-Ereignisse für Speicherung und Entfernung
- zusätzlicher Apache-Deny-Schutz für `storage/`
- Integrationstests für Originalspeicher, Hashintegrität, Ownership, Arbeitskopie und logisches Entfernen

## M3 noch offen
- Privacy-/Anonymisierungsgrundlage für Gesichter, fremde Kennzeichen und Redaktionsbereiche
- kategorienbasierte Vollständigkeitsprüfung
- Evidence-Review
- Beweismappen-/Exportgrundlage
- spätere OCR-/KI-Qualitäts- und Privacy-Assistenten

## Bewusst getrennt
- Originale werden nie durch Qualitäts-, Anonymisierungs- oder Kompressionsschritte überschrieben.
- Arbeits- und spätere öffentliche/versandfähige Kopien werden als eigene Varianten versioniert.
- KI-/OCR-Ausgaben werden später ausschließlich assistiv behandelt und benötigen Nutzerbestätigung.

## Bekannte Einschränkungen
- Arbeitskopien werden nur erzeugt, wenn die PHP-GD-Erweiterung verfügbar ist; das Original bleibt davon unabhängig funktionsfähig.
- Die aktuelle Qualitätsprüfung ist technisch und regelbasiert; Unschärfe/Helligkeit und motivbezogene Eignung folgen in weiteren M3-Ausbaustufen.
- Produktive Tatbestandsdaten bleiben getrennt fachlich/rechtlich zu verifizieren.
- Der aktuelle E-Mail-Transport verwendet PHP `mail()`; SMTP folgt mit dem Kommunikationsausbau.

## Nächster Schritt
M3-Core per CI integrieren; anschließend Privacy-/Anonymisierungsgrundlage und Evidence-Review/Beweismappe umsetzen.
