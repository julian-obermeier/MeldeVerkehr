# Technische Spezifikation – MeldeVerkehr

## Zielplattform

- PHP 8.3/8.4+
- MySQL/MariaDB
- HTML5/CSS3/JavaScript
- PWA mit Service Worker und IndexedDB
- Shared Hosting
- SMTP/IMAP
- DB-basierte Queue und Cronjobs

## Rollen

- USER
- COMMUNITY_MODERATOR
- REGIONAL_MODERATOR
- ADMIN
- SUPER_ADMIN
- AUTHORITY_USER
- AUTHORITY_ADMIN

Rollen werden durch Permissions und Ownership-/Scope-Prüfungen ergänzt.

## Kernentitäten

- users, user_profiles, passkeys, two_factor
- cases, case_versions, case_status_history, case_timeline
- vehicles, locations
- offenses, offense_versions, case_offenses
- traffic_signs, supplementary_signs, case_traffic_signs
- evidence_files, evidence_metadata, evidence_redactions
- witness_reports, case_witnesses
- authorities, authority_routes, authority_requirements, authority_health
- dispatch_packages, dispatch_attempts
- incoming_messages, outgoing_messages, attachments
- tasks, deadlines, notifications
- documents, ai_runs, audit_logs
- problem_areas, municipal_reports
- community_posts, community_comments, community_reactions
- groups, group_members, conversations, messages
- reputation_events, achievements, moderation_cases, abuse_flags
- jobs, cron_runs, settings

## Vorgangsstatus

DRAFT, CAPTURE_IN_PROGRESS, WAITING_FOR_EVIDENCE, READY_FOR_REVIEW, REVIEW_REQUIRED, READY_FOR_SUBMISSION, SUBMISSION_PENDING, SENT, DELIVERED, DELIVERY_UNKNOWN, DELIVERY_FAILED, AUTHORITY_REPLY, USER_ACTION_REQUIRED, AUTHORITY_PROCESSING, CORRECTION_PENDING, WITHDRAWAL_PENDING, CLOSED, ARCHIVED, DELETION_PENDING.

## Beweissystem

- unverändertes Original außerhalb des Webroots
- SHA-256 je Original
- getrennte Arbeits-/Versandkopie
- Thumbnail
- EXIF/GPS/Aufnahmezeit soweit vorhanden
- Kategorien und Qualitätsstatus
- Datenschutz-Redactions
- Integritätsprüfung
- optional perceptual hash für Bildähnlichkeit

## Tatbestand

Tatbestände und Verkehrszeichen werden versioniert. Alte Vorgänge referenzieren immer den historischen Datenstand. KI darf Vorschläge liefern, Nutzer bestätigt rechtlich relevante Angaben.

## Behördenrouting

Kanäle: PORTAL, API, WEBFORM, EMAIL, PDF, MANUAL.

Pro Behörde können Priorität, Anforderungen, Limits und Fallbacks hinterlegt werden. Jeder Versandversuch wird protokolliert.

## Mail

Ausgang: SMTP.  
Eingang: IMAP per Cron.

Behördenantworten werden dem Vorgang zugeordnet, im Original gespeichert und separat klassifiziert.

## Community

Öffentliche Community-Daten sind von privaten OWi-Akten getrennt. Kennzeichen, Gesichter, behördliche Aktenzeichen und andere personenbezogene Daten dürfen nicht automatisch veröffentlicht werden.

## Sicherheit

- password_hash/password_verify
- Prepared Statements
- CSRF
- XSS-Schutz
- Secure/HttpOnly/SameSite Cookies
- Rate Limits
- MFA/Passkeys optional
- Application-Level Encryption für besonders sensible Felder
- manipulationsgeschütztes Auditlog
- keine Secrets oder Produktionsdaten im Git
