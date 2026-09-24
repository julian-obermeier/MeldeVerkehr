# Projektstatus

## Aktuelle Version
0.4.0-dev

## Phase
M4 – Sachverhalt, Zeugenbericht und finaler Qualitätsreview

## M1 – Fundament
Abgeschlossen und auf `develop` integriert.

## M2 – Bürgerportal / Vorgangskern
Abgeschlossen und auf `develop` integriert.

## M3 – Beweissystem
Abgeschlossen und auf `develop` integriert.

## M4 – umgesetzt
- strukturierte eigene Beobachtung mit separaten Feldern für Wahrnehmung, Auswirkung und Kontext
- unveränderliche Versionierung eigener Beobachtungen
- feldseitige Verschlüsselung sensibler Freitexte
- deterministischer neutraler Sachverhaltgenerator `deterministic-de-v1`
- Generator verwendet ausschließlich bestätigte Vorgangsdaten und eigene Nutzerangaben
- ausdrücklicher Hinweis, dass die behördliche Würdigung unabhängig erfolgt
- bearbeitbarer Sachverhalt mit eigener Versionshistorie
- verschlüsselte Speicherung generierter und bearbeiteter Sachverhaltstexte
- versionierte Zeugenbericht-Snapshots
- Snapshot bindet Fahrzeug, Standort, Beobachtungszeit, Tatbestand, eigene Beobachtung, Sachverhalt und eingefrorene Beweismappe
- SHA-256 über den kanonischen entschlüsselten Zeugenbericht-Snapshot
- verschlüsselte Speicherung des Zeugenbericht-Snapshots
- elektronische Nutzererklärung mit Versionsbezug und Zeitstempel
- minimierte Audit-Metadaten ausschließlich als HMAC-/Hashwerte für IP, User-Agent und Sessionbezug
- bestätigter Zeugenbericht wird gegen den aktuellen Aktenstand geprüft
- nachträgliche neue Beobachtungs-/Narrativversionen machen alte Berichtsversionen nicht still aktuell
- finaler serverseitiger Qualitätsreview mit Rot/Gelb/Grün
- rote Punkte blockieren den Versandstatus
- gelbe Hinweise müssen ausdrücklich bestätigt werden
- grüne Punkte dokumentieren technisch/faktisch vollständige Bereiche
- Integritätsprüfung des eingefrorenen Evidence-Manifests
- Integritätsprüfung des aktuellen bestätigten Zeugenbericht-Snapshots
- Evidence-Hinweise werden in den finalen Review übernommen
- bestätigter finaler Review erzeugt versionierten Qualitäts-Snapshot
- Statusübergang `READY_FOR_REVIEW` → `READY_FOR_SUBMISSION`
- M4-Daten werden nach Abschluss nur noch lesbar dargestellt
- vollständige Bürgeroberfläche für Beobachtung, Sachverhalt, Zeugenbericht, Erklärung und finalen Review
- Integrationstests bis zur Versandbereitschaft

## M4-Status
Der technische M4-Kern ist abgeschlossen. Ein Vorgang verlässt M4 mit bestätigtem Zeugenbericht, elektronischer Erklärung, finalem Qualitätsreview und dem Status `READY_FOR_SUBMISSION`.

## Bewusst getrennt
- der Qualitätsreview beurteilt technische/faktische Vollständigkeit, nicht die spätere behördliche Entscheidung.
- der neutrale Beschreibungsgenerator ist deterministisch und enthält keine eigenständige KI-Rechtsbewertung.
- Behördenzuständigkeit, Versandkanal, Zustellnachweis und Rückantworten folgen in M5.
- produktive Tatbestands- und Rechtsdaten werden weiterhin separat fachlich geprüft und versioniert.

## Bekannte Einschränkungen
- zusätzliche Zeugen/Einladungslinks sind noch nicht umgesetzt.
- OCR/KI-Assistenten für Foto-, Schild- und Tatbestandserkennung folgen in späteren Ausbaustufen.
- der aktuelle E-Mail-Transport verwendet PHP `mail()`; SMTP/IMAP werden mit M5 ausgebaut.

## Nächster Schritt
M4 per CI integrieren; anschließend M5 – Behördenverzeichnis, Zuständigkeitslogik, Versandpakete, Versandqueue und Zustellnachweis.
