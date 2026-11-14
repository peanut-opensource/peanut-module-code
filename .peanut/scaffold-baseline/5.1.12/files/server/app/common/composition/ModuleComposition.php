<?php

declare(strict_types=1);

namespace app\common\composition;

use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Kernel\Module\ModuleProviderBindings;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerContributor;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry;
use think\App;
use PeanutAdmin\Modules\File\Contract\FileDocumentReferenceContributor;
use PeanutAdmin\Modules\File\Contract\FileDocumentReferencePolicy;
use PeanutAdmin\Modules\File\Service\FileDocumentReferenceRegistry;
use PeanutAdmin\Modules\ContentTools\Contract\ContentToolContributor;
use PeanutAdmin\Modules\ContentTools\Contract\SurfaceAdapterRegistration;
use PeanutAdmin\Modules\ContentTools\Service\ContentToolRegistry;

/** Registers compiled Module bindings into the one ThinkPHP container. */
final readonly class ModuleComposition
{
    public function __construct(private App $app) {}

    public function register(CompiledModuleRegistry $registry): void
    {
        $providerClasses = [];
        $providers = [];
        $selected = array_column(array_map(static fn($manifest) => $manifest->data, $registry->modules), 'key');
        $taskSelected = in_array('peanut.task', $selected, true);
        $taskWorkers = [];
        $fileDocumentPolicy = null;
        $documentOwnerPolicies = [];
        $documentAcceptedProvider = null;
        $contentAdapters = [];
        $selectedModuleKeys = [];
        foreach ($registry->modules as $manifest) {
            $moduleKey = $manifest->data['key'] ?? null;
            $backend = $manifest->data['backend'] ?? null;
            $providerClass = is_array($backend) ? ($backend['provider'] ?? null) : null;
            if (!is_string($moduleKey) || $moduleKey === ''
                || !is_string($providerClass) || $providerClass === ''
                || isset($providerClasses[$providerClass]) || isset($selectedModuleKeys[$moduleKey])) {
                throw new ModuleException('MODULE_COMPOSITION_INVALID', 'Module provider identity is invalid or duplicated.');
            }

            $provider = $this->app->make($providerClass, [], true);
            if (!$provider instanceof ModuleProvider
                || !hash_equals($moduleKey, $provider->moduleKey())) {
                throw new ModuleException('MODULE_COMPOSITION_INVALID', "Module provider is invalid: {$moduleKey}");
            }
            $providerClasses[$providerClass] = $moduleKey;
            $providers[$moduleKey] = $provider;
            $selectedModuleKeys[$moduleKey] = true;
            if ($provider instanceof ContentToolContributor) {
                $registrations = $provider->contentToolRegistrations();
                if (!array_is_list($registrations)) {
                    throw new ModuleException('MODULE_CONTENT_TOOL_INVALID', 'Content tools must be typed list contributions.');
                }
                foreach ($registrations as $registration) {
                    if (!$registration instanceof SurfaceAdapterRegistration || $registration->providerModuleKey !== $moduleKey) {
                        throw new ModuleException('MODULE_CONTENT_TOOL_INVALID', 'Content tool provider identity does not match its compiled Module.');
                    }
                    $contentAdapters[] = $registration;
                }
            }
            if ($provider instanceof \PeanutAdmin\Modules\Document\Contract\DocumentOwnerContributor) {
                foreach ($provider->documentOwnerPolicies() as $type => $policy) {
                    $key = $moduleKey . ':' . $type;
                    if (!is_string($type) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $type) !== 1
                        || isset($documentOwnerPolicies[$key]) || !is_string($policy)
                        || !is_a($policy, \PeanutAdmin\Modules\Document\Contract\DocumentOwnerPolicy::class, true)) {
                        throw new ModuleException('MODULE_DOCUMENT_OWNER_POLICY_INVALID', 'Invalid business Document owner policy contribution.');
                    }
                    $documentOwnerPolicies[$key] = $policy;
                }
            }
            if ($provider instanceof FileDocumentReferenceContributor) {
                $policy = $provider->fileDocumentReferencePolicy();
                if ($moduleKey !== 'peanut.document' || $fileDocumentPolicy !== null
                    || !is_a($policy, FileDocumentReferencePolicy::class, true)) {
                    throw new ModuleException('MODULE_FILE_REFERENCE_POLICY_INVALID', 'Invalid Document resource policy contribution.');
                }
                $fileDocumentPolicy = $policy;
            }
            if ($provider instanceof \PeanutAdmin\Modules\Document\Contract\DocumentAcceptedWriteContributor) {
                $accepted = $provider->acceptedWriteProvider();
                if ($moduleKey !== 'peanut.collaboration' || $documentAcceptedProvider !== null
                    || !is_a($accepted, \PeanutAdmin\Modules\Document\Contract\DocumentAcceptedWriteProvider::class, true)) {
                    throw new ModuleException('MODULE_DOCUMENT_ACCEPTED_PROVIDER_INVALID', 'Invalid accepted Document write contribution.');
                }
                $documentAcceptedProvider = $accepted;
            }
            if ($provider instanceof TaskWorkerContributor) {
                foreach ($provider->taskWorkerDefinitions() as $definition) {
                    if (!is_string($definition) || !is_a($definition, TaskWorkerDefinition::class, true)) {
                        throw new ModuleException('MODULE_TASK_WORKER_INVALID', "Module Task worker is invalid: {$moduleKey}");
                    }
                    $taskWorkers[] = ['module_key' => $moduleKey, 'definition' => $definition];
                }
            }
        }

        $contentRegistry = null;
        if (isset($selectedModuleKeys['peanut.content-tools'])) {
            $contentRegistry = ContentToolRegistry::withBuiltins();
            usort($contentAdapters, static fn(SurfaceAdapterRegistration $a, SurfaceAdapterRegistration $b): int => strcmp($a->id, $b->id));
            foreach ($contentAdapters as $registration) {
                $contentRegistry->register($registration);
            }
        } elseif ($contentAdapters !== []) {
            throw new ModuleException('MODULE_CONTENT_TOOL_UNAVAILABLE', 'Content tool contributions require the selected Content Tools Module.');
        }
        $bindings = ModuleProviderBindings::collect(array_values($providers));
        // Validate optional contributions before publishing any container bindings.
        if (!isset($selectedModuleKeys['peanut.document'])
            && ($documentOwnerPolicies !== [] || $documentAcceptedProvider !== null)) {
            throw new ModuleException('MODULE_DOCUMENT_OWNER_UNAVAILABLE', 'Business owner contributions require the installed Document module.');
        }
        // Optional Task bindings come from selected providers, never a fixed business provider class.
        foreach ($taskSelected ? $providers : [] as $provider) {
            if (!is_callable([$provider, 'taskWorkerBindings'])) {
                continue;
            }
            $workerBindings = $provider->taskWorkerBindings();
            if (!is_array($workerBindings)) {
                throw new ModuleException('MODULE_BINDING_INVALID', 'Optional Task bindings must be an array.');
            }
            foreach ($workerBindings as $abstract => $concrete) {
                if (!is_string($abstract) || (!interface_exists($abstract) && !class_exists($abstract))
                    || (!is_string($concrete) && !$concrete instanceof \Closure)
                    || is_string($concrete) && (!class_exists($concrete) || !is_a($concrete, $abstract, true))) {
                    throw new ModuleException('MODULE_BINDING_INVALID', 'Optional Task binding is invalid.');
                }
                if (isset($bindings[$abstract])) {
                    throw new ModuleException('MODULE_BINDING_CONFLICT', "Module binding is duplicated: {$abstract}");
                }
                $bindings[$abstract] = $concrete;
            }
        }
        $this->assertAliasGraphIsAcyclic($bindings);
        foreach ($bindings as $abstract => $concrete) {
            if ($this->app->bound($abstract)) {
                throw new ModuleException('MODULE_BINDING_CONFLICT', "Module binding conflicts with Host binding: {$abstract}");
            }
        }
        if (isset($bindings[\PeanutAdmin\Modules\Document\Service\DocumentAcceptedWriteRegistry::class])
            || isset($bindings[\PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry::class])
            || isset($bindings[TaskWorkerDefinitionRegistry::class]) || isset($bindings[FileDocumentReferenceRegistry::class])
            || $this->app->bound(TaskWorkerDefinitionRegistry::class) || $this->app->bound(FileDocumentReferenceRegistry::class)
            || $this->app->bound(\PeanutAdmin\Modules\Document\Service\DocumentAcceptedWriteRegistry::class)
            || $this->app->bound(\PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry::class)) {
            throw new ModuleException('MODULE_BINDING_CONFLICT', 'Task worker registry conflicts with a Host binding.');
        }
        foreach ($providerClasses as $providerClass => $moduleKey) {
            if ($this->app->bound($providerClass) || isset($bindings[$providerClass])) {
                throw new ModuleException('MODULE_BINDING_CONFLICT', "Module provider conflicts with a binding: {$providerClass}");
            }
        }
        if ($contentRegistry !== null && ($this->app->bound(ContentToolRegistry::class) || isset($bindings[ContentToolRegistry::class]))) {
            throw new ModuleException('MODULE_BINDING_CONFLICT', 'Content tool registry conflicts with a Host or Module binding.');
        }
        // Optional providers reuse the same typed worker registry without requiring Task at load time.
        if ($taskSelected) {
            foreach ($providers as $provider) {
                if ($provider instanceof TaskWorkerContributor
                    || !is_callable([$provider, 'taskWorkerDefinitions'])) {
                    continue;
                }
                $definitions = $provider->taskWorkerDefinitions();
                if (!is_array($definitions) || !array_is_list($definitions)) {
                    throw new ModuleException('MODULE_TASK_WORKER_INVALID', 'Optional workers must be a typed list.');
                }
                foreach ($definitions as $definition) {
                    if (!is_string($definition) || !is_a($definition, TaskWorkerDefinition::class, true)) {
                        throw new ModuleException('MODULE_TASK_WORKER_INVALID', 'Optional worker contribution is invalid.');
                    }
                    $taskWorkers[] = ['module_key' => $provider->moduleKey(), 'definition' => $definition];
                }
            }
        }
        $taskRegistry = $taskSelected ? new TaskWorkerDefinitionRegistry($taskWorkers) : null;
        foreach ($bindings as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
        foreach ($providerClasses as $providerClass => $moduleKey) {
            $this->app->instance($providerClass, $providers[$moduleKey]);
        }
        if ($taskRegistry !== null) {
            $this->app->instance(TaskWorkerDefinitionRegistry::class, $taskRegistry);
        }
        if ($contentRegistry !== null) {
            $this->app->instance(ContentToolRegistry::class, $contentRegistry);
        }
        $this->app->instance(FileDocumentReferenceRegistry::class, new FileDocumentReferenceRegistry($this->app, $fileDocumentPolicy));
        if (isset($selectedModuleKeys['peanut.document'])) {
            $this->app->instance(
                \PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry::class,
                new \PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry($this->app, $documentOwnerPolicies),
            );
            $this->app->instance(
                \PeanutAdmin\Modules\Document\Service\DocumentAcceptedWriteRegistry::class,
                new \PeanutAdmin\Modules\Document\Service\DocumentAcceptedWriteRegistry($this->app, $documentAcceptedProvider),
            );
        }

        $dataPermissions = new \PeanutAdmin\DataPermission\Runtime\DataPermissionRuntimeRegistry();
        foreach ($providers as $provider) {
            if ($provider instanceof \PeanutAdmin\DataPermission\Runtime\DataPermissionModuleProvider) {
                $provider->registerDataPermission($dataPermissions);
            }
        }
        $this->app->instance(\PeanutAdmin\DataPermission\Runtime\DataPermissionRuntimeRegistry::class, $dataPermissions);
        $this->app->instance(\PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry::class, $dataPermissions->resourceProviders);
        $this->app->instance(\PeanutAdmin\DataPermission\Target\TargetResolverRegistry::class, $dataPermissions->targetResolvers);
        $this->app->instance(\PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry::class, $dataPermissions->targetCatalogProviders);
        $this->app->instance(\PeanutAdmin\DataPermission\Provider\SharedMasterScopeProviderRegistry::class, $dataPermissions->sharedMasterProviders);
    }

    /** @param array<class-string, class-string|\Closure> $bindings */
    private function assertAliasGraphIsAcyclic(array $bindings): void
    {
        foreach ($bindings as $abstract => $concrete) {
            if (!is_string($concrete)) {
                continue;
            }
            $seen = [$abstract => true];
            $next = $concrete;
            while (isset($bindings[$next]) && is_string($bindings[$next])) {
                if (isset($seen[$next])) {
                    throw new ModuleException('MODULE_BINDING_CONFLICT', "Cyclic Module binding: {$abstract}");
                }
                $seen[$next] = true;
                $next = $bindings[$next];
            }
        }
    }
}
