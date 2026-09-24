# Projektstatus

## Aktuelle Version
0.6.0-dev

## Phase
M6 – Zustellmonitoring und Behördenkommunikation

## M1–M5
Fundament, Bürger-Vorgangskern, Beweissystem, Sachverhalt/Zeugenbericht, finaler Review sowie Behördenrouting/Dispatch sind abgeschlossen und auf `develop` integriert.

## M6 – umgesetzt
- zufällige, nicht erratbare Reply-Adresse je Dispatch
- sichere Defaults `COMM_REPLY_DOMAIN=reply.invalid` und `COMM_INBOUND_ENABLED=false`
- Reply-Adresse wird an Dispatch und ausgehende Nachricht gebunden
- stabile Message-ID je Erstversand
- `Reply-To`, `Message-ID` und `In-Reply-To` transportübergreifend
- optionaler SMTP-Transport mit STARTTLS/SSL und AUTH LOGIN
- PHP-`mail()` bleibt als optionale Alternative
- DB-Dry-Run bleibt Standard und protokolliert Threading-Metadaten
- SMTP nutzt die Reply-Adresse als Envelope-Sender für Bounce-Routing
- IMAP-Adapter für ungesehene Nachrichten
- separater Cron `php cron.php inbound-mail`
- Inbound-Cron ist standardmäßig deaktiviert
- Zuordnung eingehender Mails über Reply-Adresse oder `In-Reply-To`
- Deduplizierung über Message-ID/Fingerprint
- verschlüsselte Quarantäne für nicht zuordenbare Nachrichten
- verschlüsselte Speicherung von Absender, Empfänger, Betreff und Nachrichtentext
- SHA-256-Integrität für Nachrichtentexte
- geschützter Storage für eingehende Anhänge
- Hashprüfung beim Download von Behördenanhängen
- deterministische Klassifikation: Eingangsbestätigung, Rückfrage, Frist, Nachforderung, Ablehnung/Einstellung, Abschluss, Unzustellbarkeit, Sonstiges
- deterministische Fristerkennung aus absoluten Datumsangaben und „innerhalb von X Tagen“
- automatische Aufgaben aus Rückfragen/Nachforderungen/Fristen
- separate Fristdatensätze mit expliziter Erledigung
- kontrollierte Statusfortschreibung bis `DELIVERED`, `AUTHORITY_REPLY`, `USER_ACTION_REQUIRED`, `AUTHORITY_PROCESSING` bzw. `DELIVERY_FAILED`
- Behördenabschluss/-ablehnung schließt einen Vorgang **nicht** automatisch
- versionierte, verschlüsselte Antwortentwürfe
- Antwortassistent verwendet nur bereits bestätigte Vorgangsdaten
- Antwortentwürfe werden niemals automatisch versendet
- explizite Nutzerbestätigung vor Reply-Queue
- Reply-Queue über bestehende DB-Jobqueue
- erfolgreicher Reply wird als eigener verschlüsselter OUTBOUND-Verlauf gespeichert
- Threading über `In-Reply-To`/Message-ID
- Aufgaben aus der beantworteten Nachricht werden nach erfolgreichem Versand geschlossen
- Fristen bleiben sichtbar, bis der Nutzer sie ausdrücklich erledigt
- gemeinsame Bürger-Timeline für Ein-/Ausgänge, Anhänge, Aufgaben und Fristen
- Integrationstests für Reply-Routing, Deduplizierung, Quarantäne, Fristen, Anhänge, Antwortentwurf und Dry-Run-Reply

## M6-Status
Der technische Kommunikationskern ist umgesetzt. Ohne explizite Konfiguration werden weder IMAP-Nachrichten eingelesen noch reale E-Mails versendet.

## Sicherheitsprinzipien
- produktiver Eingang und produktiver Versand sind getrennt opt-in.
- unbekannte eingehende Nachrichten werden nicht verworfen, sondern verschlüsselt quarantänisiert.
- eingehende Anhänge liegen außerhalb des Webroots und werden nur nach Ownership- und Hashprüfung ausgeliefert.
- Behördenantworten werden deterministisch klassifiziert; eine spätere KI darf nur assistieren.
- Ablehnung/Abschluss einer Behörde führt nicht automatisch zum Schließen des Vorgangs.
- Nutzerantworten benötigen immer eine explizite Versandbestätigung.

## Bekannte Einschränkungen
- PHP-IMAP muss für den produktiven IMAP-Adapter verfügbar sein.
- PHP-`mail()` kann den Envelope-Sender nicht so kontrolliert setzen wie SMTP; vollständiges Bounce-Routing ist daher mit SMTP vorzuziehen.
- DSN-Parsing ist aktuell keyword-/nachrichtenbasiert und noch kein vollständiger RFC-3464-Parser.
- eingehende Anhangstypen werden geschützt gespeichert, aber noch nicht zusätzlich malware-gescannt.
- KI-gestützte Antwortklassifikation ist noch nicht aktiviert.

## Nächster Schritt
M6 per CI integrieren; anschließend M7 – intelligente Assistenz: OCR, Fotoqualität, Schild-/Zusatzzeichenerkennung und assistive Tatbestandsvorschläge.
