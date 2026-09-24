# Projektstatus

## Aktuelle Version
0.8.0-dev

## Phase
M8 – Private Karten, Problemstellen, Analytics und kommunale Problemberichte

## M1–M7
Fundament, Vorgangskern, Beweissystem, Zeugenbericht/finaler Review, Behördenrouting/Dispatch, Behördenkommunikation und intelligente Assistenz sind abgeschlossen und auf `develop` integriert.

## M8 – umgesetzt
- private Vorgangskarte mit ausschließlich eigenen Vorgängen
- Karte selektiert keine Fahrzeug- oder Kennzeichendaten
- schematische SVG-Karte ohne externe Kartentiles oder Drittanbieter-Requests
- Statusfilter-Grundlage für private Kartendaten
- private Hotspot-Erkennung aus ausschließlich eigenen geolokalisierten Vorgängen
- private Problemstellen mit Eigentümerbindung, Mittelpunkt und Radius
- automatische Fallzuordnung über Haversine-Distanz
- serverseitige Ownership-Prüfung für Problemstellen
- Problemstellen-Detail mit ausschließlich eigenen zugeordneten Fällen
- Aggregation nach Status, Tatbestandskategorie und Tageszeit
- versionierte Problemstellen-Snapshots für frei wählbare Zeiträume
- SHA-256-Integrität je Snapshot
- Analytics-Center mit eigenen Vorgängen
- Auswertungen nach Status, Ort, Tatbestandskategorie und Monat
- frei begrenzbare Analysezeiträume auf Serviceebene
- anonymisierte kommunale Problemberichte
- kommunale Reports enthalten standardmäßig keine Kennzeichen
- kommunale Reports enthalten keine Halter-/Eigentümerdaten
- kommunale Reports enthalten keine internen Vorgangs-IDs
- kommunale Reports enthalten standardmäßig keine Fotos
- Reportkoordinaten werden gegenüber den internen Fallkoordinaten reduziert dargestellt
- versionierte kommunale Reports mit SHA-256-Integrität
- Dashboard-Navigation für Karte, Problemstellen und Analytics
- Integrationstests für Ownership, private Kartenabgrenzung, Hotspots, Aggregationen und anonymisierte Reports

## M8-Status
Der private Karten-/Analytics-Kern ist abgeschlossen. Alle Karten-, Hotspot- und Problemstellenfunktionen arbeiten ausschließlich mit dem Datenbestand des angemeldeten Nutzers. Es existiert weiterhin keine öffentliche Kennzeichen-, Fahrzeug- oder Fallkarte.

## Datenschutzprinzipien
- private Kartendaten verlassen beim Rendern der aktuellen Kartenansicht nicht automatisch den Server/Browser-Kontext zu einem externen Kartendienst.
- die Karten-/Analytics-Abfragen lesen keine Kennzeichen aus der Fahrzeugtabelle.
- Problemstellen sind private Benutzerressourcen.
- Hotspots werden nur aus eigenen Fällen erzeugt.
- kommunale Problemberichte sind eigenständige anonymisierte Aggregationsartefakte.
- interne Case-IDs, Kennzeichen, Halterdaten und Fotos werden nicht in den kommunalen Report-Snapshot aufgenommen.
- Report- und Snapshot-Inhalte sind versioniert und gehasht.
- ein späteres öffentliches Problemstellenmodul muss technisch getrennt bleiben und darf nur ausdrücklich freigegebene, anonymisierte Kopien verwenden.

## Bekannte Einschränkungen
- die aktuelle Karte ist bewusst eine schematische SVG-Darstellung ohne Straßen-/Basiskarte.
- Clusterbildung verwendet derzeit eine einfache geografische Rasterung; komplexere räumliche Clusterverfahren können später ergänzt werden.
- kommunale Reports enthalten noch keinen PDF-/Dokumentexport und noch keinen eigenen Behördenversandworkflow.
- automatisch erkannte Hotspots werden aktuell als Vorschläge angezeigt; das Anlegen erfolgt bewusst durch den Nutzer.
- Behördenreaktionsmetriken können in einer späteren Analytics-Ausbaustufe detaillierter aufbereitet werden.

## Nächster Schritt
M8 per CI integrieren; anschließend M9 – Community-Grundlage mit separaten öffentlichen Profilen, Beiträgen, regionalen Gruppen, moderierten öffentlichen Problemstellen und kontrollierter anonymisierter Freigabe privater Vorgänge.
