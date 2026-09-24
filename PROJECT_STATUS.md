# Projektstatus

## Aktuelle Version
0.5.0-dev

## Phase
M5 – Behördenrouting und Versand

## M1–M4
Fundament, Vorgangskern, Beweissystem sowie Sachverhalt/Zeugenbericht/finaler Qualitätsreview sind abgeschlossen und auf `develop` integriert.

## M5 – umgesetzt
- versionierbares Behördenverzeichnis ohne hardcodierte ungeprüfte Produktivkontakte
- Behördenstatus und Verifikationszeitpunkt
- mehrere Versandendpunkte pro Behörde
- Kanäle als technische Endpunktseigenschaft
- Priorität und Status je Endpunkt
- versionierte Behördenanforderungsprofile
- Pflichtfelder, akzeptierte MIME-Typen sowie Einzel-/Gesamtgrößenlimits
- deterministische Routingregeln nach Land, Bundesland/Region, Landkreis, PLZ, PLZ-Präfix, Ort und Tatbestandskategorie
- administrativ steuerbare Routingpriorität
- Spezifitätsscore und erklärbare Trefferfelder
- Gleichstand zwischen Behörden wird als `AMBIGUOUS` blockiert statt geraten
- `EXACT`/nicht-exakte Routing-Sicherheit mit Bestätigungspflicht
- verschlüsselter Dispatch-Snapshot
- SHA-256 über kanonischen Dispatch-Snapshot
- Snapshot bindet Zielbehörde, Endpunkt, Routingregel, Reporter, bestätigten Zeugenbericht, Beweismappe, Qualitätsreview und Anforderungsprofil
- Kompatibilitätsprüfung gegen Pflichtfelder, MIME-Typen und Größenlimits
- Dispatch-Status und versionierte Versandversuche
- DB-basierte Dispatch-Queue über vorhandenes Job-System
- Statusfolge `READY_FOR_SUBMISSION` → `SUBMISSION_PENDING` → `SENT`
- Fehlerpfad über `DELIVERY_FAILED` und Queue-Retry
- Evidence-Anlagen werden vor Transport erneut gegen eingefrorene SHA-256-Snapshots geprüft
- transportneutrale Schnittstelle
- DB-Dry-Run-Transport
- attachment-fähiger PHP-`mail()`-Transport
- **sicherer Standard: `DISPATCH_TRANSPORT=dry_run`**
- Installer und `.env.example` setzen Dry-Run standardmäßig
- Cron verarbeitet `DISPATCH_SEND`-Jobs über `php cron.php queue`
- Bürger-Versandreview mit Zielbehörde, Endpunkt, Routinggrund, Anforderungsprofil, Blockern und Warnungen
- explizite Nutzerbestätigung vor Queue-Erstellung
- Versandstatus im Vorgang
- Integrationstest mit ausschließlich fiktiven Behörden- und E-Mail-Daten

## M5-Status
Der technische Routing-/Versandkern ist abgeschlossen. In der Standardkonfiguration werden keinerlei echte Behördennachrichten versendet. Echter PHP-Mail-Versand ist nur nach expliziter Konfiguration möglich.

## Bewusst getrennt
- reale Behördenkontakte und Zuständigkeitsdaten werden nicht ungeprüft ausgeliefert; sie benötigen einen verifizierten Import-/Pflegeprozess.
- automatische Webformular-, Portal- und API-Übertragung folgt erst nach konkreten, verifizierten Integrationen.
- Zustellbestätigung über SMTP hinaus sowie Behördenrückantworten/IMAP folgen im nächsten Kommunikationsblock.
- die fachliche Entscheidung einer Behörde wird nicht durch das Routing oder den Versandstatus vorweggenommen.

## Bekannte Einschränkungen
- PHP-`mail()` liefert nur eine lokale Transportannahme, keine gesicherte behördliche Zustellung.
- Reporterprofil enthält aktuell Vorname, Nachname und E-Mail; zusätzliche Behördenpflichtfelder können deshalb über das Anforderungsprofil blockieren.
- produktive Behördenstammdaten sind noch leer.
- keine automatische Portal-/API-Navigation.

## Nächster Schritt
M5 per CI integrieren; danach M6 – Zustellmonitoring, SMTP/IMAP, eingehende Behördenantworten, Klassifikation, Aufgaben und Fristen.
