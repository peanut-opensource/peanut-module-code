<?php

declare(strict_types=1);

namespace app\platform\validation\module;

use PeanutAdmin\DataPermission\Provider\ResourceQueryPolicyProvider;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProvider;
use PeanutAdmin\DataPermission\Target\ResourceTargetCatalogProvider;
use PeanutAdmin\DataPermission\Target\ResourceTargetResolver;
use PeanutAdmin\Kernel\Module\ContractInspector;

final class ReflectionContractInspector implements ContractInspector
{
    private const CONTRACTS = [
        'TargetResolver' => ResourceTargetResolver::class,
        'TargetCatalogProvider' => ResourceTargetCatalogProvider::class,
        'ResourceQueryPolicyProvider' => ResourceQueryPolicyProvider::class,
        'SharedMasterScopeProvider' => SharedMasterScopeProvider::class,
    ];

    public function classExists(string $class): bool
    {
        return class_exists($class) || interface_exists($class);
    }

    public function implements(string $class, string $contract): bool
    {
        $interface = self::CONTRACTS[$contract] ?? $contract;

        return $this->classExists($class) && is_a($class, $interface, true);
    }
}
