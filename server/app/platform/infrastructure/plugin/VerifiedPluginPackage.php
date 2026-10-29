<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use app\platform\value\plugin\PluginDescriptor;

/** Immutable package inventory passed from archive verification into lifecycle application. */
final readonly class VerifiedPluginPackage
{
    /**
     * @param array<string,string> $inventory
     * @param array<string,array{
     *   key:string,version:string,backend_relative:string,frontend_relative:?string,
     *   frontend_contributions:list<array{client_key:string,entry:string,root:string}>,
     *   manifest:\PeanutAdmin\Kernel\Module\ManifestDocument,
     *   dependencies:list<array{module_key:string,version:string}>,owned_tables:list<string>
     * }> $modules
     * @param list<string> $dependencyOrder
     */
    public function __construct(
        public string $archiveSha256,
        public string $packageKey,
        public string $packageVersion,
        public string $stageRoot,
        public string $manifestRelative,
        public PluginDescriptor $descriptor,
        public array $inventory,
        public array $modules,
        public array $dependencyOrder,
    ) {}
}
