<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Expand File's source-image MIME check to its existing upload whitelist; leave derivatives unchanged. */
final class ExpandFileSourceImageMediaTypes extends Migrator
{
    public function up(): void
    {
        if (!$this->hasTable('file_image_asset')) {
            throw new RuntimeException('FILE_IMAGE_ASSET_TABLE_REQUIRED');
        }
        $prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? 'pa_');
        $table = $prefix . 'file_image_asset';
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new RuntimeException('FILE_IMAGE_ASSET_TABLE_INVALID');
        }
        $present = $this->fetchRow(sprintf(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND CONSTRAINT_NAME = 'chk_file_image_asset_media_type'",
            $table,
        ));
        if (!$present) {
            throw new RuntimeException('FILE_IMAGE_MEDIA_CONSTRAINT_REQUIRED');
        }

        // One atomic ALTER on the source-image table. The JPEG/PNG derivative
        // constraint, file objects, and all existing references are untouched.
        $this->execute(sprintf(
            "ALTER TABLE `%s` DROP CHECK `chk_file_image_asset_media_type`, ADD CONSTRAINT `chk_file_image_asset_media_type` CHECK ((`media_type` IN (_utf8mb4'image/jpeg',_utf8mb4'image/png',_utf8mb4'image/gif',_utf8mb4'image/webp',_utf8mb4'image/bmp',_utf8mb4'image/x-ms-bmp',_utf8mb4'image/vnd.microsoft.icon',_utf8mb4'image/x-icon')))",
            $table,
        ));
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'File source images may contain newly allowed MIME types. Restore a matching backup instead of narrowing their metadata constraint.',
        );
    }
}

