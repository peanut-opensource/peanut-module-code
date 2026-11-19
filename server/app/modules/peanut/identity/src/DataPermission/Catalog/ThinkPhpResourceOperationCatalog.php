<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\DataPermission\Catalog;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;

/** Lightweight catalog adapter for standard role data-scope policies. */
final class ThinkPhpResourceOperationCatalog implements ResourceOperationCatalog
{
    public function find(string $resourceKey, string $operation): ?ResourceOperation
    {
        return null;
    }

    public function findMany(array $operations): array
    {
        return [];
    }

    public function availableOperations(int $tenantId, PageRequest $page): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function moduleAvailable(int $tenantId, string $moduleKey): bool
    {
        return true;
    }

    public function registryRevision(): string
    {
        return hash('sha256', 'standard-data-scope.v1');
    }
}
