<?php

declare(strict_types=1);

namespace app\api\services;

use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\OAuth\Contract\OfficialAccountCallbacks;

/** 公众号回调用例；控制器保留原文读取和响应格式，本入口拥有验签后的范围及模块编排。 */
final readonly class OfficialAccountApplicationService
{
    public function __construct(
        private OfficialAccountCallbacks $officialAccount,
        private ExecutionContextStore $executionContexts,
        private ModuleExecutionBoundary $modules,
        private ExternalTenantResolutionService $externalTenants,
    ) {}

    /** @param array<string,mixed> $params */
    public function verify(string $binding, array $params, string $operationId): void
    {
        $resolution = $this->externalTenants->verifiedCallback(
            ExternalProvider::WECHAT_OFFICIAL_CALLBACK,
            $binding,
            'wechat.official.verify',
            $operationId,
            fn(array $config): bool => $this->officialAccount->verify($params, $config),
        );
        $this->executionContexts->run(
            new SystemExecutionContext($resolution->context),
            fn() => $this->modules->assertExternalCallback('peanut.oauth'),
        );
    }

    /** @param array<string,mixed> $params */
    public function callback(string $binding, array $params, string $rawBody, string $operationId): string
    {
        $resolution = $this->externalTenants->verifiedCallback(
            ExternalProvider::WECHAT_OFFICIAL_CALLBACK,
            $binding,
            'wechat.official.callback',
            $operationId,
            fn(array $config): bool => strtolower((string) ($params['encrypt_type'] ?? '')) !== 'aes'
                && $this->officialAccount->verify($params, $config),
        );
        return $this->executionContexts->run(
            new SystemExecutionContext($resolution->context),
            function () use ($resolution, $rawBody): string {
                $this->modules->assertExternalCallback('peanut.oauth');
                return $this->officialAccount->handlePlain($resolution->context, $rawBody);
            },
        );
    }
}
