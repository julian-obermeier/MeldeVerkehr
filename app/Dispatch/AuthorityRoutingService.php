<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

use MeldeVerkehr\Auth\AuthorizationService;
use PDO;

final class AuthorityRoutingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization
    ) {
    }

    public function routeForCase(string $userId, string $caseId): array
    {
        $context = $this->caseContext($userId, $caseId);

        $stmt = $this->pdo->query(
            'SELECT rr.*, a.name AS authority_name, a.status AS authority_status,
                    ep.channel AS endpoint_channel, ep.endpoint_value, ep.status AS endpoint_status,
                    ep.priority AS endpoint_priority
             FROM authority_routing_rules rr
             INNER JOIN authorities a ON a.id = rr.authority_id
             LEFT JOIN authority_endpoints ep ON ep.id = rr.endpoint_id
             WHERE rr.active = 1
               AND a.status IN ("ACTIVE","VERIFIED")
             ORDER BY rr.priority DESC, rr.created_at ASC'
        );

        $matches = [];
        foreach ($stmt->fetchAll() as $rule) {
            if (!$this->matches($rule, $context)) {
                continue;
            }

            $endpoint = $this->resolveEndpoint($rule);
            if ($endpoint === null) {
                continue;
            }

            $specificity = $this->specificity($rule);
            $matches[] = [
                'rule_id' => (string) $rule['id'],
                'authority_id' => (string) $rule['authority_id'],
                'authority_name' => (string) $rule['authority_name'],
                'endpoint_id' => (string) $endpoint['id'],
                'channel' => (string) $endpoint['channel'],
                'endpoint_value' => (string) $endpoint['endpoint_value'],
                'certainty' => (string) $rule['certainty'],
                'priority' => (int) $rule['priority'],
                'specificity' => $specificity,
                'score' => ((int) $rule['priority'] * 1000) + $specificity,
                'matched_on' => $this->matchedOn($rule),
            ];
        }

        usort($matches, static fn(array $a, array $b): int =>
            ($b['score'] <=> $a['score'])
            ?: strcmp($a['authority_name'], $b['authority_name'])
        );

        if ($matches === []) {
            return [
                'status' => 'NONE',
                'context' => $context,
                'selected' => null,
                'candidates' => [],
                'requires_confirmation' => true,
            ];
        }

        $selected = $matches[0];
        $ambiguous = isset($matches[1])
            && $matches[1]['score'] === $selected['score']
            && $matches[1]['authority_id'] !== $selected['authority_id'];

        if ($ambiguous) {
            return [
                'status' => 'AMBIGUOUS',
                'context' => $context,
                'selected' => null,
                'candidates' => array_values(array_filter(
                    $matches,
                    static fn(array $candidate): bool => $candidate['score'] === $selected['score']
                )),
                'requires_confirmation' => true,
            ];
        }

        return [
            'status' => 'MATCHED',
            'context' => $context,
            'selected' => $selected,
            'candidates' => $matches,
            'requires_confirmation' => $selected['certainty'] !== 'EXACT',
        ];
    }

    public function requirements(string $authorityId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, authority_id, version_no, required_fields_json, accepted_mime_json,
                    max_attachment_bytes, max_total_bytes, notes, created_at
             FROM authority_requirement_versions
             WHERE authority_id = :authority_id AND active = 1
             ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute(['authority_id' => $authorityId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['required_fields'] = json_decode((string) $row['required_fields_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['accepted_mime'] = json_decode((string) $row['accepted_mime_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['required_fields_json'], $row['accepted_mime_json']);

        return $row;
    }

    private function caseContext(string $userId, string $caseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.user_id, l.country, l.state, l.district, l.postal_code, l.city,
                    o.category_key AS offense_category
             FROM cases c
             LEFT JOIN locations l ON l.case_id = c.id
             LEFT JOIN case_offenses co ON co.case_id = c.id AND co.is_primary = 1
             LEFT JOIN offense_versions ov ON ov.id = co.offense_version_id
             LEFT JOIN offenses o ON o.id = ov.offense_id
             WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $caseId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'case.view_own', (string) $row['user_id']);

        return [
            'case_id' => (string) $row['id'],
            'country_code' => strtoupper(trim((string) ($row['country'] ?? 'DE'))) ?: 'DE',
            'postal_code' => trim((string) ($row['postal_code'] ?? '')),
            'city' => trim((string) ($row['city'] ?? '')),
            'district' => trim((string) ($row['district'] ?? '')),
            'state_code' => trim((string) ($row['state'] ?? '')),
            'offense_category' => trim((string) ($row['offense_category'] ?? '')),
        ];
    }

    private function matches(array $rule, array $context): bool
    {
        if ($rule['country_code'] !== null && strtoupper((string) $rule['country_code']) !== $context['country_code']) {
            return false;
        }

        if ($rule['state_code'] !== null && strcasecmp((string) $rule['state_code'], $context['state_code']) !== 0) {
            return false;
        }

        if ($rule['postal_code'] !== null && (string) $rule['postal_code'] !== $context['postal_code']) {
            return false;
        }

        if (
            $rule['postal_prefix'] !== null
            && !str_starts_with($context['postal_code'], (string) $rule['postal_prefix'])
        ) {
            return false;
        }

        if ($rule['city'] !== null && strcasecmp((string) $rule['city'], $context['city']) !== 0) {
            return false;
        }

        if ($rule['district'] !== null && strcasecmp((string) $rule['district'], $context['district']) !== 0) {
            return false;
        }

        if (
            $rule['offense_category'] !== null
            && strcasecmp((string) $rule['offense_category'], $context['offense_category']) !== 0
        ) {
            return false;
        }

        return true;
    }

    private function resolveEndpoint(array $rule): ?array
    {
        if ($rule['endpoint_id'] !== null) {
            if (!in_array((string) $rule['endpoint_status'], ['ACTIVE','VERIFIED'], true)) {
                return null;
            }

            return [
                'id' => $rule['endpoint_id'],
                'channel' => $rule['endpoint_channel'],
                'endpoint_value' => $rule['endpoint_value'],
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, channel, endpoint_value
             FROM authority_endpoints
             WHERE authority_id = :authority_id AND status IN ("ACTIVE","VERIFIED")
             ORDER BY priority ASC, created_at ASC LIMIT 1'
        );
        $stmt->execute(['authority_id' => $rule['authority_id']]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function specificity(array $rule): int
    {
        return ($rule['postal_code'] !== null ? 100 : 0)
            + ($rule['postal_prefix'] !== null ? 60 : 0)
            + ($rule['city'] !== null ? 50 : 0)
            + ($rule['district'] !== null ? 40 : 0)
            + ($rule['state_code'] !== null ? 30 : 0)
            + ($rule['offense_category'] !== null ? 20 : 0)
            + ($rule['country_code'] !== null ? 10 : 0);
    }

    private function matchedOn(array $rule): array
    {
        $fields = [];

        foreach ([
            'country_code','state_code','postal_code','postal_prefix','city','district','offense_category'
        ] as $field) {
            if ($rule[$field] !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
