# Contributing

## Branch-Strategie

- `main`: stabile Releases
- `develop`: integrierte Entwicklung
- `feature/<name>`: Features
- `fix/<name>`: Fehlerbehebungen
- `hotfix/<name>`: kritische Produktionsfixes
- `release/<version>`: Releasevorbereitung

## Commit-Präfixe

- `feat:`
- `fix:`
- `security:`
- `refactor:`
- `docs:`
- `test:`
- `chore:`
- `db:`
- `ui:`

## Definition of Done

Ein Feature gilt erst als abgeschlossen, wenn – soweit relevant – Backend, Datenbank/Migration, Validierung, Berechtigungen, UI, Fehlerbehandlung, Audit und Tests vorhanden sind.

## Datenbank

Schemaänderungen ausschließlich über versionierte Migrationen. Historische Tatbestands- und Vorgangsversionen dürfen nicht überschrieben werden.

## Sicherheit

Keine Secrets, Backups, Originalbeweise oder Produktionsdaten committen.
