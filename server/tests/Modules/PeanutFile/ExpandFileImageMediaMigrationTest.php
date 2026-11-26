<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Migration\IrreversibleMigrationException;
use PHPUnit\Framework\TestCase;

final class ExpandFileImageMediaMigrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $file = dirname(__DIR__, 3) . '/app/modules/peanut/file/database/migrations/20261126010001_expand_file_source_image_media_types.php';
        require_once $file;
    }

    public function testQualifiedSourceTableAltersOnlyItsExistingMimeConstraint(): void
    {
        $adapter = $this->createMock(PdoAdapter::class);
        $adapter->expects(self::once())->method('hasTable')->with('file_image_asset')->willReturn(true);
        $adapter->method('getOption')->with('table_prefix')->willReturn('pa_');
        $adapter->expects(self::once())->method('fetchRow')
            ->with(self::callback(static fn(string $sql): bool => str_contains($sql, 'chk_file_image_asset_media_type') && str_contains($sql, 'DATABASE()')))
            ->willReturn(['CONSTRAINT_NAME' => 'chk_file_image_asset_media_type']);
        $adapter->expects(self::once())->method('execute')
            ->with(self::callback(static function (string $sql): bool {
                foreach (['ALTER TABLE `pa_file_image_asset`', 'DROP CHECK `chk_file_image_asset_media_type`', 'ADD CONSTRAINT `chk_file_image_asset_media_type`', 'image/webp', 'image/gif', 'image/bmp', 'image/vnd.microsoft.icon'] as $required) {
                    if (!str_contains($sql, $required)) return false;
                }
                return !str_contains($sql, 'pa_file_derivative') && !str_contains($sql, 'DELETE');
            }));
        $migration = new ExpandFileSourceImageMediaTypes('fixture', 20261126010001);
        $migration->setAdapter($adapter);
        $migration->up();
    }

    public function testAbsentOwnedTableRejectsBeforeDataDefinition(): void
    {
        $adapter = $this->createMock(PdoAdapter::class);
        $adapter->method('hasTable')->willReturn(false);
        $adapter->expects(self::never())->method('execute');
        $adapter->expects(self::never())->method('fetchRow');
        $migration = new ExpandFileSourceImageMediaTypes('fixture', 20261126010001);
        $migration->setAdapter($adapter);
        $this->expectExceptionMessage('FILE_IMAGE_ASSET_TABLE_REQUIRED');
        $migration->up();
    }

    public function testAbsentOriginalConstraintDoesNotCreateAmbiguousDrift(): void
    {
        $adapter = $this->createMock(PdoAdapter::class);
        $adapter->method('hasTable')->willReturn(true);
        $adapter->method('getOption')->willReturn('pa_');
        $adapter->method('fetchRow')->willReturn(false);
        $adapter->expects(self::never())->method('execute');
        $migration = new ExpandFileSourceImageMediaTypes('fixture', 20261126010001);
        $migration->setAdapter($adapter);
        $this->expectExceptionMessage('FILE_IMAGE_MEDIA_CONSTRAINT_REQUIRED');
        $migration->up();
    }

    public function testDowngradeRequiresMatchingBackup(): void
    {
        $migration = new ExpandFileSourceImageMediaTypes('fixture', 20261126010001);
        $this->expectException(IrreversibleMigrationException::class);
        $migration->down();
    }
}

