<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition;

use app\platform\infrastructure\module\DeployedTenantModuleRegistry;

final readonly class DeployedReferenceCodeSetRegistry
{
    public function __construct(private DeployedTenantModuleRegistry $modules) {}

    public function build(): ReferenceCodeSetRegistry
    {
        $registry = new ReferenceCodeSetRegistry();
        $loader = new ReferenceCodeSetLoader();
        foreach ($this->modules->compiled()->modules as $manifest) {
            $key = (string) ($manifest->data['key'] ?? '');
            $backend = is_array($manifest->data['backend'] ?? null) ? $manifest->data['backend'] : [];
            $resource = $backend['reference_code_sets'] ?? null;
            $registry->registerModule($key, is_string($resource)
                ? $loader->load($key, $manifest->root . '/' . ltrim($resource, '/'))
                : []);
        }
        return $registry;
    }
}
