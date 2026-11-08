<?php

declare(strict_types=1);

namespace app\common\composition;

use app\common\services\CrontabCommandService;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\App;
use think\console\Command;

/** Selected native Module services register CLI metadata without granting scheduler authority. */
final class ModuleConsoleCommands
{
    /** @var array<string, class-string<Command>> */
    private array $commands = [];

    /** @return array<string, class-string<Command>> */
    public function contributions(): array
    {
        return $this->commands;
    }

    /** @param array<string, class-string<Command>> $contributions */
    public static function register(App $app, string $moduleKey, array $contributions): void
    {
        $registry = $app->make(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class);
        $registry->requireManifest($moduleKey);
        $staged = $app->make(self::class);
        $commands = $staged->commands;
        $owners = (array) $app->config->get('console.module_commands', []);
        foreach ($contributions as $name => $command) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)+$/D', $name) !== 1
                || !is_string($command) || !is_a($command, Command::class, true)
                || (new $command())->getName() !== $name) {
                throw new ModuleException('MODULE_COMMAND_INVALID', 'Invalid Module console command.');
            }
            if (array_key_exists($name, $commands) || array_key_exists($name, $owners)) {
                throw new ModuleException('MODULE_COMMAND_CONFLICT', "Module console command is duplicated: {$name}");
            }
            $owner = CrontabCommandService::tenantCommandOwner($name);
            if ($owner !== null && $owner !== $moduleKey) {
                throw new ModuleException('MODULE_COMMAND_INVALID', 'Tenant command owner does not match the approved Module.');
            }
            $commands[$name] = $command;
            if ($owner !== null) {
                $owners[$name] = $owner;
            }
        }
        $staged->commands = $commands;
        $app->config->set(['module_commands' => $owners], 'console');
    }
}
