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
        $workflowSelected = in_array('peanut.workflow', $selected, true);
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
            $providerClasses[$providerClass] = true;
            $providers[$providerClass] = $provider;
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
        $contributors = [];
        if ($workflowSelected) {
            foreach ($providers as $provider) {
                if ($provider instanceof \PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioContributor) {
                    $contributors[] = $provider;
                }
            }
        }
        $bindings = ModuleProviderBindings::collect(array_values($providers));
        if ($workflowSelected) {
            if (isset($bindings[\PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry::class])) {
                throw new ModuleException('MODULE_BINDING_CONFLICT', 'Workflow Scenario registry belongs to the composition root.');
            }
            $bindings[\PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry::class] = static function (App $app) use ($contributors) {
                $scenarios = new \PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry();
                foreach ($contributors as $provider) {
                    $provider->registerWorkflowScenarios($scenarios, $app);
                }
                return $scenarios;
            };
        }
        if (isset($selectedModuleKeys['peanut.notification'])) {
            if (isset($bindings[\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class])) {
                throw new ModuleException('MODULE_BINDING_CONFLICT', 'Notification producer registry belongs to the composition root.');
            }
            $bindings[\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class] = static function (App $app) use ($contributors) {
                $producers = new \PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry();
                foreach ($contributors as $provider) {
                    if (method_exists($provider, 'notificationProducers')) {
                        foreach ($provider->notificationProducers($app) as $producer) {
                            $producers->register($producer);
                        }
                    }
                }
                return $producers;
            };
        }
        // This optional Task capability is loaded only for the compiled two-module selection.
        if ($taskSelected && isset($selectedModuleKeys['peanut.collaboration'])) {
            $provider = $providers[\PeanutAdmin\Modules\Collaboration\ModuleProvider::class] ?? null;
            if (!$provider instanceof \PeanutAdmin\Modules\Collaboration\ModuleProvider) {
                throw new ModuleException('MODULE_TASK_WORKER_INVALID', 'Collaboration worker provider is invalid.');
            }
            foreach ($provider->taskWorkerBindings() as $abstract => $concrete) {
                if (!is_string($abstract) || (!interface_exists($abstract) && !class_exists($abstract))
                    || (!is_string($concrete) && !$concrete instanceof \Closure)
                    || is_string($concrete) && (!class_exists($concrete) || !is_a($concrete, $abstract, true))) {
                    throw new ModuleException('MODULE_BINDING_INVALID', 'Collaboration Task binding is invalid.');
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
            || $this->app->bound(TaskWorkerDefinitionRegistry::class) || $this->app->bound(FileDocumentReferenceRegistry::class)
            || $this->app->bound(\PeanutAdmin\Modules\Document\Service\DocumentAcceptedWriteRegistry::class)
            || $this->app->bound(\PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry::class)) {
            throw new ModuleException('MODULE_BINDING_CONFLICT', 'Task worker registry conflicts with a Host binding.');
        }
        foreach ($providers as $providerClass => $provider) {
            if ($this->app->bound($providerClass) || isset($bindings[$providerClass])) {
                throw new ModuleException('MODULE_BINDING_CONFLICT', "Module provider conflicts with a binding: {$providerClass}");
            }
        }
        if ($contentRegistry !== null && ($this->app->bound(ContentToolRegistry::class) || isset($bindings[ContentToolRegistry::class]))) {
            throw new ModuleException('MODULE_BINDING_CONFLICT', 'Content tool registry conflicts with a Host or Module binding.');
        }
        foreach ($bindings as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
        foreach ($providers as $providerClass => $provider) {
            $this->app->instance($providerClass, $provider);
        }
        // Only the selected Workflow + File composition supplies the default source snapshot.
        if ($workflowSelected && isset($selectedModuleKeys['peanut.file'])
            && !$this->app->bound(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachmentResolver::class)) {
            $this->app->bind(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachmentResolver::class, \PeanutAdmin\Modules\Workflow\Integration\FileWorkflowAttachmentResolver::class);
        }
        // Optional worker contributions are collected after selected module bindings exist.
        if ($taskSelected && $workflowSelected) {
            foreach ($providers as $provider) {
                if ($provider instanceof TaskWorkerContributor
                    || !$provider instanceof \PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioContributor
                    || !method_exists($provider, 'taskWorkerDefinitions')) {
                    continue;
                }
                foreach ($provider->taskWorkerDefinitions() as $definition) {
                    if (!is_string($definition) || !is_a($definition, TaskWorkerDefinition::class, true)) {
                        throw new ModuleException('MODULE_TASK_WORKER_INVALID', 'Workflow worker contribution is invalid.');
                    }
                    $taskWorkers[] = ['module_key' => $provider->moduleKey(), 'definition' => $definition];
                }
            }
        }
        if ($taskSelected && isset($selectedModuleKeys['peanut.collaboration'])) {
            foreach ($providers[\PeanutAdmin\Modules\Collaboration\ModuleProvider::class]->taskWorkerDefinitions() as $definition) {
                if (!is_string($definition) || !is_a($definition, TaskWorkerDefinition::class, true)
                    || !is_a($definition, TaskWorkerContributor::class, true)) {
                    throw new ModuleException('MODULE_TASK_WORKER_INVALID', 'Collaboration worker contribution is invalid.');
                }
                $taskWorkers[] = ['module_key' => 'peanut.collaboration', 'definition' => $definition];
            }
        }
        $taskRegistry = new TaskWorkerDefinitionRegistry($taskWorkers);
        $this->app->instance(TaskWorkerDefinitionRegistry::class, $taskRegistry);
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
        } elseif ($documentOwnerPolicies !== [] || $documentAcceptedProvider !== null) {
            throw new ModuleException('MODULE_DOCUMENT_OWNER_UNAVAILABLE', 'Business owner contributions require the installed Document module.');
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
        if ($workflowSelected && $taskSelected) {
            $this->app->bind(\PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher::class, function () use ($contributors) {
                $submissions = [];
                foreach ($contributors as $provider) {
                    array_push($submissions, ...$provider->workflowTaskSubmissions($this->app));
                }
                return $this->app->make(\PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class)->publisher(...$submissions);
            });
        }
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
