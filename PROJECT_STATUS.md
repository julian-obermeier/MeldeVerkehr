# Projektstatus

## Aktuelle Version
0.11.0-dev

## Phase
M11 – Behördenportal, Authority-RBAC und API v1

## M1–M10
Fundament, Vorgangskern, Beweissystem, Zeugenbericht/finaler Review, Behördenrouting/Dispatch, Behördenkommunikation, intelligente Assistenz, private Karten/Analytics, Community sowie Suche/Dokumentcenter/Notifications/Exporte/Retention sind abgeschlossen und auf `develop` integriert.

## M11 – umgesetzt
- Authority-Scope-Modell pro Nutzer und Behörde
- getrennte Scope-Rollen `AUTHORITY_USER` und `AUTHORITY_ADMIN`
- ein Nutzer kann je Behörde unterschiedliche Scope-Rollen besitzen
- Scope-Rolle begrenzt Rechte zusätzlich zur globalen Rollen-/Permission-Zuordnung
- globale AUTHORITY_ADMIN-Rolle kann einen schwächeren AUTHORITY_USER-Scope nicht eskalieren
- Behördenportal unter `/authority`
- Inbox ausschließlich für an die jeweilige Behörde tatsächlich als `SENT` übermittelte Vorgänge
- Suche/Filter nach Vorgangsnummer, Straße, Ort und Status im Authority-Scope
- Behördenfallansicht verwendet den eingefrorenen Dispatch-Snapshot statt einer veränderlichen Live-Bürgerakte
- Dispatch-Snapshot wird vor Darstellung per SHA-256 verifiziert
- strukturierte Behördenanfragen mit Typen TIME/PHOTO/LOCATION/WITNESS/OTHER
- Portal-Anfragen werden verschlüsselt gespeichert
- Portal-Anfragen erzeugen gleichzeitig offene Aufgaben im bestehenden Bürger-Kommunikationsworkflow
- optionale Fristen je Authority-Inquiry
- separater verschlüsselter Halterdaten-Speicher
- Halterdaten sind nicht Bestandteil der Bürgerakte, Community, Analytics oder regulärer Authority-Exporte
- Halterdatensätze werden per SHA-256 gegen Manipulation geprüft
- Halterdatenzugriff nur mit Authority-Admin-Scope und `authority.holder.*`-Rechten
- Authority-scoped CSV-/JSON-/XML-Exporte
- Authority-Exporte enthalten keine isolierten Halterdaten
- Authority-Administration für Benutzerzuordnungen und API-Tokens
- API-Tokens werden nur als SHA-256-Hash gespeichert
- Token-Klartext wird ausschließlich beim Erstellen zurückgegeben
- Tokenablauf, Widerruf und Last-Used-Zeitpunkt
- API-Scopes `cases:read`, `inquiries:write`, `exports:read`, `holder:read`, `holder:write`
- API-Scope ersetzt niemals Authority-Scope
- Token ist fest an eine Authority-ID gebunden
- Cross-Authority-Zugriff über Token ist blockiert
- API v1 unter `/api/v1/authority`
- standardisierte JSON-Antworten mit `success`, `data`, `errors`, `meta`
- API-Fallliste
- API-Falldetail aus frozen dispatch snapshot
- API-Erstellung strukturierter Authority-Inquiries
- Webportal nutzt Session + CSRF
- API nutzt ausschließlich Bearer-Token
- Dashboard zeigt Behördenportal nur bei aktivem Authority-Scope
- Integrationstests für Scope-Isolation, eingefrorene Dispatchdaten, Inquiry-Task-Brücke, Halterverschlüsselung, Exporte, Token-Hashing, Token-Scopes und Widerruf

## Sicherheitsprinzipien
- Rollen allein gewähren keinen Authority-Fallzugriff; zusätzlich muss ein aktiver Scope zur konkreten Behörde existieren.
- Authority-Permissions werden pro Scope-Rolle erneut begrenzt.
- API-Tokens sind hochentropisch und werden nicht im Klartext persistiert.
- API-Token-Scopes werden zusätzlich gegen die aktuelle Authority-Rolle des Token-Benutzers geprüft.
- widerrufene oder abgelaufene Tokens sind sofort ungültig.
- ein Token für Behörde A kann keinen Vorgang von Behörde B lesen, auch wenn der Benutzer beide Behörden kennt.
- Behördenfallansichten lesen den tatsächlich versandten, unveränderlichen Dispatch-Snapshot.
- Halterdaten sind in einer separaten verschlüsselten Tabelle isoliert.
- reguläre Authority-Exporte enthalten keine Halterdaten.
- Authority-Inquiries speichern Text verschlüsselt und erzeugen nur strukturierte Bürger-Aufgaben.
- Webmutationen bleiben CSRF-geschützt; API-Aufrufe benötigen Bearer-Token.

## Bekannte Einschränkungen
- Authority-Accounts werden derzeit aus bereits existierenden MeldeVerkehr-Benutzern zugeordnet; ein separates Einladungs-/SSO-Onboarding ist noch offen.
- OAuth2/OIDC ist noch nicht implementiert; API v1 nutzt gehashte statische Bearer-Tokens.
- Authority-API stellt in M11 noch keinen Evidence-Dateidownload bereit.
- Halterdaten werden bewusst nicht über die API ausgegeben.
- Portal-Inquiries besitzen noch keinen eigenen strukturierten Authority-Antwortabschluss; die Bürgeraufgabe ist bereits integriert.
- Authority-Exporte sind synchron und auf 250 Inbox-Zeilen begrenzt.
- separate verifizierte Authority-Organisationseinstellungen, Billing und SLA-Funktionen sind noch offen.

## Nächster Schritt
M11 per CI integrieren; anschließend M12 – Produktionshärtung, Update-/Backup-Workflow, PWA-/Offline-Feinschliff, Accessibility, Last-/Abuse-Tests und Release Candidate.
