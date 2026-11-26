<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\ApiProblem;
use DateTimeImmutable;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;

abstract class IntegrationAdminController extends BaseAdminController
{
    /** @param list<string> $allowed @return array<string,mixed> */
    protected function body(array $allowed): array
    {
        // HTTP JSON is the native Integration write contract. Read its raw
        // object instead of trusting a mutable ThinkPHP POST parser cache, which
        // may differ on parameterized routes (notably :identityKey/rotate).
        // Unknown fields are still refused; route keys are never body fields.
        if (str_contains(strtolower((string) $this->request->contentType()), 'json')) {
            try {
                $body = json_decode($this->request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw IntegrationSecurityException::invalid();
            }
        } else {
            $body = $this->request->post();
        }
        if (!is_array($body) || array_is_list($body) || array_diff(array_keys($body), $allowed) !== []) {
            throw IntegrationSecurityException::invalid();
        }
        return $body;
    }

    protected function positiveInteger(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            || (int) $value < 1
        ) {
            throw IntegrationSecurityException::invalid();
        }
        return (int) $value;
    }

    protected function expiry(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
        ) {
            throw IntegrationSecurityException::invalid();
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw IntegrationSecurityException::invalid();
        }
    }

    protected function problem(IntegrationSecurityException $exception): ApiProblem
    {
        // Native request provenance, only for rejected Integration JSON in
        // a development runtime. No request values, headers, IDs or secrets.
        // This distinguishes body parsing, revision validation, and native
        // service failures that previously all mapped to the same 42200.
        if ($exception->problemCode === 'INTEGRATION_INPUT_INVALID' && $this->app->isDebug()) {
            try {
                $raw = $this->request->getContent();
                $json = json_decode($raw, true);
                $post = $this->request->post();
                \app\common\infrastructure\runtime\OperationalLog::warning(
                    $this->executionContext(),
                    'integration.input_rejected_origin',
                    [
                        'source_file' => basename($exception->getFile()),
                        'source_line' => $exception->getLine(),
                        'request_shared_with_app' => $this->request === $this->app->request,
                        'content_type' => $this->request->contentType(),
                        'raw_bytes' => strlen($raw),
                        'json_type' => get_debug_type($json),
                        'json_field_names' => is_array($json) ? array_keys($json) : [],
                        'json_revision_type' => is_array($json) ? get_debug_type($json['revision'] ?? null) : 'absent',
                        'post_field_names' => is_array($post) ? array_keys($post) : [],
                        'post_revision_type' => is_array($post) ? get_debug_type($post['revision'] ?? null) : 'absent',
                        'route_field_names' => array_keys($this->request->route(false)),
                    ],
                );
            } catch (\Throwable) {
                // Diagnostics must never alter the original HTTP decision.
            }
        }
        $status = match ($exception->problemCode) {
            'INTEGRATION_PERMISSION_DENIED', 'MACHINE_SCOPE_DENIED' => 403,
            'MACHINE_IDENTITY_NOT_FOUND', 'WEBHOOK_ENDPOINT_NOT_FOUND', 'SESSION_DEVICE_NOT_FOUND' => 404,
            'INTEGRATION_REVISION_CONFLICT' => 409,
            'WEBHOOK_SECRET_INVALID' => 503,
            default => 422,
        };
        return new ApiProblem($exception->problemCode, $status, 'Integration request was rejected.');
    }
}
