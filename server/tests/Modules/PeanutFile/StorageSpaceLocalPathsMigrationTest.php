<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Migration\IrreversibleMigrationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;

/** Files are real; the adapter captures SQL without connecting to a database. */
final class StorageSpaceLocalPathsMigrationTest extends TestCase
{
    private string $root;
    private Container $previousContainer;

    public static function setUpBeforeClass(): void
    {
        $server = dirname(__DIR__, 3);
        require_once $server . '/vendor/autoload.php';
        require_once $server . '/vendor/topthink/framework/src/helper.php';
        require_once $server . '/app/modules/peanut/file/database/migrations/20261123010001_migrate_storage_space_local_paths.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/peanut-storage-path-migration-' . bin2hex(random_bytes(8));
        foreach (['public', 'private', 'storage/public', 'storage/private'] as $relative) {
            mkdir($this->root . '/' . $relative, 0700, true);
        }
        symlink('../storage/public', $this->root . '/public/storage');
        $this->previousContainer = Container::getInstance();
        new App($this->root . '/');
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        $paths = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($paths as $path) {
            if ($path->isDir() && !$path->isLink()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($this->root);
    }

    public static function unpreparedLayouts(): array
    {
        return [
            'missing private directory' => ['missing-private'],
            'legacy public directory' => ['legacy-public'],
            'legacy private data' => ['legacy-private'],
            'wrong public link' => ['wrong-link'],
            'canonical private symlink' => ['private-link'],
        ];
    }

    #[DataProvider('unpreparedLayouts')]
    public function testUnpreparedHostStopsBeforeAnyDatabaseMutation(string $layout): void
    {
        if ($layout === 'missing-private') {
            rmdir($this->root . '/storage/private');
        } elseif ($layout === 'legacy-public') {
            unlink($this->root . '/public/storage');
            mkdir($this->root . '/public/storage');
            file_put_contents($this->root . '/public/storage/kept.txt', 'legacy public bytes');
        } elseif ($layout === 'legacy-private') {
            mkdir($this->root . '/private/storage');
            file_put_contents($this->root . '/private/storage/kept.txt', 'legacy private bytes');
        } elseif ($layout === 'wrong-link') {
            unlink($this->root . '/public/storage');
            symlink('../storage/private', $this->root . '/public/storage');
        } else {
            rmdir($this->root . '/storage/private');
            symlink('public', $this->root . '/storage/private');
        }

        $adapter = $this->createMock(PdoAdapter::class);
        $adapter->method('hasTable')->with('storage_space')->willReturn(true);
        $adapter->expects(self::never())->method('execute');
        $adapter->expects(self::never())->method('fetchRow');
        $migration = new MigrateStorageSpaceLocalPaths('test', 20261123010001);
        $migration->setAdapter($adapter);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('migrate-storage-layout.py');
        $migration->up();
    }

    public function testPreparedHostUpdatesPointersWithoutMovingFiles(): void
    {
        file_put_contents($this->root . '/storage/public/kept.txt', 'public bytes');
        file_put_contents($this->root . '/storage/private/kept.txt', 'private bytes');
        $adapter = $this->createMock(PdoAdapter::class);
        $adapter->method('hasTable')->with('storage_space')->willReturn(true);
        $adapter->method('getOption')->with('table_prefix')->willReturn('test_');
        $adapter->method('fetchRow')->willReturn(['CONSTRAINT_NAME' => 'chk_storage_space_local_path']);
        $sql = [];
        $adapter->expects(self::exactly(4))->method('execute')->willReturnCallback(
            static function (string $statement, array $params = []) use (&$sql): int {
                $sql[] = $statement;
                return 0;
            },
        );
        $migration = new MigrateStorageSpaceLocalPaths('test', 20261123010001);
        $migration->setAdapter($adapter);
        $migration->up();

        self::assertStringStartsWith('ALTER TABLE `test_storage_space` DROP CHECK', $sql[0]);
        self::assertStringContainsString("SET `local_path` = 'storage/public' WHERE `local_path` = 'public/storage'", $sql[1]);
        self::assertStringContainsString("SET `local_path` = 'storage/private' WHERE `local_path` = 'private/storage'", $sql[2]);
        self::assertStringContainsString('ADD CONSTRAINT `chk_storage_space_local_path`', $sql[3]);
        self::assertSame('public bytes', file_get_contents($this->root . '/public/storage/kept.txt'));
        self::assertSame('private bytes', file_get_contents($this->root . '/storage/private/kept.txt'));
    }

    public function testMissingTableDoesNotMutateDatabase(): void
    {
        $adapter = $this->createMock(PdoAdapter::class);
        $adapter->method('hasTable')->willReturn(false);
        $adapter->expects(self::never())->method('execute');
        $migration = new MigrateStorageSpaceLocalPaths('test', 20261123010001);
        $migration->setAdapter($adapter);
        $migration->up();
    }

    public function testDowngradeCannotRedirectPointersAwayFromCurrentFiles(): void
    {
        $migration = new MigrateStorageSpaceLocalPaths('test', 20261123010001);
        $this->expectException(IrreversibleMigrationException::class);
        $this->expectExceptionMessage('paired backup');
        $migration->down();
    }
}
