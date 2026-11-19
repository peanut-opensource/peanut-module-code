<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\access\SourceRead;

use DateTimeImmutable;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\Kernel\Audit\AuditWriter;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Identity\access\SourceRead\Model\SourceReadGrantRecord;
use think\db\Raw;
use think\facade\Db;

/** Source-tenant administration for revocable, read-only grants. */
final readonly class SourceReadGrantAdministrationService
{
    public function __construct(
        private SourceReadCapabilityRegistry $capabilities,
        private TenantAuthorizationEvaluator $permissions,
        private ModuleAvailability $modules,
        private AuditWriter $audit,
    ) {}

    /**
     * @param list<string> $fields
     * @return array{id:int,revision:int}
     */
    public function put(
        TenantContext $actor,
        int $recipientTenantId,
        string $capability,
        string $action,
        string $effect,
        ?int $objectId,
        array $fields,
        ?int $expectedRevision = null,
        ?DateTimeImmutable $validUntil = null,
    ): array {
        $definition = $this->capabilities->require($capability, $action);
        $this->permissions->assertAllowed($actor, $definition->managePermissionKey);
        $this->assertTenants($actor, $recipientTenantId, $definition);
        $fields = $this->fields($definition, $effect, $fields);
        if ($objectId !== null && $objectId < 1) {
            throw new \InvalidArgumentException('SOURCE_READ_GRANT_OBJECT_INVALID');
        }
        if ($validUntil !== null && $validUntil <= new DateTimeImmutable('now')) {
            throw new \InvalidArgumentException('SOURCE_READ_GRANT_EXPIRY_INVALID');
        }

        return Db::transaction(function () use (
            $actor,
            $recipientTenantId,
            $definition,
            $effect,
            $objectId,
            $fields,
            $expectedRevision,
            $validUntil,
        ): array {
            $query = SourceReadGrantRecord::where('source_tenant_id', $actor->tenantId)
                ->where('recipient_tenant_id', $recipientTenantId)
                ->where('capability', $definition->key)
                ->where('action', $definition->action)
                ->where('effect', $effect);
            $query = $objectId === null
                ? $query->whereNull('object_id')
                : $query->where('object_id', $objectId);
            $query->lock(true);
            $existing = $query->find();
            $now = gmdate('Y-m-d H:i:s.000');
            if ($existing instanceof SourceReadGrantRecord) {
                $revision = (int) $existing->getAttr('revision');
                if ($expectedRevision === null || $expectedRevision !== $revision) {
                    throw new DataAuthorizationException('AUTHZ_READ_GRANT_REVISION_MISMATCH', 'The source-read grant revision changed.');
                }
                $updated = $query->where('revision', $revision)->update([
                    'fields_json' => json_encode($fields, JSON_THROW_ON_ERROR),
                    'status' => 'active',
                    'valid_until' => $validUntil?->format('Y-m-d H:i:s.v'),
                    'revoked_by_member_id' => null,
                    'revoked_at' => null,
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]);
                if ($updated !== 1) {
                    throw new DataAuthorizationException('AUTHZ_READ_GRANT_REVISION_MISMATCH', 'The source-read grant revision changed.');
                }
                $id = (int) $existing->getAttr('id');
                ++$revision;
            } else {
                if ($expectedRevision !== null) {
                    throw new DataAuthorizationException('AUTHZ_READ_GRANT_REVISION_MISMATCH', 'The source-read grant does not exist.');
                }
                $record = SourceReadGrantRecord::create([
                    'source_tenant_id' => $actor->tenantId,
                    'recipient_tenant_id' => $recipientTenantId,
                    'capability' => $definition->key,
                    'action' => $definition->action,
                    'effect' => $effect,
                    'object_id' => $objectId,
                    'fields_json' => $fields,
                    'status' => 'active',
                    'valid_from' => $now,
                    'valid_until' => $validUntil?->format('Y-m-d H:i:s.v'),
                    'granted_by_member_id' => $actor->memberId,
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $id = (int) $record->getAttr('id');
                $revision = 1;
            }
            $this->audit->tenantMember(
                $actor,
                'tenant.source-read-grant.changed',
                $definition->managePermissionKey,
                'source-read-grant',
                (string) $id,
                [
                    'recipient_tenant_id' => $recipientTenantId,
                    'capability' => $definition->key,
                    'effect' => $effect,
                    'revision' => $revision,
                ],
            );

            return ['id' => $id, 'revision' => $revision];
        });
    }

    public function revoke(TenantContext $actor, int $grantId, int $expectedRevision): int
    {
        if ($grantId < 1 || $expectedRevision < 1) {
            throw new \InvalidArgumentException('SOURCE_READ_GRANT_INVALID');
        }

        return Db::transaction(function () use ($actor, $grantId, $expectedRevision): int {
            $record = SourceReadGrantRecord::where('source_tenant_id', $actor->tenantId)
                ->where('id', $grantId)->lock(true)->find();
            if (!$record instanceof SourceReadGrantRecord) {
                throw new DataAuthorizationException('AUTHZ_READ_GRANT_NOT_FOUND', 'The source-read grant was not found.');
            }
            $definition = $this->capabilities->require(
                (string) $record->getAttr('capability'),
                (string) $record->getAttr('action'),
            );
            $this->permissions->assertAllowed($actor, $definition->managePermissionKey);
            if ((int) $record->getAttr('revision') !== $expectedRevision
                || SourceReadGrantRecord::where('source_tenant_id', $actor->tenantId)
                    ->where('id', $grantId)->where('revision', $expectedRevision)->where('status', 'active')->update([
                        'status' => 'revoked',
                        'revoked_by_member_id' => $actor->memberId,
                        'revoked_at' => gmdate('Y-m-d H:i:s.000'),
                        'revision' => new Raw('revision + 1'),
                        'updated_at' => gmdate('Y-m-d H:i:s.000'),
                    ]) !== 1) {
                throw new DataAuthorizationException('AUTHZ_READ_GRANT_REVISION_MISMATCH', 'The source-read grant revision changed.');
            }
            $revision = $expectedRevision + 1;
            $this->audit->tenantMember(
                $actor,
                'tenant.source-read-grant.revoked',
                $definition->managePermissionKey,
                'source-read-grant',
                (string) $grantId,
                ['revision' => $revision],
            );

            return $revision;
        });
    }

    private function assertTenants(TenantContext $actor, int $recipientTenantId, SourceReadCapability $definition): void
    {
        if ($recipientTenantId < 1 || $recipientTenantId === $actor->tenantId) {
            throw new \InvalidArgumentException('SOURCE_READ_GRANT_TENANT_INVALID');
        }
        // 来源具备数据提供模块；接收方具备消费该能力的模块，两者不必相同。
        foreach ([[$actor->tenantId, $definition->moduleKey], [$recipientTenantId, $definition->recipientModuleKey]] as [$tenantId, $moduleKey]) {
            if (Tenant::where('id', $tenantId)->where('status', 'active')->value('id') === null) {
                throw new DataAuthorizationException('AUTHZ_READ_TENANT_UNAVAILABLE', 'A source-read tenant is unavailable.');
            }
            $this->modules->assertAvailable(
                TenantScope::fromTrustedContext($tenantId, 'source-read-grant-administration'),
                $moduleKey,
                new DateTimeImmutable('now'),
            );
        }
    }

    /** @param list<string> $fields @return list<string> */
    private function fields(SourceReadCapability $definition, string $effect, array $fields): array
    {
        if (!in_array($effect, ['allow', 'deny'], true) || !array_is_list($fields)) {
            throw new \InvalidArgumentException('SOURCE_READ_GRANT_EFFECT_INVALID');
        }
        if ($effect === 'deny') {
            if ($fields !== []) {
                throw new \InvalidArgumentException('SOURCE_READ_GRANT_DENY_FIELDS_INVALID');
            }
            return [];
        }
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_string($field) || !in_array($field, $definition->fields, true)) {
                throw new DataAuthorizationException('AUTHZ_READ_FIELDS_DENIED', 'The granted field is not registered.');
            }
            $normalized[$field] = true;
        }
        $fields = array_keys($normalized);
        sort($fields, SORT_STRING);
        if ($fields === []) {
            throw new \InvalidArgumentException('SOURCE_READ_GRANT_FIELDS_EMPTY');
        }

        return $fields;
    }
}
