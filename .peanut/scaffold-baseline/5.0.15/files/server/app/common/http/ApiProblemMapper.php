<?php

declare(strict_types=1);

namespace app\common\http;

use app\common\exception\BusinessException;
use app\common\exception\module\ModuleScaffoldException;
use app\common\exception\installation\InstallationExecutionException;
use app\common\exception\http\OutboundHttpException;
use app\common\exception\storage\StorageProviderException;
use PeanutAdmin\Modules\Identity\Invitation\TenantOwnerInvitationException;
use app\platform\exception\PlatformRefreshCredentialException;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\exception\plugin\PluginPackageException;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use think\exception\RouteNotFoundException;
use think\exception\HttpException;
use think\exception\ValidateException;

/** Maps stable domain failures into the single public API error type. */
final class ApiProblemMapper
{
    public function map(\Throwable $exception): ?ApiProblem
    {
        return match (true) {
            $exception instanceof ApiProblem => $exception,
            $exception instanceof RouteNotFoundException => new ApiProblem(
                'ROUTE_NOT_FOUND',
                404,
                'Route not found.',
            ),
            $exception instanceof HttpException
                && $exception->getStatusCode() >= 400 && $exception->getStatusCode() <= 599 => new ApiProblem(
                    'HTTP_ERROR_' . $exception->getStatusCode(),
                    $exception->getStatusCode(),
                    'HTTP request was rejected.',
                    headers: $exception->getHeaders(),
                ),
            $exception instanceof BusinessException => new ApiProblem(
                $exception->errorCode,
                $exception->httpStatus,
                $exception->getMessage(),
            ),
            $exception instanceof OutboundHttpException => new ApiProblem(
                OutboundHttpException::ERROR_CODE,
                OutboundHttpException::HTTP_STATUS,
                $exception->getMessage(),
            ),
            $exception instanceof StorageProviderException => new ApiProblem(
                $exception->errorCode,
                $exception->httpStatus,
                $exception->getMessage(),
            ),
            $exception instanceof ValidateException => ApiProblem::fromEnvelope(
                (string) $exception->getError(),
            ),
            $exception instanceof \InvalidArgumentException => new ApiProblem(
                'INVALID_ARGUMENT',
                422,
                $exception->getMessage(),
            ),
            $exception instanceof AuthException => new ApiProblem(
                $exception->errorCode,
                $exception->httpStatus,
                $exception->getMessage(),
            ),
            $exception instanceof PlatformRefreshCredentialException => ApiProblem::fromEnvelope(
                PlatformRefreshCredentialException::MESSAGE,
                ['error_code' => PlatformRefreshCredentialException::ERROR_CODE],
                40100,
            ),
            $exception instanceof AdminAccessException => new ApiProblem(
                $exception->errorCode,
                $exception->httpStatus,
                $exception->getMessage(),
            ),
            $exception instanceof TenantOwnerInvitationException => new ApiProblem(
                $exception->errorCode,
                $exception->httpStatus,
                $exception->getMessage(),
            ),
            $exception instanceof InstallationExecutionException => new ApiProblem(
                $exception->errorCode,
                $exception->httpStatus,
                $exception->getMessage(),
                $exception->details(),
            ),
            $exception instanceof OpsConsoleException => (new ApiProblem(
                $exception->problemCode,
                $exception->status,
                'Operations request was rejected.',
            ))->withHeaders(['Cache-Control' => 'no-store']),
            $exception instanceof PluginPackageException
                && (str_starts_with($exception->errorCode, 'MODULE_DEPENDENC')
                    || in_array($exception->errorCode, ['MODULE_REQUIRED_DEPENDENCY_UNAVAILABLE',
                        'MODULE_DEVELOPMENT_DEPENDENCY_UNSATISFIED', 'MODULE_WORKSPACE_CONFIGURATION_REQUIRED'], true)) => new ApiProblem(
                    $exception->errorCode,
                    $this->moduleLifecycleStatus($exception->errorCode),
                    $exception->getMessage(),
                    $exception->details,
                ),
            $exception instanceof PluginLifecycleException
                || $exception instanceof PluginPackageException
                || $exception instanceof ModuleScaffoldException => new ApiProblem(
                    $exception->errorCode,
                    $this->moduleLifecycleStatus($exception->errorCode),
                    'Module runtime request was rejected.',
                ),
            $exception instanceof ModuleException => new ApiProblem(
                $exception->errorCode,
                $this->moduleStatus($exception->errorCode),
                'Module request was rejected.',
            ),
            default => null,
        };
    }

    private function moduleStatus(string $errorCode): int
    {
        return match ($errorCode) {
            'MODULE_TENANT_DISABLED',
            'MODULE_OPERATION_NOT_ALLOWED',
            'CONTEXT_TENANT_REQUIRED' => 403,
            'MODULE_NOT_INSTALLED',
            'MODULE_INSTALLATION_FAILED',
            'MODULE_REGISTRY_UNAVAILABLE' => 503,
            default => 409,
        };
    }

    private function moduleLifecycleStatus(string $errorCode): int
    {
        return match (true) {
            in_array($errorCode, ['MODULE_REGISTRY_UNAVAILABLE', 'PLUGIN_LOCK_INVALID', 'PLUGIN_ARTIFACT_MISMATCH'], true) => 503,
            str_contains($errorCode, 'PLAN_CHANGED'), str_contains($errorCode, 'CONFLICT'), str_contains($errorCode, 'DEPENDENT'),
            str_contains($errorCode, 'TENANT_MODULE_ACTIVE'), str_contains($errorCode, 'STATE'), str_contains($errorCode, 'IN_PROGRESS'),
            $errorCode === 'MODULE_UNINSTALL_BLOCKED', $errorCode === 'MODULE_CREATE_TARGET_EXISTS' => 409,
            default => 422,
        };
    }
}
