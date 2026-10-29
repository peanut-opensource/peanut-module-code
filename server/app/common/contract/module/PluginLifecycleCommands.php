<?php

declare(strict_types=1);

namespace app\common\contract\module;

interface PluginLifecycleCommands
{
    /** @return array<string,mixed> */
    public function install(string $pluginKey): array;

    /** Reads the actual install/resume policy without changing lifecycle state.
     * Optional native consumer runs before the same package advisory lock is released.
     * @return array<string,mixed>
     */
    public function installPlan(string $pluginKey, ?callable $consumePlan = null): array;

    /** @return array<string,mixed> */
    public function reconcile(string $pluginKey): array;

    /** Prepare the selected already installed graph once, then reconcile it in dependency order. */
    public function reconcileInstalled(array $pluginKeys): array;

    /** Reads a release reconcile action without changing installation, catalog or migration state.
     * Optional native consumer runs before the same package advisory lock is released.
     */
    public function reconcilePlan(string $pluginKey, bool $preserveDisabled = false, ?callable $consumePlan = null): array;

    /** A consumer is allowed only for dry-run and runs inside the existing package lock.
     * @return array<string,mixed>
     */
    public function upgrade(string $pluginKey, bool $dryRun, ?callable $consumePlan = null): array;

    /** @return array<string,mixed> */
    public function rollbackPlan(string $pluginKey): array;

    /** @return array<string,mixed> */
    public function uninstall(string $pluginKey): array;
}
