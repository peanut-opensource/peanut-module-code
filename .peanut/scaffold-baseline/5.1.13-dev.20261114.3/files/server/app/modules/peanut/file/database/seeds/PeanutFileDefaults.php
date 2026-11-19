<?php

declare(strict_types=1);

use think\migration\Seeder;

/** Neutral storage defaults; retries preserve every existing account, space and route. */
final class PeanutFileDefaults extends Seeder
{
    public function run(): void
    {
        $this->execute(<<<'SQL'
INSERT INTO pa_storage_account (account_key,driver,name,status,created_at,updated_at)
SELECT 'local','local','本地存储','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)
WHERE NOT EXISTS (SELECT 1 FROM pa_storage_account WHERE account_key='local');
SQL);
        foreach (['public', 'private'] as $access) {
            $this->execute(<<<'SQL'
INSERT INTO pa_storage_space (space_key,account_id,name,access_type,local_path,status,created_at,updated_at)
SELECT ?,id,?,?,?,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)
FROM pa_storage_account WHERE account_key='local'
AND NOT EXISTS (SELECT 1 FROM pa_storage_space WHERE space_key=?);
SQL, ['local-' . $access, $access === 'public' ? '本地公开文件' : '本地私有文件', $access, $access . '/storage', 'local-' . $access]);
            $this->execute(<<<'SQL'
INSERT INTO pa_storage_route (route_key,access_type,space_id,updated_at)
SELECT ?,?,id,UTC_TIMESTAMP(3) FROM pa_storage_space WHERE space_key=?
AND NOT EXISTS (SELECT 1 FROM pa_storage_route WHERE route_key=?);
SQL, ['default.' . $access, $access, 'local-' . $access, 'default.' . $access]);
        }
    }
}
