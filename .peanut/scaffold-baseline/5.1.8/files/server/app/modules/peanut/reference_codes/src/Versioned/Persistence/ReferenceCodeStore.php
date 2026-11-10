<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Versioned\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeException;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Persistence\Model\ReferenceCodeEntryRecord;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Persistence\Model\ReferenceCodeEntryVersionRecord;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Persistence\Model\ReferenceCodeSetRecord;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use think\db\Raw;
use think\db\exception\PDOException;
use think\facade\Db;

final class ReferenceCodeStore
{
    public function __construct(
        private readonly ?TenantMemberDirectory $members = null,
        private readonly ?AdminDirectoryQuery $memberReferences = null,
    ) {}

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    public function atomically(callable $operation): mixed
    {
        return Db::transaction($operation);
    }

    /** @return array{inserted: int, updated: int, retired: int, reactivated: int} */
    public function synchronize(ReferenceCodeSetRegistry $registry, DateTimeImmutable $now): array
    {
        $this->assertExactMillisecond($now);

        return Db::transaction(function () use ($registry, $now): array {
            $moduleKeys = $registry->moduleKeys();
            if ($moduleKeys === []) {
                return ['inserted' => 0, 'updated' => 0, 'retired' => 0, 'reactivated' => 0];
            }
            $rows = ReferenceCodeSetRecord::whereIn('module_key', $moduleKeys)->lock(true)->select()->toArray();
            $existing = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $existing[(string) $row['module_key'] . ':' . (string) $row['set_key']] = $row;
                }
            }
            $declared = [];
            $counts = ['inserted' => 0, 'updated' => 0, 'retired' => 0, 'reactivated' => 0];
            foreach ($registry->all() as $definition) {
                $qualifiedKey = $definition->qualifiedKey();
                $declared[$qualifiedKey] = true;
                $row = $existing[$qualifiedKey] ?? null;
                if ($row === null) {
                    $this->insertDefinition($definition, $now);
                    ++$counts['inserted'];
                    continue;
                }
                $reactivating = (string) $row['lifecycle'] === 'retired';
                if (!$reactivating && hash_equals((string) $row['definition_digest'], $definition->digest)) {
                    continue;
                }
                ReferenceCodeSetRecord::where('id', (int) $row['id'])->update([
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'definition_digest' => $definition->digest,
                    'lifecycle' => 'active',
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $this->date($now),
                ]);
                ++$counts[$reactivating ? 'reactivated' : 'updated'];
            }
            foreach ($existing as $qualifiedKey => $row) {
                if (isset($declared[$qualifiedKey]) || (string) $row['lifecycle'] === 'retired') {
                    continue;
                }
                $affected = ReferenceCodeSetRecord::where('id', (int) $row['id'])
                    ->where('lifecycle', 'active')->update([
                        'lifecycle' => 'retired',
                        'revision' => new Raw('revision + 1'),
                        'updated_at' => $this->date($now),
                    ]);
                $counts['retired'] += $affected;
            }

            return $counts;
        });
    }

    public function assertCurrentDefinition(ReferenceCodeSetDefinition $definition, bool $forShare = false): void
    {
        $this->definitionRow($definition, $forShare);
    }

    public function create(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        string $label,
        string $metadataJson,
        string $status,
        int $sortOrder,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
    ): DateTimeImmutable {
        return Db::transaction(function () use (
            $definition,
            $context,
            $code,
            $label,
            $metadataJson,
            $status,
            $sortOrder,
            $effectiveAt,
            $expiresAt,
        ): DateTimeImmutable {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition, true);
            $existing = $this->entry((int) $set['id'], $context->tenantId, $code, true);
            if ($existing !== null) {
                throw (string) $existing['lifecycle'] === 'retired'
                    ? ReferenceCodeException::retired()
                    : ReferenceCodeException::alreadyExists();
            }
            $now = $this->databaseNow();
            try {
                $entryId = (int) ReferenceCodeEntryRecord::insertGetId([
                    'tenant_id' => $context->tenantId,
                    'set_id' => (int) $set['id'],
                    'code' => $code,
                    'lifecycle' => 'active',
                    'revision' => 1,
                    'created_by_member_id' => $context->memberId,
                    'updated_by_member_id' => $context->memberId,
                    'retired_at' => null,
                    'created_at' => $this->date($now),
                    'updated_at' => $this->date($now),
                ]);
                $this->insertVersion(
                    $entryId,
                    1,
                    $label,
                    $metadataJson,
                    $status,
                    $sortOrder,
                    $effectiveAt,
                    $expiresAt,
                    $context->memberId,
                    $now,
                );
            } catch (PDOException $exception) {
                if ($this->isCreateCompetition($exception)) {
                    throw ReferenceCodeException::alreadyExists();
                }
                throw $exception;
            }

            return $now;
        });
    }

    public function replace(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        string $label,
        string $metadataJson,
        string $status,
        int $sortOrder,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        int $expectedRevision,
    ): DateTimeImmutable {
        return Db::transaction(function () use (
            $definition,
            $context,
            $code,
            $label,
            $metadataJson,
            $status,
            $sortOrder,
            $effectiveAt,
            $expiresAt,
            $expectedRevision,
        ): DateTimeImmutable {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition, true);
            $entry = $this->entry((int) $set['id'], $context->tenantId, $code, true);
            if ($entry === null) {
                throw ReferenceCodeException::codeNotFound();
            }
            if ((string) $entry['lifecycle'] === 'retired') {
                throw ReferenceCodeException::retired();
            }
            if ((int) $entry['revision'] !== $expectedRevision) {
                throw ReferenceCodeException::revisionMismatch();
            }
            $revision = $expectedRevision + 1;
            $now = $this->databaseNow();
            $affected = ReferenceCodeEntryRecord::where('id', (int) $entry['id'])
                ->where('lifecycle', 'active')->where('revision', $expectedRevision)->update([
                    'revision' => $revision,
                    'updated_by_member_id' => $context->memberId,
                    'updated_at' => $this->date($now),
                ]);
            if ($affected !== 1) {
                throw ReferenceCodeException::revisionMismatch();
            }
            $this->insertVersion(
                (int) $entry['id'],
                $revision,
                $label,
                $metadataJson,
                $status,
                $sortOrder,
                $effectiveAt,
                $expiresAt,
                $context->memberId,
                $now,
            );

            return $now;
        });
    }

    public function retire(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code,
        int $expectedRevision,
    ): DateTimeImmutable {
        return Db::transaction(function () use ($definition, $context, $code, $expectedRevision): DateTimeImmutable {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition, true);
            $entry = $this->entry((int) $set['id'], $context->tenantId, $code, true);
            if ($entry === null) {
                throw ReferenceCodeException::codeNotFound();
            }
            if ((string) $entry['lifecycle'] === 'retired') {
                throw ReferenceCodeException::retired();
            }
            if ((int) $entry['revision'] !== $expectedRevision) {
                throw ReferenceCodeException::revisionMismatch();
            }
            $last = ReferenceCodeEntryVersionRecord::where('entry_id', (int) $entry['id'])
                ->where('revision', $expectedRevision)->lock(true)->find()?->toArray();
            if ($last === null) {
                throw ReferenceCodeException::internal();
            }
            $revision = $expectedRevision + 1;
            $now = $this->databaseNow();
            $affected = ReferenceCodeEntryRecord::where('id', (int) $entry['id'])
                ->where('lifecycle', 'active')->where('revision', $expectedRevision)->update([
                    'lifecycle' => 'retired',
                    'revision' => $revision,
                    'updated_by_member_id' => $context->memberId,
                    'retired_at' => $this->date($now),
                    'updated_at' => $this->date($now),
                ]);
            if ($affected !== 1) {
                throw ReferenceCodeException::revisionMismatch();
            }
            $this->insertVersion(
                (int) $entry['id'],
                $revision,
                (string) $last['label'],
                (string) $last['metadata_json'],
                'inactive',
                (int) $last['sort_order'],
                $now,
                null,
                $context->memberId,
                $now,
            );

            return $now;
        });
    }

    /**
     * @return array{
     *   as_of: DateTimeImmutable,
     *   entries: list<array{entry: array<string, mixed>, versions: list<array<string, mixed>>}>
     * }
     */
    public function snapshot(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        ?string $code,
        ?DateTimeImmutable $asOf,
    ): array {
        return Db::transaction(function () use ($definition, $context, $code, $asOf): array {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition);
            $comparisonTime = $asOf ?? $this->databaseNow();
            $this->assertExactMillisecond($comparisonTime);
            $query = ReferenceCodeEntryRecord::where('tenant_id', $context->tenantId)
                ->where('set_id', (int) $set['id']);
            if ($code !== null) {
                $query->where('code', $code);
            }
            $rows = $query->orderRaw('BINARY `code` ASC')->select()->toArray();
            $versionQuery = $this->snapshotVersionQuery($context, (int) $set['id']);
            if ($code !== null) {
                $versionQuery->where('entry.code', $code);
            }
            $verifiedMembers = [];
            return ['as_of' => $comparisonTime, 'entries' => $this->snapshotEntries($context, $rows, $versionQuery, $verifiedMembers)];
        });
    }

    /**
     * 模块内部列表读取：同一事务/时刻先有界扫描并沿既有hydrator核全部历史，再由数据库计数和分页。
     * 页外损坏历史仍失败；扫描不收集全量条目ID或结果对象，作者键集仅在这次事务内复用。
     * @param \Closure(array{entry:array<string,mixed>,versions:list<array<string,mixed>>}, DateTimeImmutable):void $assertHistory 现有纯历史校验，不执行写入。
     * @return array{as_of:DateTimeImmutable,total:int,entries:list<array{entry:array<string,mixed>,versions:list<array<string,mixed>>}>}
     */
    public function pageSnapshot(
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        ?DateTimeImmutable $asOf,
        string $effectiveStatus,
        bool $includeRetired,
        int $page,
        int $pageSize,
        \Closure $assertHistory,
    ): array {
        if (!in_array($effectiveStatus, ['active', 'inactive', 'all'], true)
            || $page < 1 || $page > 10000 || $pageSize < 1 || $pageSize > 100) {
            throw ReferenceCodeException::invalid('REFERENCE_CODE_REQUEST_INVALID', 'The reference-code query is invalid.');
        }
        return Db::transaction(function () use ($definition, $context, $asOf, $effectiveStatus, $includeRetired, $page, $pageSize, $assertHistory): array {
            $this->assertTenantActor($context);
            $set = $this->definitionRow($definition);
            $setId = (int) $set['id'];
            $comparisonTime = $asOf ?? $this->databaseNow();
            $this->assertExactMillisecond($comparisonTime);
            $scope = ReferenceCodeEntryRecord::where('tenant_id', $context->tenantId)->where('set_id', $setId);
            $verifiedMembers = [];
            $cursor = 0;
            do {
                $rows = (clone $scope)->where('id', '>', $cursor)->order('id')->limit(200)->select()->toArray();
                if ($rows === []) {
                    break;
                }
                $last = (int) $rows[count($rows) - 1]['id'];
                if ($last <= $cursor) {
                    throw ReferenceCodeException::internal();
                }
                $versions = $this->snapshotVersionQuery($context, $setId)
                    ->where('entry.id', '>', $cursor)->where('entry.id', '<=', $last);
                foreach ($this->snapshotEntries($context, $rows, $versions, $verifiedMembers) as $raw) {
                    $assertHistory($raw, $comparisonTime);
                }
                $cursor = $last;
            } while (count($rows) === 200);

            $query = $this->effectivePageQuery($context, $setId, $comparisonTime, $effectiveStatus, $includeRetired);
            $total = (int) (clone $query)->count();
            $rows = $query->field('entry.*')
                ->orderRaw('CASE WHEN effective.id IS NULL THEN 1 ELSE 0 END ASC')
                ->order('effective.sort_order')->orderRaw('BINARY entry.code ASC')
                ->page($page, $pageSize)->select()->toArray();
            // 这里只保留至多100条当前页ID；版本仍经同租户/同集合的所属条目关联。
            $versions = $this->snapshotVersionQuery($context, $setId)->whereIn('entry.id', array_column($rows, 'id'));
            return [
                'as_of' => $comparisonTime,
                'total' => $total,
                'entries' => $this->snapshotEntries($context, $rows, $versions, $verifiedMembers),
            ];
        });
    }

    private function effectivePageQuery(TenantContext $context, int $setId, DateTimeImmutable $asOf, string $effectiveStatus, bool $includeRetired): \think\db\Query
    {
        $instant = $this->date($asOf);
        // 生效版本为给定时刻仍在区间内的最大revision，不是无条件取最新一版。
        $winner = $this->snapshotVersionQuery($context, $setId)
            ->where('version.effective_at', '<=', $instant)
            ->where(static function (\think\db\Query $query) use ($instant): void {
                $query->whereNull('version.expires_at')->whereOr('version.expires_at', '>', $instant);
            })
            ->field('version.entry_id')->fieldRaw('MAX(version.revision) AS effective_revision')
            ->group('version.entry_id')->buildSql();
        $query = ReferenceCodeEntryRecord::alias('entry')
            ->leftJoin([$winner => 'winner'], 'winner.entry_id = entry.id')
            ->leftJoin('reference_code_entry_version effective', 'effective.entry_id = entry.id AND effective.revision = winner.effective_revision')
            ->where('entry.tenant_id', $context->tenantId)->where('entry.set_id', $setId)
            ->where('entry.created_at', '<=', $instant);
        if (!$includeRetired) {
            $query->where(static function (\think\db\Query $query) use ($instant): void {
                $query->whereNull('entry.retired_at')->whereOr('entry.retired_at', '>', $instant);
            });
        }
        if ($effectiveStatus !== 'all') {
            $query->where('effective.status', $effectiveStatus);
        }
        return $query;
    }

    private function snapshotVersionQuery(TenantContext $context, int $setId): \think\db\Query
    {
        return ReferenceCodeEntryVersionRecord::alias('version')
            ->join('reference_code_entry entry', 'entry.id = version.entry_id')
            ->where('entry.tenant_id', $context->tenantId)->where('entry.set_id', $setId);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<int,true> $verifiedMembers 当前事务已验证成员，不保存到实例或全局。
     * @return list<array{entry:array<string,mixed>,versions:list<array<string,mixed>>}>
     */
    private function snapshotEntries(TenantContext $context, array $rows, \think\db\Query $versionQuery, array &$verifiedMembers): array
    {
        if ($rows === []) {
            return [];
        }
        $versionsByEntry = [];
        $memberIds = [];
        foreach ($rows as $entry) {
            if (!is_array($entry)) {
                throw ReferenceCodeException::internal();
            }
            $memberIds[$this->referenceMemberId($entry['created_by_member_id'] ?? null)] = true;
            $memberIds[$this->referenceMemberId($entry['updated_by_member_id'] ?? null)] = true;
        }
        foreach ($versionQuery->field('version.*')->order('version.entry_id')->order('version.revision')->select()->toArray() as $version) {
            if (!is_array($version)) {
                throw ReferenceCodeException::internal();
            }
            $memberIds[$this->referenceMemberId($version['changed_by_member_id'] ?? null)] = true;
            $versionsByEntry[(int) $version['entry_id']][] = $version;
        }
        $unverified = array_diff_key($memberIds, $verifiedMembers);
        if ($unverified !== []) {
            $this->assertMemberReferences($context, $unverified);
            $verifiedMembers += $unverified;
        }
        $entries = [];
        foreach ($rows as $entry) {
            $entries[] = ['entry' => $entry, 'versions' => $versionsByEntry[(int) $entry['id']] ?? []];
        }
        return $entries;
    }

    /** @return list<array{module_key: string, set_key: string, name: string, description: string, definition_revision: int}> */
    public function definitionSummaries(ReferenceCodeSetRegistry $registry): array
    {
        return Db::transaction(function () use ($registry): array {
            $summaries = [];
            foreach ($registry->all() as $definition) {
                $row = $this->definitionRow($definition);
                $summaries[] = [
                    'module_key' => $definition->moduleKey,
                    'set_key' => $definition->key,
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'definition_revision' => (int) $row['revision'],
                ];
            }

            return $summaries;
        });
    }

    private function insertDefinition(ReferenceCodeSetDefinition $definition, DateTimeImmutable $now): void
    {
        ReferenceCodeSetRecord::insert([
            'module_key' => $definition->moduleKey,
            'set_key' => $definition->key,
            'name' => $definition->name,
            'description' => $definition->description,
            'definition_digest' => $definition->digest,
            'lifecycle' => 'active',
            'revision' => 1,
            'created_at' => $this->date($now),
            'updated_at' => $this->date($now),
        ]);
    }

    /** @return array<string, mixed> */
    private function definitionRow(ReferenceCodeSetDefinition $definition, bool $forShare = false): array
    {
        $query = ReferenceCodeSetRecord::where('module_key', $definition->moduleKey)
            ->where('set_key', $definition->key)->where('lifecycle', 'active');
        if ($forShare) {
            $query->lock('FOR SHARE');
        }
        $row = $query->find()?->toArray();
        if ($row === null || !hash_equals((string) $row['definition_digest'], $definition->digest)) {
            throw ReferenceCodeException::setNotFound();
        }
        if ((int) $row['id'] < 1
            || (int) $row['revision'] < 1
            || (string) $row['module_key'] !== $definition->moduleKey
            || (string) $row['set_key'] !== $definition->key
            || (string) $row['name'] !== $definition->name
            || (string) $row['description'] !== $definition->description) {
            throw ReferenceCodeException::internal();
        }

        return $row;
    }

    /** 数据库整数字符串须精确可表示，不把损坏编号强转为其他成员。 */
    private function referenceMemberId(mixed $memberId): int
    {
        if ((!is_int($memberId)
                && !(is_string($memberId) && ctype_digit($memberId) && (string) (int) $memberId === $memberId))
            || (int) $memberId < 1) {
            throw ReferenceCodeException::internal();
        }
        return (int) $memberId;
    }

    /**
     * 历史作者只核同租户存在，停用/空显示名仍合法；读取者由assertTenantActor另验。
     * Identity负责既有500成员分批；键集只活在本次快照事务内，不跨调用缓存。
     * @param array<int, true> $memberIds 同次快照已去重且校验类型的全部署名成员。
     */
    private function assertMemberReferences(TenantContext $context, array $memberIds): void
    {
        if ($this->memberReferences === null) {
            throw ReferenceCodeException::internal();
        }
        $members = $this->memberReferences->memberDisplayNames($context, array_keys($memberIds));
        foreach ($memberIds as $memberId => $_) {
            if (!array_key_exists($memberId, $members)) {
                throw ReferenceCodeException::internal();
            }
        }
    }

    private function assertTenantActor(TenantContext $context): void
    {
        $member = $this->members?->activeMembership($context->tenantId, $context->memberId);
        if ($member === null
            || $member->accountId !== $context->accountId
            || $member->authorizationRevision !== $context->authorizationRevision) {
            throw ReferenceCodeException::codeNotFound();
        }
    }

    /** @return array<string, mixed>|null */
    private function entry(int $setId, int $tenantId, string $code, bool $forUpdate): ?array
    {
        $query = ReferenceCodeEntryRecord::where('tenant_id', $tenantId)
            ->where('set_id', $setId)->where('code', $code);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->find()?->toArray();

        return $row;
    }

    private function insertVersion(
        int $entryId,
        int $revision,
        string $label,
        string $metadataJson,
        string $status,
        int $sortOrder,
        DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        int $memberId,
        DateTimeImmutable $createdAt,
    ): void {
        ReferenceCodeEntryVersionRecord::insert([
            'entry_id' => $entryId,
            'revision' => $revision,
            'label' => $label,
            'metadata_json' => $metadataJson,
            'status' => $status,
            'sort_order' => $sortOrder,
            'effective_at' => $this->date($effectiveAt),
            'expires_at' => $expiresAt === null ? null : $this->date($expiresAt),
            'changed_by_member_id' => $memberId,
            'created_at' => $this->date($createdAt),
        ]);
    }

    private function databaseNow(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.v',
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
            new DateTimeZone('UTC'),
        ) ?: throw ReferenceCodeException::internal();
    }

    private function assertExactMillisecond(DateTimeImmutable $date): void
    {
        if (((int) $date->format('u')) % 1000 !== 0) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_INTERVAL_INVALID',
                'Reference-code timestamps require exact millisecond precision.',
            );
        }
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function isCreateCompetition(PDOException $exception): bool
    {
        $error = $exception->getData()['PDO Error Info'] ?? [];
        $sqlState = (string) ($error['SQLSTATE'] ?? $exception->getCode());
        $driverCode = (int) ($error['Driver Error Code'] ?? 0);

        return ($sqlState === '23000' && $driverCode === 1062)
            || ($sqlState === '40001' && $driverCode === 1213)
            || ($sqlState === 'HY000' && $driverCode === 1205);
    }
}
