<?php

declare(strict_types=1);

// Isolated repository/real-synchronizer regression: no MySQL and no write to a shared APP.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class FakeCatalogResult
{
    public function __construct(private array $rows) {}
    public function toArray(): array { return $this->rows; }
}

final class FakeCatalogRow
{
    public function __construct(private array $row) {}
    public function toArray(): array { return $this->row; }
}

final class FakeCatalogQuery
{
    public function __construct(private string $field, private mixed $filter) {}
    public function field(string $fields): self { return $this; }
    private function matches(array $row): bool
    {
        return $this->field === 'id'
            ? $row['id'] === $this->filter
            : (is_array($this->filter)
                ? in_array($row['key'], $this->filter, true)
                : $row['key'] === $this->filter);
    }
    public function select(): FakeCatalogResult
    {
        ++FakeCatalogModel::$prefetchCount;
        return new FakeCatalogResult(array_values(array_filter(FakeCatalogModel::$rows, fn(array $x): bool => $this->matches($x))));
    }
    public function find(): ?FakeCatalogRow
    {
        ++FakeCatalogModel::$keyReadCount;
        foreach (FakeCatalogModel::$rows as $row) {
            if ($this->matches($row)) { return new FakeCatalogRow($row); }
        }
        return null;
    }
    public function value(string $field): mixed
    {
        ++FakeCatalogModel::$keyReadCount;
        foreach (FakeCatalogModel::$rows as $row) {
            if ($this->matches($row)) { return $row[$field] ?? null; }
        }
        return null;
    }
    public function update(array $data): int
    {
        ++FakeCatalogModel::$writeCount;
        foreach (FakeCatalogModel::$rows as &$row) {
            if ($this->matches($row)) { $row = array_replace($row, $data); return 1; }
        }
        return 0;
    }
}

final class FakeCatalogModel
{
    public static array $rows = [];
    public static int $prefetchCount = 0;
    public static int $keyReadCount = 0;
    public static int $writeCount = 0;

    public static function whereIn(string $field, array $values): FakeCatalogQuery
    {
        return new FakeCatalogQuery($field, $values);
    }
    public static function where(string $field, mixed $value): FakeCatalogQuery
    {
        return new FakeCatalogQuery($field, $value);
    }
    public static function insertGetId(array $row): int
    {
        ++self::$writeCount;
        $row['id'] = count(self::$rows) + 1;
        self::$rows[$row['key']] = $row;
        return $row['id'];
    }
}

foreach ([
    \PeanutAdmin\Modules\Identity\Persistence\Model\Permission::class,
    \PeanutAdmin\Modules\Identity\Authorization\Model\DataConditionDefinitionRecord::class,
    \PeanutAdmin\Modules\Identity\Authorization\Model\TargetTypeRecord::class,
    \PeanutAdmin\Modules\Identity\Authorization\Model\ProtectedResourceRecord::class,
] as $model) {
    if (class_exists($model, false) || !class_alias(FakeCatalogModel::class, $model)) {
        throw new RuntimeException('Isolated catalog fixture was not installed');
    }
}

$repository = new \PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository();
$sync = new \PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer($repository);
$sync->synchronize();
$firstCount = count(FakeCatalogModel::$rows);
if ($firstCount < 50 || FakeCatalogModel::$prefetchCount !== 2 || FakeCatalogModel::$keyReadCount !== 0) {
    throw new RuntimeException('Core sync used per-key reads instead of two read batches');
}
$sync->synchronize();
if (count(FakeCatalogModel::$rows) !== $firstCount || FakeCatalogModel::$prefetchCount !== 4
    || FakeCatalogModel::$keyReadCount !== 0) {
    throw new RuntimeException('Idempotent core catalog refresh performed an extra lookup');
}
FakeCatalogModel::$rows['core.menu.read']['module_key'] = 'other.module';
try {
    $sync->synchronize();
    throw new RuntimeException('Conflicting module owner was accepted');
} catch (DomainException $exception) {
    if ($exception->getMessage() !== 'Catalog key is already owned by another module.') {
        throw $exception;
    }
}
FakeCatalogModel::$rows['core.menu.read']['module_key'] = 'core';
$manifest = \PeanutAdmin\Kernel\Module\ManifestDocument::fromObject('/virtual/identity', json_decode(json_encode([
    'key' => 'peanut.identity',
    'version' => '4.0.0-rc.63',
    'catalog' => [
        'permissions' => [],
        'target_types' => [[
            'key' => 'peanut.identity.target',
            'name' => 'Synthetic target',
            'resolver' => 'test-resolver',
            'catalog_provider' => 'test-provider',
            'id_format' => 'string',
        ]],
        'data_conditions' => [],
        'protected_resources' => [],
    ],
], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
$registry = new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([$manifest], [], [], [], 'synthetic-registry');
$syncModules = new \PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer($repository);
$priorReads = FakeCatalogModel::$keyReadCount;
$syncModules->synchronize($registry);
if (($row = FakeCatalogModel::$rows['peanut.identity.target'] ?? null) === null
    || $row['module_key'] !== 'peanut.identity' || FakeCatalogModel::$keyReadCount !== $priorReads) {
    throw new RuntimeException('TargetType real module synchronization did not use prefetched ownership');
}
$syncModules->synchronize($registry);
if (FakeCatalogModel::$keyReadCount !== $priorReads) {
    throw new RuntimeException('TargetType repeat synchronization made an unexpected point read');
}
FakeCatalogModel::$rows['peanut.identity.target']['module_key'] = 'other.module';
try {
    $syncModules->synchronize($registry);
    throw new RuntimeException('TargetType ownership conflict was accepted');
} catch (DomainException $exception) {
    if ($exception->getMessage() !== 'Catalog key is already owned by another module.') {
        throw $exception;
    }
}
echo 'AUTHORIZATION-CATALOG-BATCH-001 passed: rows=' . $firstCount
    . ' prefetches=' . FakeCatalogModel::$prefetchCount
    . ' per-key-reads=0 owner-conflict=blocked target-type=verified' . PHP_EOL;
