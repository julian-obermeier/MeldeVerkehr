# Security Policy

## Grundsätze

MeldeVerkehr verarbeitet sensible personenbezogene Daten, Standortdaten, Kennzeichen, Beweisfotos und Behördenkommunikation. Sicherheit und Datenminimierung haben daher Priorität vor Komfortfunktionen.

## Verbindliche Regeln

- Passwörter ausschließlich mit PHP `password_hash()` / `password_verify()`
- Prepared Statements für Datenbankzugriffe
- CSRF-Schutz für zustandsändernde Webrequests
- XSS-Schutz und standardmäßiges Escaping
- sichere Sessions und Cookies
- Originalbeweise niemals öffentlich erreichbar
- keine Secrets oder echten Beweisdateien im Repository
- Berechtigungs- und Ownership-Prüfung auf jedem sensiblen Zugriff
- Auditierung kritischer Aktionen
- KI darf keine Anzeige ohne ausdrückliche Nutzerfreigabe versenden

## Sicherheitslücken

Sensible Schwachstellen nicht öffentlich in einem Issue mit reproduzierbaren Exploitdetails veröffentlichen. Bei privatem Sicherheitskanal diesen bevorzugen.

## Unterstützte Versionen

Während der Entwicklungsphase wird ausschließlich der aktuelle Entwicklungsstand unterstützt.
