<?php

declare(strict_types=1);

namespace app\common\composition;

use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\Console;
use think\Service;

/** Registers compiled Module services with the native ThinkPHP lifecycle. */
final class ModuleExtensionService extends Service
{
    public function register(): void
    {
        // Source authoring can boot before a compiled deployment registry exists.
        if (!$this->app->bound(CompiledModuleRegistry::class)) {
            return;
        }
        $registry = $this->app->make(CompiledModuleRegistry::class);
        foreach ($registry->modules as $manifest) {
            $moduleKey = $manifest->data['key'] ?? null;
            $backend = $manifest->data['backend'] ?? null;
            $providerClass = is_array($backend) ? ($backend['provider'] ?? null) : null;
            if (!is_string($moduleKey) || $moduleKey === '' || !is_string($providerClass) || $providerClass === '') {
                throw new ModuleException('MODULE_COMPOSITION_INVALID', 'Compiled Module provider identity is invalid.');
            }
            $provider = $this->app->make($providerClass);
            if ($provider instanceof Service) {
                $this->app->register($provider);
            }
        }
        $commands = $this->app->make(ModuleConsoleCommands::class);
        // Console construction has already loaded native defaults, config and Service commands.
        $this->app->resolving('console', static function (Console $console) use ($commands): void {
            $contributions = $commands->contributions();
            foreach (array_keys($contributions) as $name) {
                if ($console->hasCommand($name)) {
                    throw new ModuleException('MODULE_COMMAND_CONFLICT', "Module console command is duplicated: {$name}");
                }
            }
            $console->addCommands($contributions);
        });
    }
}
