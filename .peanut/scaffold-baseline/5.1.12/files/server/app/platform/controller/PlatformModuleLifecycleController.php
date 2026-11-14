<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\http\PageResult;
use app\platform\services\module\PlatformModuleRuntimeService;
use app\platform\services\module\OfficialModuleReleaseService;
use app\platform\infrastructure\module\DeterministicTarArchive;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\exception\module\ModulePackageException;

/**
 * @property-read PlatformModuleRuntimeService $moduleRuntime 当前 App 中声明式解析的控制器依赖。
 * @property-read OfficialModuleReleaseService $officialReleases
 */
final class PlatformModuleLifecycleController extends BasePlatformController
{
    protected string $moduleRuntimeClass = PlatformModuleRuntimeService::class;
    protected string $officialReleasesClass = OfficialModuleReleaseService::class;

    public function updates()
    {
        $channel = $this->request->get('channel', 'stable');
        if (!is_string($channel)) {
            throw new ModulePackageException('MODULE_RELEASE_CHANNEL_INVALID', 'Select stable or prerelease.');
        }
        return $this->data($this->officialReleases->updates($channel));
    }

    /** The same developer gate and native worker apply to local and official packages. */
    public function officialUpdate()
    {
        if ($this->platformContext === null) {
            throw new ModuleLifecycleException('MODULE_CONTEXT_INVALID', 'A validated Platform session is required.');
        }
        $input = $this->request->post();
        if (array_diff(array_keys($input), ['module_key', 'version', 'sha256', 'catalog_tag', 'channel', 'dry_run', 'confirm_selection_sha256']) !== []
            || !is_bool($input['dry_run'] ?? null)) {
            throw new ModulePackageException('MODULE_RELEASE_SELECTION_INVALID', 'Provide an exact official package and explicit dry_run.');
        }
        $selection = [];
        foreach (['module_key', 'version', 'sha256', 'catalog_tag', 'channel'] as $key) {
            $selection[$key] = $input[$key] ?? ($key === 'channel' ? 'stable' : null);
        }
        $idempotency = trim((string) $this->request->header('Idempotency-Key', ''));
        if (!$input['dry_run'] && (strlen($idempotency) < 8 || strlen($idempotency) > 200
            || preg_match('/^[\x21-\x7e]+$/D', $idempotency) !== 1
            || !is_string($input['confirm_selection_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $input['confirm_selection_sha256']) !== 1)) {
            throw new ModulePackageException('MODULE_RELEASE_CONFIRMATION_REQUIRED', 'Confirm the reviewed native plan with an Idempotency-Key.');
        }
        $digest = hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (!$input['dry_run'] && !hash_equals($digest, $input['confirm_selection_sha256'])) {
            throw new ModulePackageException('MODULE_RELEASE_SELECTION_CHANGED', 'The reviewed target changed; request a fresh preview.');
        }
        $package = $this->officialReleases->package($selection);
        $file = tmpfile();
        if ($file === false) {
            throw new ModulePackageException('MODULE_PACKAGE_WRITE_FAILED', 'Unable to stage the verified package.');
        }
        try {
            if (fwrite($file, $package['bytes']) !== strlen($package['bytes']) || !fflush($file)) {
                throw new ModulePackageException('MODULE_PACKAGE_WRITE_FAILED', 'Unable to stage the verified package.');
            }
            $path = realpath(stream_get_meta_data($file)['uri']);
            unset($package['bytes']);
            if ($input['dry_run']) {
                $plan = $this->moduleRuntime->update($path, $package['sha256'], null, true, $this->platformContext->core);
                return $this->data(['dry_run' => true, 'plan' => $plan, 'selection_sha256' => $digest]);
            }
            // The original idempotent queue owns retries. Do not repeat source preflight here:
            // a previous request may already be running after its HTTP response was lost.
            // The worker rechecks current version, permissions, dependencies and migration state.
            return $this->data($this->moduleRuntime->update(
                $path,
                $package['sha256'],
                null,
                false,
                $this->platformContext->core,
                $idempotency,
            ));
        } finally {
            fclose($file);
        }
    }

    public function lists()
    {
        $page = $this->positiveInteger($this->request->get('page', 1));
        $pageSize = $this->positiveInteger($this->request->get('page_size', 20));
        if ($pageSize > 100) {
            throw new ModuleLifecycleException('PAGE_SIZE_INVALID', 'Page size is invalid.');
        }
        $moduleKey = trim((string) $this->request->get('module_key', ''));
        $result = $this->moduleRuntime->modules($page, $pageSize, $moduleKey === '' ? null : $moduleKey);
        return $this->dataLists(new PageResult($result['items'], $result['total'], $page, $pageSize));
    }

    public function install()
    {
        return $this->packageOperation('install');
    }

    public function update()
    {
        return $this->packageOperation('update');
    }

    public function task(string $task_key)
    {
        if ($this->platformContext === null) {
            throw new ModuleLifecycleException('MODULE_CONTEXT_INVALID', 'A validated Platform session is required.');
        }
        return $this->data($this->moduleRuntime->task($this->platformContext->core, $task_key));
    }

    private function packageOperation(string $operation)
    {
        $uploaded = $this->request->file('package');
        $expected = strtolower(trim((string) $this->request->post('expected_sha256', '')));
        $keyId = trim((string) $this->request->post('signature_key_id', ''));
        if (!$uploaded || strtolower((string) $uploaded->getOriginalExtension()) !== 'tar'
            || $uploaded->getSize() <= 0 || $uploaded->getSize() > DeterministicTarArchive::MAX_TOTAL_BYTES
            || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            throw new ModulePackageException('MODULE_PACKAGE_REQUEST_INVALID', 'Module package request is invalid.');
        }
        if ($this->platformContext === null) {
            throw new ModuleLifecycleException('MODULE_CONTEXT_INVALID', 'A validated Platform session is required.');
        }
        $arguments = [$uploaded->getPathname(), $expected, $keyId === '' ? null : $keyId];
        $context = $this->platformContext->core;
        $idempotency = trim((string) $this->request->header('Idempotency-Key', ''));
        return $this->data($operation === 'install'
            ? $this->moduleRuntime->install(...[...$arguments, $context, $idempotency])
            : $this->moduleRuntime->update(...[...$arguments, false, $context, $idempotency]));
    }

    public function create()
    {
        $input = $this->request->post();
        if (array_diff(array_keys($input), ['module_key', 'vendor', 'client']) !== []
            || !is_string($input['module_key'] ?? null)
            || (array_key_exists('vendor', $input) && !is_string($input['vendor']))
            || (array_key_exists('client', $input) && !is_string($input['client']))) {
            throw new ModuleLifecycleException('MODULE_CREATE_REQUEST_INVALID', 'Module creation accepts only declared string fields.');
        }
        $vendor = trim($input['vendor'] ?? '');
        return $this->data($this->moduleRuntime->create(
            trim($input['module_key']),
            $vendor === '' ? null : $vendor,
            $input['client'] ?? 'none',
        ));
    }

    public function uninstall()
    {
        $params = $this->request->post();
        $moduleKey = $this->moduleKey($params['module_key'] ?? null);
        $purge = $this->boolean($params['purge'] ?? false, 'purge');
        $preview = $this->boolean($params['preview'] ?? null, 'preview');
        if ($preview) {
            return $this->data($this->moduleRuntime->uninstallPreview($moduleKey, $purge));
        }
        $this->changeReason($params['change_reason'] ?? null);
        $plan = $params['confirm_plan'] ?? null;
        $digest = strtolower(trim((string) ($params['confirm_plan_digest'] ?? '')));
        $packageKey = trim((string) ($params['confirm_package_key'] ?? ''));
        if (!is_array($plan) || array_is_list($plan) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || $packageKey === '' || ($plan['package_key'] ?? null) !== $packageKey) {
            throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Module uninstall confirmation is invalid.');
        }
        if ($this->platformContext === null) {
            throw new ModuleLifecycleException('MODULE_CONTEXT_INVALID', 'A validated Platform session is required.');
        }
        return $this->data($this->moduleRuntime->uninstall(
            $moduleKey,
            $purge,
            $plan,
            $digest,
            $this->platformContext->core,
            trim((string) $this->request->header('Idempotency-Key', '')),
        ));
    }

    public function disable()
    {
        $params = $this->request->post();
        $moduleKey = $this->moduleKey($params['module_key'] ?? null);
        $this->changeReason($params['change_reason'] ?? null);
        return $this->data($this->moduleRuntime->disable($moduleKey));
    }

    public function sync()
    {
        $moduleKey = trim((string) $this->request->post('module_key', ''));
        if ($moduleKey !== '') {
            $this->moduleKey($moduleKey);
        }
        return $this->data($this->moduleRuntime->sync($moduleKey === '' ? null : $moduleKey));
    }

    private function moduleKey(mixed $value): string
    {
        $key = trim((string) $value);
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $key) !== 1 || strlen($key) > 96) {
            throw new ModuleLifecycleException('MODULE_KEY_INVALID', 'Module key is invalid.');
        }
        return $key;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new ModuleLifecycleException('MODULE_REQUEST_INVALID', "{$field} must be boolean.");
        }
        return $value;
    }

    private function changeReason(mixed $value): string
    {
        $reason = trim((string) $value);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new ModuleLifecycleException('MODULE_CHANGE_REASON_INVALID', 'Change reason is invalid.');
        }
        return $reason;
    }

    private function positiveInteger(mixed $value): int
    {
        $candidate = is_int($value) ? (string) $value : trim((string) $value);
        if (preg_match('/^[1-9][0-9]*$/D', $candidate) !== 1) {
            throw new ModuleLifecycleException('PAGE_INVALID', 'Page is invalid.');
        }
        return (int) $candidate;
    }

}
