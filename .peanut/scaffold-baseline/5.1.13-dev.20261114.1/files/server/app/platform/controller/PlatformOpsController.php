<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\http\JsonResponseFactory;
use PeanutAdmin\Modules\Ops\Service\PlatformOpsApplicationService;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use think\Response;
use think\response\Json;

/**
 * Platform-only Ops Console Host; PC20 reads plus the bounded PC21 artifact.
 * @property-read PlatformOpsApplicationService $operations 当前 App 中声明式解析的控制器依赖。
 */
final class PlatformOpsController extends BasePlatformController
{
    protected string $operationsClass = PlatformOpsApplicationService::class;

    public function status(): Json
    {
        return $this->run(fn(): array => $this->operations->status($this->context()));
    }

    public function upgradeReadiness(): Json
    {
        return $this->run(fn(): array => $this->operations->upgradeReadiness($this->context()));
    }

    public function providers(): Json
    {
        [$page, $pageSize] = $this->pagination();
        return $this->run(fn(): array => $this->operations->providers($this->context(), $page, $pageSize));
    }

    public function maintenance(): Json
    {
        return $this->run(fn(): ?array => $this->operations->maintenance($this->context()));
    }

    public function logs(): Json
    {
        $params = $this->request->get();
        $source = $params['source'] ?? 'platform.audit';
        $severity = $params['severity'] ?? 'info';
        $pageSize = $params['page_size'] ?? '20';
        if (array_diff(array_keys($params), ['source', 'severity', 'page_size', 'cursor']) !== []
            || $source !== 'platform.audit'
            || !is_string($severity)
            || !in_array($severity, ['info', 'warning', 'error', 'critical'], true)
            || (!is_int($pageSize) && !is_string($pageSize))
            || preg_match('/^[1-9][0-9]{0,2}$/D', (string) $pageSize) !== 1
            || (int) $pageSize > 100
            || (isset($params['cursor']) && (!is_string($params['cursor'])
                || ($params['cursor'] !== '' && preg_match('/^cursor_[a-f0-9]{64}_[0-9]{8}$/D', $params['cursor']) !== 1)))
        ) {
            throw OpsConsoleException::invalid();
        }
        return $this->run(fn(): array => $this->operations->logs(
            $this->context(),
            $source,
            $severity,
            (int) $pageSize,
            ($params['cursor'] ?? '') === '' ? null : $params['cursor'],
        ));
    }

    public function scheduleMaintenance(): Json
    {
        return $this->run(function (): array {
            $params = $this->request->put();
            $keys = array_keys($params);
            sort($keys, SORT_STRING);
            if ($keys !== ['ends_at', 'reason_key', 'starts_at']
                || !is_string($params['reason_key'])
                || !is_string($params['starts_at'])
                || !is_string($params['ends_at'])
            ) {
                throw OpsConsoleException::invalid();
            }

            return $this->operations->scheduleMaintenance(
                $this->context(),
                $params['reason_key'],
                $params['starts_at'],
                $params['ends_at'],
                $this->ifMatchRevision(true),
                $this->idempotencyKey(),
            );
        });
    }

    public function closeMaintenance(string $maintenance_key): Json
    {
        return $this->run(function () use ($maintenance_key): array {
            if ($this->request->post() !== []) {
                throw OpsConsoleException::invalid();
            }

            return $this->operations->closeMaintenance(
                $this->context(),
                $maintenance_key,
                $this->ifMatchRevision(false),
                $this->idempotencyKey(),
            );
        });
    }

    public function diagnostics(): Response
    {
        $requestId = $this->requestId();
        $artifact = $this->operations->diagnostics(
            $this->context(),
            $this->windowMinutes($this->request->get('window_minutes', 60)),
            $requestId,
        );

        return response($artifact['json'], 200, [
            'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="' . $artifact['filename'] . '"',
            'Content-Length' => (string) $artifact['bytes'],
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Diagnostic-SHA256' => $artifact['sha256'],
            'X-Request-Id' => $requestId,
        ]);
    }

    public function backup(): Json
    {
        return $this->run(function (): array {
            $params = $this->request->post();
            if (array_keys($params) !== ['provider_key'] || !is_string($params['provider_key'])) {
                throw OpsConsoleException::invalid();
            }
            return $this->operations->submitBackup(
                $this->context(),
                $params['provider_key'],
                $this->idempotencyKey(),
            );
        });
    }

    public function restore(): Json
    {
        return $this->run(function (): array {
            $params = $this->request->post();
            $paramKeys = array_keys($params);
            sort($paramKeys, SORT_STRING);
            if ($paramKeys !== ['backup_reference_key', 'provider_key', 'target_key']
                || !is_string($params['provider_key'])
                || !is_string($params['backup_reference_key'])
                || !is_string($params['target_key'])
            ) {
                throw OpsConsoleException::invalid();
            }
            return $this->operations->submitRestore(
                $this->context(),
                $params['provider_key'],
                $params['backup_reference_key'],
                $params['target_key'],
                $this->idempotencyKey(),
            );
        });
    }

    public function upgrade(): Json
    {
        return $this->run(function (): array {
            if ($this->request->post() !== []) {
                throw OpsConsoleException::invalid();
            }
            return $this->operations->submitUpgrade($this->context(), $this->idempotencyKey());
        });
    }

    public function moduleOperation(): Json
    {
        return $this->run(function (): array {
            $params = $this->request->post();
            if (array_keys($params) !== ['request_key'] || !is_string($params['request_key'])) {
                throw OpsConsoleException::invalid();
            }
            return $this->operations->submitModuleOperation(
                $this->context(),
                $params['request_key'],
                $this->idempotencyKey(),
            );
        });
    }

    public function moduleOperations(): Json
    {
        return $this->run(fn(): array => $this->operations->moduleOperations($this->context()));
    }

    public function upgrades(): Json
    {
        return $this->run(fn(): array => $this->operations->upgrades($this->context()));
    }

    public function backups(): Json
    {
        [$page, $pageSize] = $this->pagination();
        return $this->run(fn(): array => $this->operations->backups($this->context(), $page, $pageSize));
    }

    public function task(string $task_key): Json
    {
        return $this->run(fn(): array => $this->operations->task($this->context(), $task_key));
    }

    /** @return array{int,int} */
    private function pagination(): array
    {
        $params = $this->request->get();
        if (array_diff(array_keys($params), ['page', 'page_size']) !== []) {
            throw OpsConsoleException::invalid();
        }
        $values = [];
        foreach (['page' => 1, 'page_size' => 20] as $key => $default) {
            $value = $params[$key] ?? $default;
            if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]{0,5}$/D', (string) $value) !== 1) {
                throw OpsConsoleException::invalid();
            }
            $values[] = (int) $value;
        }
        if ($values[1] > 100) {
            throw OpsConsoleException::invalid();
        }
        return $values;
    }

    private function run(callable $operation): Json
    {
        return JsonResponseFactory::data($operation())->header([
            'Cache-Control' => 'no-store',
            'X-Request-Id' => $this->requestId(),
        ]);
    }

    private function context(): \PeanutAdmin\Kernel\Context\PlatformContext
    {
        if ($this->platformContext === null) {
            throw OpsConsoleException::denied();
        }
        return $this->platformContext->core;
    }

    private function windowMinutes(mixed $value): int
    {
        $candidate = is_int($value) ? (string) $value : trim((string) $value);
        if (!in_array($candidate, ['60', '360', '1440'], true)) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                'Diagnostic window is invalid.',
                ['error_code' => 'OPS_DIAGNOSTIC_WINDOW_INVALID'],
                42200,
            )->withHeaders(['Cache-Control' => 'no-store']);
        }
        return (int) $candidate;
    }

    private function idempotencyKey(): string
    {
        $value = trim((string) $this->request->header('Idempotency-Key', ''));
        if ($value === '') {
            throw OpsConsoleException::invalid();
        }
        return $value;
    }

    private function ifMatchRevision(bool $allowZero): int
    {
        $value = trim((string) $this->request->header('If-Match', ''));
        if (preg_match('/^"rev-([0-9]+)"$/D', $value, $matches) !== 1) {
            throw OpsConsoleException::invalid();
        }
        $revision = (int) $matches[1];
        if (($allowZero && $revision < 0) || (!$allowZero && $revision < 1)) {
            throw OpsConsoleException::revisionConflict();
        }
        return $revision;
    }
}
