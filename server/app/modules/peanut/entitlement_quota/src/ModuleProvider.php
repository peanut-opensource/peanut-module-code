<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\EntitlementQuota;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

/** 官方维护的可选业务模块；注册定义不启用租户、不执行迁移或读取当前身份。 */
final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.entitlement-quota';
    }

    public function bindings(): array
    {
        // 业务端口由明确的调用模块注入；缺少实现时容器拒绝，不提供放行替代。
        return [];
    }
}
