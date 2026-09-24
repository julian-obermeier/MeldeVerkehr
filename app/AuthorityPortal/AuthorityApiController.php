<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

use JsonException;
use MeldeVerkehr\Auth\AuthorizationException;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Release\ReleaseServiceFactory;

final class AuthorityApiController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function cases(Request $request): Response
    {
        if (($limited = $this->throttle($request, 'authority-api-cases')) !== null) {
            return $limited;
        }

        try {
            $ctx = $this->authenticate($request, 'cases:read');
            $rows = AuthorityPortalServiceFactory::portal($this->app)->inbox(
                $ctx['user_id'],
                $ctx['authority_id'],
                $this->nullable($request->query('status')),
                $this->nullable($request->query('q')),
                min(250, max(1, (int) $request->query('limit', 100)))
            );

            return $this->success(
                $rows,
                ['count' => count($rows), 'authority_id' => $ctx['authority_id']]
            );
        } catch (AuthorizationException $e) {
            return $this->error('AUTHENTICATION_FAILED', 'Bearer-Token oder Scope ungültig.', 401);
        }
    }

    public function case(Request $request): Response
    {
        if (($limited = $this->throttle($request, 'authority-api-case')) !== null) {
            return $limited;
        }

        try {
            $ctx = $this->authenticate($request, 'cases:read');
            $detail = AuthorityPortalServiceFactory::portal($this->app)->caseDetail(
                $ctx['user_id'],
                (string) $request->route('id', ''),
                $ctx['authority_id']
            );

            return $this->success($detail, ['authority_id' => $ctx['authority_id']]);
        } catch (AuthorizationException|\DomainException $e) {
            return $this->error('CASE_NOT_FOUND', 'Behördenvorgang nicht gefunden.', 404);
        }
    }

    public function inquiry(Request $request): Response
    {
        if (($limited = $this->throttle($request, 'authority-api-inquiry')) !== null) {
            return $limited;
        }

        try {
            $ctx = $this->authenticate($request, 'inquiries:write');
            $json = $request->json();

            $inquiry = AuthorityPortalServiceFactory::portal($this->app)->createInquiry(
                $ctx['user_id'],
                (string) $request->route('id', ''),
                (string) ($json['inquiry_type'] ?? ''),
                (string) ($json['subject'] ?? ''),
                (string) ($json['body'] ?? ''),
                $this->apiDateTime($json['due_at'] ?? null),
                $ctx['authority_id']
            );

            return $this->success($inquiry, ['authority_id' => $ctx['authority_id']], 201);
        } catch (AuthorizationException $e) {
            return $this->error('AUTHENTICATION_FAILED', 'Bearer-Token oder Scope ungültig.', 401);
        } catch (JsonException|\InvalidArgumentException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return $this->error('CASE_NOT_FOUND', 'Behördenvorgang nicht gefunden.', 404);
        }
    }

    private function throttle(Request $request, string $bucket): ?Response
    {
        $ip = trim((string) $request->server('REMOTE_ADDR', 'unknown'));
        $result = ReleaseServiceFactory::rateLimiter($this->app)->consume(
            $bucket,
            $ip,
            (int) $this->app->config()->get('release.api_rate_limit_attempts', 120),
            (int) $this->app->config()->get('release.api_rate_limit_window_seconds', 60),
            60
        );

        if ($result['allowed']) {
            return null;
        }

        return Response::json([
            'success' => false,
            'data' => null,
            'errors' => [[
                'code' => 'RATE_LIMITED',
                'message' => 'Zu viele Anfragen. Bitte später erneut versuchen.',
            ]],
            'meta' => [
                'retry_after' => $result['retry_after'],
            ],
        ], 429, [
            'Retry-After' => (string) $result['retry_after'],
            'Cache-Control' => 'no-store',
        ]);
    }

    private function authenticate(Request $request, string $scope): array
    {
        $header = (string) $request->server(
            'HTTP_AUTHORIZATION',
            $request->server('REDIRECT_HTTP_AUTHORIZATION', '')
        );

        return AuthorityPortalServiceFactory::tokens($this->app)->authenticate(
            $header,
            $scope
        );
    }

    private function success(mixed $data, array $meta = [], int $status = 200): Response
    {
        return Response::json([
            'success' => true,
            'data' => $data,
            'errors' => [],
            'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return Response::json([
            'success' => false,
            'data' => null,
            'errors' => [[
                'code' => $code,
                'message' => $message,
            ]],
            'meta' => [],
        ], $status);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function apiDateTime(mixed $value): ?\DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('due_at ist ungültig.');
        }
    }
}
