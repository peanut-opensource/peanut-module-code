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
        $body = $this->request->post();
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
