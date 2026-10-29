<?php

declare(strict_types=1);

namespace app\common\services;

/** 定时任务只允许调用显式注册且非调度器自身的控制台命令。 */
class CrontabCommandService
{
    private const TENANT_AWARE_COMMANDS = [
        'crontab:demo',
        'refund:reconcile',
    ];

    public function __construct(
        private readonly array $configuredCommands,
        private readonly array $moduleCommands,
    ) {}

    public function allowedCommands(): array
    {
        $commands = array_keys($this->configuredCommands);
        return array_values(array_filter(
            $commands,
            static fn(string $command): bool => $command !== 'crontab',
        ));
    }

    public function assertAllowed(string $command): void
    {
        if (!in_array($command, $this->allowedCommands(), true)) {
            throw new \RuntimeException('定时任务命令未注册或不允许调度');
        }
    }

    public function assertTenantAware(string $command): void
    {
        $this->assertAllowed($command);
        if (!in_array($command, self::TENANT_AWARE_COMMANDS, true) || $this->moduleKey($command) === null) {
            throw new \RuntimeException('定时任务命令尚未采用可信租户上下文');
        }
    }

    public function moduleKey(string $command): ?string
    {
        $module = $this->moduleCommands[trim($command)] ?? null;
        if ($module === null || trim((string) $module) === '') {
            return null;
        }
        $module = trim((string) $module);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,127}$/D', $module) !== 1) {
            throw new \RuntimeException('定时任务所属模块标识无效');
        }
        return $module;
    }
}
