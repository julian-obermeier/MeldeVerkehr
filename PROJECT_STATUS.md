# Projektstatus

## Aktuelle Version
0.3.0-dev

## Phase
M3 – Beweissystem

## M1 – Fundament
Abgeschlossen und auf `develop` integriert.

## M2 – Bürgerportal / Vorgangskern
Abgeschlossen und auf `develop` integriert.

## M3 – umgesetzt
- Evidence-Datenmodell mit Items, Versionen, Metadaten und Ereignissen
- strukturierte Foto-Kategorien
- geschützte Originaldateien außerhalb des öffentlichen Webroots
- serverseitige UUID-Dateinamen und SHA-256 je Dateiversion
- MIME-, Größen- und Bildabmessungsprüfung
- technische Qualitätsstufen `SUITABLE`, `LIMITED`, `RETAKE_RECOMMENDED`
- getrennte `ORIGINAL`, `WORKING` und `PUBLIC` Varianten
- GD-basierte Arbeitskopien mit Maximaldimension 1920 px
- Bürger-Evidence-Center mit Datei-/Mobilkamera-Upload
- Ownership- und Statusprüfung für private Nachweise
- logisches Entfernen ohne stille Vernichtung von Originalen
- Original- und PUBLIC-Integritätsprüfung
- manuelle Privacy-Bereiche mit normalisierten Koordinaten
- Privacy-Typen für Gesichter, fremde Kennzeichen, Personen und sensible Details
- interaktive Rechteckauswahl auf geschützter Arbeitskopie
- versionierte Privacy-Snapshots
- separate redigierte PUBLIC-Kopien mit schwarzer Redaktion
- Provenienz PUBLIC → WORKING/ORIGINAL inklusive Quellhash und Regionshash
- geschützte, nicht cachebare Evidence-Vorschau
- kategorienbasierter Evidence-Review
- blockierende Privacy-/Integritätsprüfungen
- nicht blockierende Qualitäts- und Kategoriehinweise mit expliziter Bestätigung
- versionierte Case-Evidence-Reviews
- revisionssicher eingefrorene Beweismappen
- Manifest mit SHA-256 und Snapshot der verwendeten PUBLIC-Versionen
- eingefrorene Evidence-Pakete sperren nachträgliche Evidence-/Privacy-Änderungen
- Case-Status unterscheidet M2-Grunddatenreview und eingefrorene M3-Beweismappe anhand des Paketstands
- Apache-Deny-Schutz für Runtime-Storage
- Integrationstests für Storage, Derivate, Privacy, Integrität, Ownership, Review und Package-Freeze

## M3-Status
Der technische M3-Beweiskern ist abgeschlossen. Ein Vorgang verlässt M3 mit einer eingefrorenen, gehashten Beweismappe und dem Status `READY_FOR_REVIEW`.

## Bewusst getrennt
- Originale werden niemals durch Qualitäts-, Privacy- oder Kompressionsschritte überschrieben.
- Privacy-/Versandkopien sind eigene versionierte Varianten.
- fehlende empfohlene Foto-Kategorien sind Hinweise, keine automatische rechtliche Wertung.
- OCR/KI wird später ausschließlich assistiv ergänzt und benötigt Nutzerbestätigung.

## Bekannte Einschränkungen
- Arbeits- und Privacy-Kopien benötigen die PHP-GD-Erweiterung.
- die aktuelle technische Qualitätsprüfung bewertet Auflösung/Dateigröße; Unschärfe, Helligkeit und Motiverkennung folgen in intelligenten Assistenzmodulen.
- produktive Tatbestandsdaten bleiben getrennt fachlich/rechtlich zu verifizieren.
- der aktuelle E-Mail-Transport verwendet PHP `mail()`; SMTP folgt mit dem Kommunikationsausbau.

## Nächster Schritt
M3 per CI integrieren; anschließend M4 – Sachverhalt/Witness-Report, neutraler Beschreibungsgenerator, Qualitätscheck und finaler Vorgangsreview.
