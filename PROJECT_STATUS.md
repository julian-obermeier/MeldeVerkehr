# Projektstatus

## Aktuelle Version
0.9.0-dev

## Phase
M9 – Community, kontrollierte Fallfreigaben, Reputation und Moderation

## M1–M8
Fundament, Vorgangskern, Beweissystem, Zeugenbericht/finaler Review, Behördenrouting/Dispatch, Behördenkommunikation, intelligente Assistenz sowie private Karten/Analytics/Problemstellen sind abgeschlossen und auf `develop` integriert.

## M9 – umgesetzt
- technisch getrennte Community-Profile
- Community-Profil veröffentlicht niemals Konto-E-Mail oder private Vorgangsdaten
- feldbezogene Sichtbarkeit für Bio und Region
- opt-in Leaderboard
- regionale und thematische Gruppen
- Gruppenmitgliedschaften und Owner-/Member-Rollen
- Community-Feed, Beiträge, Kommentare und hilfreiche Reaktionen
- öffentliche Problemstellen als getrennte Community-Entitäten
- öffentliche Problemstellen starten immer im Moderationsstatus `PENDING`
- öffentliche Problemstellen verwenden vergröberte Koordinaten
- anonymisierte Beobachtungen zu freigegebenen öffentlichen Problemstellen
- kontrollierte Fallfreigabe als separate Community-Kopie
- private Akte wird durch Community-Freigabe nicht verändert
- Fallfreigabe enthält keine interne Case-ID
- Fallfreigabe enthält keine öffentliche OWI-Vorgangsnummer
- Fallfreigabe enthält kein Kennzeichen
- Fallfreigabe enthält keine Halter-/Eigentümerdaten
- Standortfreigabe ist explizit auswählbar: kein Standort / Ort-Region / Straße ohne Hausnummer
- Beobachtungsdatum und Tatbestand sind separat opt-in
- Bilder können nur aus bestätigten Privacy-`PUBLIC`-Versionen übernommen werden
- öffentliche Bildkopien werden vor Veröffentlichung und Auslieferung per SHA-256 geprüft
- veröffentlichte Fallkopien besitzen einen separaten öffentlichen Token
- Community-Posts können eine veröffentlichte eigene Fallkopie referenzieren
- verschlüsselte Direktnachrichten
- erste Direktnachricht an unbekannte Nutzer wird als Anfrage behandelt
- Nachrichtenanfragen können explizit angenommen werden
- gegenseitig wirksames Blockierungssystem
- transparente Reputationsevents
- Helpful-Reaktionen vergeben qualitätsgewichtete Community-Punkte
- idempotente Unique-Keys verhindern Mehrfachpunkte für dieselbe Interaktion
- Reputation ist in getrennte Kategorien aufgeteilt
- Badge-Stammdaten und Levelmodell
- Leaderboards zeigen nur Nutzer mit ausdrücklichem Opt-in
- Moderationsmeldungen für Beiträge, Kommentare, Problemstellen, Profile und Nachrichten
- Community-/Regionalmoderatorrollen nutzen vorhandene Moderationspermissions
- Regionalmoderatoren sind auf den eigenen Profil-Regionsscope beschränkt
- öffentliche Problemstellen brauchen explizite Moderationsfreigabe
- Moderationsaktionen werden separat protokolliert
- HIDE entfernt Inhalte aus öffentlichen Abfragen, ohne die Originaldatensätze still zu löschen
- Community-Dashboard/Feed, Profil, Gruppen, Problemstellen, Nachrichten, Leaderboard und Moderationsoberfläche
- Community-Link im Bürgerdashboard
- Community-Freigabeverwaltung aus dem privaten Vorgang
- umfassende Integrationstests für Profile, Sichtbarkeit, Gruppen, Beiträge, DMs, Blockierungen, Reputation, Fallfreigaben und Regionalmoderation

## Datenschutz- und Sicherheitsprinzipien
- private Fallakte und Community-Datenmodell sind getrennt.
- Community-Profil enthält keine Konto-E-Mail, Anschrift oder privaten Vorgangsdaten.
- öffentliche Fallkopien sind eigenständige Snapshots und keine Live-Ansicht der privaten Akte.
- ausschließlich Privacy-bestätigte `PUBLIC`-Evidence-Versionen dürfen in Community-Freigaben erscheinen.
- ORIGINAL- und WORKING-Evidence werden nie über Community-URLs ausgeliefert.
- jede freigegebene Bildkopie wird gegen den im Release gespeicherten Hash geprüft.
- öffentliche Freigaben enthalten weder Kennzeichen noch interne oder öffentliche Vorgangsnummern.
- öffentliche Standortdaten werden nur gemäß expliziter Nutzerwahl übernommen; Hausnummern werden nicht veröffentlicht.
- Direktnachrichten werden verschlüsselt gespeichert und per SHA-256 auf Integrität geprüft.
- Blockierungen wirken für Direktnachrichten und Feed-Sichtbarkeit in beide Richtungen.
- öffentliche Problemstellen werden vor Veröffentlichung moderiert und verwenden vergröberte Koordinaten.
- Reputation basiert auf transparenten Ereignissen statt bloßer Beitragsmenge.
- Leaderboards sind opt-in.
- Regionalmoderatoren sehen nur Inhalte, deren Erstellerprofil in ihren Region-Scope fällt.

## Bekannte Einschränkungen
- Avatar-Dateiupload ist im M9-Kern noch nicht umgesetzt; Profile enthalten derzeit textuelle Community-Daten.
- Gruppen-Chats sind noch nicht enthalten; Direktnachrichten sind umgesetzt.
- Follow-/Follower-Beziehungen und Favoriten sind noch offen.
- Community-Suche und paginierte Feeds werden in einer späteren Ausbauphase ergänzt.
- automatische Privacy-Erkennung für Freitext ist derzeit bewusst konservativ und ersetzt keine Nutzerprüfung.
- öffentliche Problemstellen nutzen noch keine interaktive Basiskarte.
- Moderator-Scope basiert aktuell auf Community-Profilregionen; eine separate verifizierte Regionszuweisung kann später ergänzt werden.
- Appeals/Widerspruch gegen Moderationsaktionen ist noch nicht als eigener Workflow umgesetzt.
- Reputation kann später um stärkere Diminishing-Returns-/Anomalieerkennung erweitert werden.

## Nächster Schritt
M9 per CI integrieren; anschließend M10 – Dokumentcenter, globale eigene Vorgangssuche, gespeicherte Filter, Benachrichtigungscenter, Exporte und Betriebs-/Qualitätsausbau.
