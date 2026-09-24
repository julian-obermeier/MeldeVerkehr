<?php

declare(strict_types=1);

namespace MeldeVerkehr\Witness;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class FinalReviewService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly CaseService $cases,
        private readonly WitnessService $witness,
        private readonly AuditLogger $audit
    ) {
    }

    public function summary(string $userId, string $caseId): array
    {
        $caseData = $this->cases->findOwned($userId, $caseId);

        if ($caseData === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize(
            $userId,
            'case.edit_own',
            (string) $caseData['case']['user_id']
        );

        $red = [];
        $yellow = [];
        $green = [];

        $caseStatus = (string) $caseData['case']['status'];
        $completed = $caseStatus === CaseStatus::READY_FOR_SUBMISSION;

        if ($caseStatus === CaseStatus::READY_FOR_REVIEW) {
            $green[] = 'Vorgang befindet sich im finalen Review.';
        } elseif ($completed) {
            $green[] = 'Finaler Qualitätsreview wurde bereits bestätigt; der Vorgang ist versandbereit.';
        } else {
            $red[] = 'Der Vorgang befindet sich nicht im finalen Review-Status.';
        }

        $package = $this->latestPackage($caseId);
        if ($package === null) {
            $red[] = 'Keine eingefrorene Beweismappe vorhanden.';
        } elseif (!hash_equals(
            (string) $package['manifest_sha256'],
            hash('sha256', (string) $package['manifest_json'])
        )) {
            $red[] = 'Integrität des Evidence-Package-Manifests ist verletzt.';
        } else {
            $green[] = sprintf(
                'Beweismappe Version %d ist eingefroren und der Manifest-Hash ist intakt.',
                (int) $package['version_no']
            );
        }

        $report = null;
        $reportIntegrityError = null;
        try {
            $report = $this->witness->currentConfirmedReport($userId, $caseId);
        } catch (\DomainException) {
            $report = null;
        } catch (\RuntimeException $e) {
            $reportIntegrityError = $e->getMessage();
        }

        if ($reportIntegrityError !== null) {
            $red[] = 'Integrität des aktuellen Zeugenberichts konnte nicht bestätigt werden.';
        } elseif ($report === null) {
            $red[] = 'Kein aktueller bestätigter Zeugenbericht mit elektronischer Erklärung vorhanden.';
        } else {
            $green[] = sprintf(
                'Zeugenbericht Version %d ist bestätigt und passt zum aktuellen Aktenstand.',
                (int) $report['version_no']
            );
            $green[] = 'Elektronische Erklärung ist vorhanden.';
        }

        $vehicle = $caseData['vehicle'] ?? null;
        if (!is_array($vehicle) || trim((string) ($vehicle['license_plate'] ?? '')) === '') {
            $red[] = 'Fahrzeug/Kennzeichen ist nicht vollständig.';
        } else {
            $green[] = 'Fahrzeug und Kennzeichen sind vollständig.';
        }

        $location = $caseData['location'] ?? null;
        if (!is_array($location)) {
            $red[] = 'Standort fehlt.';
        } else {
            $green[] = 'Standort ist erfasst.';

            if (($location['traffic_space_type'] ?? 'UNKNOWN') === 'UNKNOWN') {
                $yellow[] = 'Der Verkehrsraum ist weiterhin als unklar markiert.';
            }
            if (($location['access_type'] ?? 'UNCLEAR') === 'UNCLEAR') {
                $yellow[] = 'Öffentlich/privat ist weiterhin unklar.';
            }
        }

        if (($caseData['case']['observed_from'] ?? null) === null) {
            $red[] = 'Beobachtungsbeginn fehlt.';
        } else {
            $green[] = 'Beobachtungsbeginn ist dokumentiert.';
        }

        if (($caseData['case']['observed_until'] ?? null) === null) {
            $yellow[] = 'Kein Beobachtungsende dokumentiert.';
        }

        $offense = $caseData['offenses'][0] ?? null;
        if (
            !is_array($offense)
            || ($offense['stable_key'] ?? '') === 'UNCLASSIFIED_PARKING'
            || (int) ($offense['user_confirmed'] ?? 0) !== 1
        ) {
            $red[] = 'Kein konkreter bestätigter Tatbestand ausgewählt.';
        } else {
            $green[] = 'Konkreter Tatbestand wurde durch den Nutzer bestätigt.';
        }

        $evidenceReview = $this->latestEvidenceReview($caseId);
        if ($evidenceReview !== null) {
            $warnings = json_decode((string) $evidenceReview['warnings_json'], true);
            if (is_array($warnings)) {
                foreach ($warnings as $warning) {
                    if (is_string($warning) && trim($warning) !== '') {
                        $yellow[] = 'Evidence-Hinweis: ' . trim($warning);
                    }
                }
            }
        }

        return [
            'case_data' => $caseData,
            'package' => $package === null ? null : [
                'id' => $package['id'],
                'version_no' => $package['version_no'],
                'manifest_sha256' => $package['manifest_sha256'],
                'frozen_at' => $package['frozen_at'],
            ],
            'report' => $report,
            'red' => array_values(array_unique($red)),
            'yellow' => array_values(array_unique($yellow)),
            'green' => array_values(array_unique($green)),
            'ready' => $red === [] && !$completed,
            'completed' => $completed,
            'latest_quality_review' => $this->latestQualityReview($caseId),
        ];
    }

    public function confirm(string $userId, string $caseId, bool $yellowAcknowledged): array
    {
        $summary = $this->summary($userId, $caseId);

        if ($summary['completed']) {
            throw new \DomainException('Der finale Qualitätsreview wurde bereits bestätigt.');
        }

        if ($summary['red'] !== []) {
            throw new \DomainException('Der finale Review enthält noch blockierende Punkte.');
        }

        if ($summary['yellow'] !== [] && !$yellowAcknowledged) {
            throw new \DomainException('Gelbe Hinweise müssen vor dem Versandstatus bestätigt werden.');
        }

        $version = $this->nextVersion($caseId);
        $reviewId = Uuid::v4();

        $stmt = $this->pdo->prepare(
            'INSERT INTO case_quality_reviews
             (id, case_id, version_no, red_json, yellow_json, green_json,
              acknowledged_yellow, created_by_user_id, created_at, confirmed_at)
             VALUES
             (:id, :case_id, :version_no, :red, :yellow, :green,
              :acknowledged, :user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $reviewId,
            'case_id' => $caseId,
            'version_no' => $version,
            'red' => json_encode($summary['red'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'yellow' => json_encode($summary['yellow'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'green' => json_encode($summary['green'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'acknowledged' => $yellowAcknowledged ? 1 : 0,
            'user_id' => $userId,
        ]);

        try {
            $this->cases->changeStatus(
                $userId,
                $caseId,
                CaseStatus::READY_FOR_SUBMISSION,
                'Finaler Qualitätsreview bestätigt'
            );
        } catch (Throwable $e) {
            $this->pdo->prepare('DELETE FROM case_quality_reviews WHERE id = :id')
                ->execute(['id' => $reviewId]);
            throw $e;
        }

        $this->audit->log('CASE_FINAL_REVIEW_CONFIRMED', 'case', $caseId, 'USER', $userId, [
            'quality_review_id' => $reviewId,
            'version_no' => $version,
            'yellow_acknowledged' => $yellowAcknowledged,
        ]);

        return [
            'quality_review_id' => $reviewId,
            'version_no' => $version,
            'status' => CaseStatus::READY_FOR_SUBMISSION,
        ];
    }

    private function latestPackage(string $caseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, version_no, manifest_json, manifest_sha256, frozen_at
             FROM evidence_packages
             WHERE case_id = :case_id AND status = "FROZEN"
             ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute(['case_id' => $caseId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function latestEvidenceReview(string $caseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT warnings_json, acknowledged_warnings, confirmed_at
             FROM case_evidence_reviews
             WHERE case_id = :case_id AND confirmed_at IS NOT NULL
             ORDER BY review_version DESC LIMIT 1'
        );
        $stmt->execute(['case_id' => $caseId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function latestQualityReview(string $caseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, version_no, acknowledged_yellow, created_at, confirmed_at
             FROM case_quality_reviews
             WHERE case_id = :case_id AND confirmed_at IS NOT NULL
             ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute(['case_id' => $caseId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function nextVersion(string $caseId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1
             FROM case_quality_reviews WHERE case_id = :case_id'
        );
        $stmt->execute(['case_id' => $caseId]);

        return max(1, (int) $stmt->fetchColumn());
    }
}
