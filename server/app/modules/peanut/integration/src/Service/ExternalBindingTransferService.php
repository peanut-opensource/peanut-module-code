<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Service;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingTransfer;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use RuntimeException;
use think\facade\Db;

/** Integration owns binding persistence; Identity owns lifecycle reads and tenant locks. */
final readonly class ExternalBindingTransferService implements ExternalBindingTransfer
{
    public function __construct(private AdminDirectoryQuery $directory) {}

    public function snapshot(TenantContext $context): array
    {
        $this->assertContext($context);
        $states = [];
        foreach (Db::name('external_channel_binding')->where('tenant_id', $context->tenantId)
            ->field('provider,identity_hash,identity_hint,config_json,status,update_time')->order('provider')->select()->toArray() as $row) {
            $states[] = $this->state((string) ($row['provider'] ?? ''), $row);
        }
        return $states;
    }

    public function current(TenantContext $context, string $provider): ?array
    {
        $this->assertContext($context);
        $this->assertProvider($provider);
        $row = Db::name('external_channel_binding')->where('tenant_id', $context->tenantId)->where('provider', $provider)
            ->field('identity_hash,identity_hint,config_json,status,update_time')->find();
        return is_array($row) ? $this->state($provider, $row) : null;
    }

    public function apply(TenantContext $context, string $provider, array $value, ?int $expectedRevision): void
    {
        $this->assertContext($context);
        $this->assertProvider($provider);
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['config', 'identity_hash', 'identity_hint', 'status']
            || !is_array($value['config']) || !is_bool($value['status'])
            || !is_string($value['identity_hint']) || strlen($value['identity_hint']) > 32
            || ($value['identity_hash'] !== null && (!is_string($value['identity_hash'])
                || preg_match('/^[a-f0-9]{64}$/D', $value['identity_hash']) !== 1))
            || ($value['status'] && $value['identity_hash'] === null)) {
            throw new RuntimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        $identityHash = $value['identity_hash'];
        $identityHint = trim($value['identity_hint']);
        $config = json_encode($value['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::transaction(function () use ($context, $provider, $value, $expectedRevision, $identityHash, $identityHint, $config): void {
            if ($this->directory->tenantStatus($context->tenantId, true) !== 'active') {
                throw new ExternalTenantResolutionException();
            }
            $row = Db::name('external_channel_binding')->where('tenant_id', $context->tenantId)->where('provider', $provider)
                ->field('id,identity_hash,identity_hint,config_json,status,update_time')->lock(true)->find();
            $row = is_array($row) ? $row : null;
            $revision = $row === null ? null : $this->revision($row);
            if (($expectedRevision === null && $row !== null)
                || ($expectedRevision !== null && $expectedRevision !== $revision)) {
                throw new RuntimeException('TRANSFER_CONFLICT');
            }
            $now = time();
            if ($row === null) {
                Db::name('external_channel_binding')->insert([
                    'tenant_id' => $context->tenantId,
                    'provider' => $provider,
                    'callback_key' => bin2hex(random_bytes(32)),
                    'identity_hash' => $identityHash ?? hash('sha256', 'unconfigured:' . $provider . ':' . $context->tenantId),
                    'identity_hint' => $identityHint,
                    'config_json' => $config,
                    'status' => $value['status'] ? 1 : 0,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                return;
            }
            $changes = ['config_json' => $config, 'status' => $value['status'] ? 1 : 0, 'update_time' => $now];
            if ($identityHash !== null) {
                $changes['identity_hash'] = $identityHash;
                $changes['identity_hint'] = $identityHint;
            }
            Db::name('external_channel_binding')->where('id', (int) $row['id'])
                ->where('tenant_id', $context->tenantId)->where('provider', $provider)->update($changes);
        });
    }

    /** @param array<string,mixed> $row @return array{provider:string,value:array{identity_hash:?string,identity_hint:string,config:array<string,mixed>,status:bool},revision:int} */
    private function state(string $provider, array $row): array
    {
        $this->assertProvider($provider);
        try {
            $config = json_decode((string) ($row['config_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        $enabled = (int) ($row['status'] ?? 0) === 1;
        $hash = trim((string) ($row['identity_hash'] ?? ''));
        if (!is_array($config) || ($hash === '' && $enabled)
            || ($hash !== '' && preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1)) {
            throw new RuntimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        return [
            'provider' => $provider,
            'value' => ['identity_hash' => $hash === '' ? null : $hash, 'identity_hint' => (string) ($row['identity_hint'] ?? ''), 'config' => $config, 'status' => $enabled],
            'revision' => $this->revision($row),
        ];
    }

    /** Same persisted-state fingerprint as the existing transfer protocol; same-second changes conflict. */
    private function revision(array $row): int
    {
        $state = [];
        foreach (['identity_hash', 'identity_hint', 'config_json', 'status', 'update_time'] as $key) {
            $state[$key] = $row[$key] ?? null;
        }
        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new RuntimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        $revision = hexdec(substr(hash('sha256', $encoded), 0, 15));
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        return $revision;
    }

    private function assertContext(TenantContext $context): void
    {
        if ($context->tenantId < 1 || $context->accountId < 1 || $context->memberId < 1
            || $context->authorizationRevision < 1 || $context->sessionKey === ''
            || $context->clientKey === '' || $context->requestId === '') {
            throw new RuntimeException('TRANSFER_TENANT_CONTEXT_INVALID');
        }
    }

    private function assertProvider(string $provider): void
    {
        if (preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $provider) !== 1) {
            throw new RuntimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
    }
}
