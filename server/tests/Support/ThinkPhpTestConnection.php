<?php

declare(strict_types=1);

use think\Container;
use think\DbManager;
use think\db\ConnectionInterface;
use think\db\PDOConnection;
use think\db\builder\Mysql as MysqlBuilder;
use think\db\builder\Sqlite as SqliteBuilder;
use think\db\connector\Mysql;
use think\db\connector\Sqlite;

/** Adapts an existing fixture PDO into the exact ThinkPHP connection used by production services. */
final class ThinkPhpTestConnection
{
    private function __construct() {}

    public static function fromPdo(PDO $pdo): PDOConnection
    {
        $connection = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'mysql' => new SharedPdoMysqlConnection($pdo),
            'sqlite' => new SharedPdoSqliteConnection($pdo),
            default => throw new RuntimeException('TEST_DATABASE_DRIVER_UNSUPPORTED'),
        };
        $manager = new SharedPdoDbManager($connection);
        $connection->setDb($manager);
        Container::getInstance()->instance(DbManager::class, $manager);

        return $connection;
    }

    public static function moduleCatalogs(PDO $pdo): \app\platform\infrastructure\module\ModuleCatalogApplier
    {
        $connection = self::fromPdo($pdo);
        return new \app\platform\infrastructure\module\ModuleCatalogApplier(
            new \PeanutAdmin\Modules\Settings\Service\SettingCatalogService(
                new \PeanutAdmin\Modules\Settings\Definition\SettingDefinitionSynchronizer(),
            ),
            new \PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer(
                new \PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository(),
            ),
            new \PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository(
                new \PeanutAdmin\Modules\Identity\Module\TenantEntitlementService(
                    new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([], [], [], [], 'fixture-catalog'),
                ),
            ),
            new \PeanutAdmin\Modules\ReferenceCodes\Service\ReferenceCodeCatalogService(),
            new \PeanutAdmin\Modules\Identity\Authorization\CatalogLifecycleService(),
        );
    }
}

/** Registers the exact fixture connection as ThinkPHP's default database manager. */
final class SharedPdoDbManager extends DbManager
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
        parent::__construct();
    }

    protected function instance(string|array|null $name = null, bool $force = false): ConnectionInterface
    {
        return $this->connection;
    }
}

final class SharedPdoMysqlConnection extends Mysql
{
    public function __construct(private readonly PDO $sharedPdo)
    {
        parent::__construct([
            'type' => 'mysql',
            'builder' => MysqlBuilder::class,
            'prefix' => 'pa_',
        ]);
    }

    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return $this->sharedPdo;
    }
}

final class SharedPdoSqliteConnection extends Sqlite
{
    public function __construct(private readonly PDO $sharedPdo)
    {
        parent::__construct([
            'type' => 'sqlite',
            'builder' => SqliteBuilder::class,
            'prefix' => 'pa_',
        ]);
    }

    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return $this->sharedPdo;
    }
}
