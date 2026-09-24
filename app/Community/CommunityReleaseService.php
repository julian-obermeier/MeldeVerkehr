<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Evidence\EvidenceStorage;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class CommunityReleaseService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CaseService $cases,
        private readonly EvidenceStorage $storage,
        private readonly AuditLogger $audit
    ) {
    }

    public function createDraft(
        string $userId,
        string $caseId,
        array $options,
        array $selectedEvidenceIds = []
    ): array {
        $case = $this->cases->findOwned($userId, $caseId);

        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $profile = $this->profile($userId);
        if ($profile === null) {
            throw new \DomainException('Für eine Community-Freigabe ist ein Community-Profil erforderlich.');
        }

        $publicText = trim((string) ($options['public_text'] ?? ''));
        if (mb_strlen($publicText) > 5000) {
            throw new \InvalidArgumentException('Öffentlicher Text ist zu lang.');
        }
        $this->assertPublicTextSafe($publicText);

        $locationLevel = strtoupper(trim((string) ($options['location_level'] ?? 'NONE')));
        if (!in_array($locationLevel, ['NONE','CITY','STREET'], true)) {
            throw new \InvalidArgumentException('Ungültige Standortfreigabe.');
        }

        $selectedEvidenceIds = array_values(array_unique(array_filter(array_map(
            'strval',
            $selectedEvidenceIds
        ))));
        if (count($selectedEvidenceIds) > 10) {
            throw new \InvalidArgumentException('Maximal 10 Bilder können öffentlich freigegeben werden.');
        }

        $evidence = $this->releaseEvidence($caseId, $selectedEvidenceIds);
        if (count($evidence) !== count($selectedEvidenceIds)) {
            throw new \DomainException(
                'Mindestens ein ausgewähltes Bild besitzt keine bestätigte Privacy-PUBLIC-Version.'
            );
        }

        $snapshot = [
            'schema_version' => 1,
            'author' => [
                'username' => (string) $profile['username'],
            ],
            'public_text' => $publicText === '' ? null : $publicText,
            'location' => $this->publicLocation($case['location'] ?? null, $locationLevel),
            'observation_date' => !empty($options['include_date'])
                ? $this->dateOnly($case['case']['observed_from'] ?? null)
                : null,
            'offense' => !empty($options['include_offense'])
                ? $this->publicOffense($case['offenses'][0] ?? null)
                : null,
            'evidence' => array_map(
                static fn(array $item, int $index): array => [
                    'order' => $index + 1,
                    'category' => (string) $item['category'],
                    'mime_type' => (string) $item['mime_type'],
                    'sha256' => (string) $item['sha256'],
                ],
                $evidence,
                array_keys($evidence)
            ),
            'privacy' => [
                'contains_case_id' => false,
                'contains_public_case_number' => false,
                'contains_license_plate' => false,
                'contains_vehicle_owner_data' => false,
                'evidence_variant' => $evidence === [] ? null : 'PUBLIC',
            ],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $json = json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $hash = hash('sha256', $json);
        $id = Uuid::v4();
        $publicToken = Uuid::v4();

        try {
            $this->pdo->beginTransaction();

            $this->pdo->prepare(
                'INSERT INTO community_case_releases
                 (id, public_token, user_id, case_id, snapshot_json, snapshot_sha256, status,
                  created_at, published_at, withdrawn_at)
                 VALUES
                 (:id, :public_token, :user_id, :case_id, :snapshot, :sha256, "DRAFT",
                  UTC_TIMESTAMP(), NULL, NULL)'
            )->execute([
                'id' => $id,
                'public_token' => $publicToken,
                'user_id' => $userId,
                'case_id' => $caseId,
                'snapshot' => $json,
                'sha256' => $hash,
            ]);

            $insert = $this->pdo->prepare(
                'INSERT INTO community_case_release_evidence
                 (release_id, evidence_id, order_no, public_version_no, sha256_snapshot, category_snapshot)
                 VALUES
                 (:release_id, :evidence_id, :order_no, :version_no, :sha256, :category)'
            );

            foreach ($evidence as $index => $item) {
                $insert->execute([
                    'release_id' => $id,
                    'evidence_id' => $item['evidence_id'],
                    'order_no' => $index + 1,
                    'version_no' => $item['version_no'],
                    'sha256' => $item['sha256'],
                    'category' => $item['category'],
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log(
            'COMMUNITY_CASE_RELEASE_DRAFT_CREATED',
            'community_case_release',
            $id,
            'USER',
            $userId,
            [
                'selected_evidence_count' => count($evidence),
                'location_level' => $locationLevel,
            ]
        );

        return $this->ownedRelease($userId, $id)
            ?? throw new \RuntimeException('Freigabeentwurf konnte nicht geladen werden.');
    }

    public function publish(string $userId, string $releaseId): array
    {
        $release = $this->ownedReleaseRaw($userId, $releaseId);

        if ($release === null) {
            throw new \DomainException('Freigabe nicht gefunden.');
        }

        if ($release['status'] !== 'DRAFT') {
            throw new \DomainException('Nur Entwürfe können veröffentlicht werden.');
        }

        $this->verifyReleaseIntegrity($releaseId, (string) $release['snapshot_json'], (string) $release['snapshot_sha256']);

        $this->pdo->prepare(
            'UPDATE community_case_releases
             SET status = "PUBLISHED", published_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND status = "DRAFT"'
        )->execute(['id' => $releaseId, 'user_id' => $userId]);

        $this->audit->log(
            'COMMUNITY_CASE_RELEASE_PUBLISHED',
            'community_case_release',
            $releaseId,
            'USER',
            $userId
        );

        return $this->ownedRelease($userId, $releaseId)
            ?? throw new \RuntimeException('Veröffentlichte Freigabe konnte nicht geladen werden.');
    }

    public function withdraw(string $userId, string $releaseId): void
    {
        $release = $this->ownedReleaseRaw($userId, $releaseId);
        if ($release === null) {
            throw new \DomainException('Freigabe nicht gefunden.');
        }

        $this->pdo->prepare(
            'UPDATE community_case_releases
             SET status = "WITHDRAWN", withdrawn_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id'
        )->execute(['id' => $releaseId, 'user_id' => $userId]);

        $this->audit->log(
            'COMMUNITY_CASE_RELEASE_WITHDRAWN',
            'community_case_release',
            $releaseId,
            'USER',
            $userId
        );
    }

    public function ownedReleases(string $userId, string $caseId): array
    {
        $case = $this->cases->findOwned($userId, $caseId);
        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, public_token, status, snapshot_sha256, created_at, published_at, withdrawn_at
             FROM community_case_releases
             WHERE user_id = :user_id AND case_id = :case_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $userId, 'case_id' => $caseId]);

        return $stmt->fetchAll();
    }

    public function ownedRelease(string $userId, string $releaseId): ?array
    {
        $row = $this->ownedReleaseRaw($userId, $releaseId);

        if ($row === null) {
            return null;
        }

        $snapshot = $this->decodeSnapshot($row);
        unset($row['snapshot_json']);
        $row['snapshot'] = $snapshot;

        return $row;
    }

    public function publicRelease(string $publicToken): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_token, snapshot_json, snapshot_sha256, published_at
             FROM community_case_releases
             WHERE public_token = :token AND status = "PUBLISHED"
             LIMIT 1'
        );
        $stmt->execute(['token' => $publicToken]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $snapshot = $this->decodeSnapshot($row);

        return [
            'public_token' => (string) $row['public_token'],
            'published_at' => $row['published_at'],
            'snapshot_sha256' => (string) $row['snapshot_sha256'],
            'snapshot' => $snapshot,
        ];
    }

    public function publicEvidence(string $publicToken, int $order): array
    {
        if ($order < 1 || $order > 10) {
            throw new \DomainException('Öffentliches Bild nicht gefunden.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT cre.id AS release_id, cre.status,
                    cree.sha256_snapshot, cree.category_snapshot,
                    ev.storage_path, ev.mime_type, ev.file_size, ev.sha256
             FROM community_case_releases cre
             INNER JOIN community_case_release_evidence cree ON cree.release_id = cre.id
             INNER JOIN evidence_versions ev
               ON ev.evidence_id = cree.evidence_id
              AND ev.variant = "PUBLIC"
              AND ev.version_no = cree.public_version_no
             WHERE cre.public_token = :token
               AND cre.status = "PUBLISHED"
               AND cree.order_no = :order_no
             LIMIT 1'
        );
        $stmt->execute(['token' => $publicToken, 'order_no' => $order]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Öffentliches Bild nicht gefunden.');
        }

        $path = $this->storage->absolute((string) $row['storage_path']);
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Öffentliche Bildkopie fehlt.');
        }

        $hash = hash_file('sha256', $path);
        if (
            !hash_equals((string) $row['sha256'], $hash)
            || !hash_equals((string) $row['sha256_snapshot'], $hash)
        ) {
            throw new \RuntimeException('Integrität der öffentlichen Bildkopie ist verletzt.');
        }

        $body = file_get_contents($path);
        if ($body === false) {
            throw new \RuntimeException('Öffentliche Bildkopie konnte nicht gelesen werden.');
        }

        return [
            'body' => $body,
            'mime_type' => (string) $row['mime_type'],
            'size' => (int) $row['file_size'],
            'sha256' => $hash,
            'category' => (string) $row['category_snapshot'],
        ];
    }

    private function releaseEvidence(string $caseId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT e.id AS evidence_id, e.category,
                    pr.public_version_no AS version_no,
                    ev.storage_path, ev.mime_type, ev.file_size, ev.sha256
             FROM evidence_items e
             INNER JOIN evidence_privacy_reviews pr ON pr.evidence_id = e.id
             INNER JOIN evidence_versions ev
               ON ev.evidence_id = e.id
              AND ev.variant = "PUBLIC"
              AND ev.version_no = pr.public_version_no
             WHERE e.case_id = ?
               AND e.status = "ACTIVE"
               AND e.id IN (' . $placeholders . ')
             ORDER BY e.created_at, e.id'
        );
        $stmt->execute(array_merge([$caseId], $ids));
        $rows = $stmt->fetchAll();

        $index = [];
        foreach ($rows as $row) {
            $index[(string) $row['evidence_id']] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($index[$id])) {
                $ordered[] = $index[$id];
            }
        }

        return $ordered;
    }

    private function verifyReleaseIntegrity(string $releaseId, string $json, string $expectedHash): void
    {
        if (!hash_equals($expectedHash, hash('sha256', $json))) {
            throw new \RuntimeException('Integrität der Freigabekopie ist verletzt.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT cree.sha256_snapshot, ev.storage_path, ev.sha256
             FROM community_case_release_evidence cree
             INNER JOIN evidence_versions ev
               ON ev.evidence_id = cree.evidence_id
              AND ev.variant = "PUBLIC"
              AND ev.version_no = cree.public_version_no
             WHERE cree.release_id = :release_id'
        );
        $stmt->execute(['release_id' => $releaseId]);

        foreach ($stmt->fetchAll() as $row) {
            $path = $this->storage->absolute((string) $row['storage_path']);
            if (!is_file($path)) {
                throw new \RuntimeException('Freigegebene PUBLIC-Bildkopie fehlt.');
            }
            $hash = hash_file('sha256', $path);

            if (
                !hash_equals((string) $row['sha256_snapshot'], $hash)
                || !hash_equals((string) $row['sha256'], $hash)
            ) {
                throw new \RuntimeException('Integrität einer freigegebenen PUBLIC-Bildkopie ist verletzt.');
            }
        }
    }

    private function publicLocation(mixed $location, string $level): ?array
    {
        if ($level === 'NONE' || !is_array($location)) {
            return null;
        }

        $result = [
            'city' => $location['city'] ?? null,
            'district' => $location['district'] ?? null,
            'state' => $location['state'] ?? null,
        ];

        if ($level === 'STREET') {
            $result['street'] = $location['street'] ?? null;
        }

        return $result;
    }

    private function publicOffense(mixed $offense): ?array
    {
        if (!is_array($offense)) {
            return null;
        }

        return [
            'category' => $offense['category'] ?? null,
            'title' => $offense['title'] ?? null,
        ];
    }

    private function dateOnly(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function assertPublicTextSafe(string $text): void
    {
        if ($text === '') {
            return;
        }

        if (
            preg_match('/\bOWI-\d{4}-\d{6}\b/i', $text) === 1
            || preg_match('/[A-ZÄÖÜ]{1,3}\s*[- ]\s*[A-ZÄÖÜ]{1,2}\s*\d{1,4}\b/u', mb_strtoupper($text, 'UTF-8')) === 1
            || filter_var($text, FILTER_VALIDATE_EMAIL)
            || preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text) === 1
        ) {
            throw new \InvalidArgumentException(
                'Öffentlicher Text enthält möglicherweise ein Kennzeichen, eine Vorgangsnummer oder eine E-Mail-Adresse. Bitte anonymisieren.'
            );
        }
    }

    private function profile(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT username FROM community_profiles
             WHERE user_id = :user_id AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function ownedReleaseRaw(string $userId, string $releaseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM community_case_releases
             WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $releaseId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function decodeSnapshot(array $row): array
    {
        $json = (string) $row['snapshot_json'];

        if (!hash_equals((string) $row['snapshot_sha256'], hash('sha256', $json))) {
            throw new \RuntimeException('Integrität der Community-Freigabe ist verletzt.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Community-Freigabe ist ungültig.');
        }

        return $decoded;
    }
}
