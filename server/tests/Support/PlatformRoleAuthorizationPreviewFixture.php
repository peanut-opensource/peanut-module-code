<?php

declare(strict_types=1);

final class PlatformRoleAuthorizationPreviewFixture
{
    public static function install(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE pa_platform_operator (id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, status TEXT, security_revision INTEGER DEFAULT 1);
            CREATE TABLE pa_platform_role (id INTEGER PRIMARY KEY, "key" TEXT UNIQUE, status TEXT, is_builtin INTEGER DEFAULT 0, revision INTEGER DEFAULT 1);
            CREATE TABLE pa_platform_operator_role (platform_operator_id INTEGER, platform_role_id INTEGER, UNIQUE(platform_operator_id,platform_role_id));
            CREATE TABLE pa_platform_role_permission (platform_role_id INTEGER, permission_id INTEGER, UNIQUE(platform_role_id,permission_id));
            CREATE TABLE pa_platform_role_authorization_source (id INTEGER PRIMARY KEY, mode TEXT NOT NULL, changed_at TEXT);
            CREATE TABLE pa_platform_role_direct_state (
                platform_role_id INTEGER PRIMARY KEY, role_revision INTEGER NOT NULL, explicit_empty INTEGER NOT NULL,
                catalog_sha256 TEXT NOT NULL, approved_permission_keys_json TEXT NOT NULL,
                auxiliary_permission_keys_json TEXT NOT NULL, updated_at TEXT
            );
            CREATE TABLE pa_platform_role_node (platform_role_id INTEGER NOT NULL, node_key TEXT NOT NULL,
                PRIMARY KEY(platform_role_id,node_key));
            CREATE TABLE pa_platform_role_node_api (platform_role_id INTEGER NOT NULL, node_key TEXT NOT NULL,
                api_id TEXT NOT NULL, PRIMARY KEY(platform_role_id,node_key,api_id));
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, "key" TEXT UNIQUE, status TEXT, api_configured INTEGER DEFAULT 0);
            CREATE TABLE pa_permission_api (id INTEGER PRIMARY KEY, permission_id INTEGER, api_id TEXT, UNIQUE(permission_id,api_id));
            CREATE TABLE pa_system_api (id TEXT PRIMARY KEY, method TEXT, path TEXT, audience TEXT, access_mode TEXT, status TEXT, module_key TEXT, default_permission_keys TEXT, prerequisite_permission_keys TEXT, UNIQUE(method,path,audience));
            CREATE TABLE pa_system_menu (id INTEGER PRIMARY KEY, "key" TEXT UNIQUE, scope TEXT, type TEXT, status TEXT, is_disable INTEGER DEFAULT 0);
            CREATE TABLE pa_credential (id INTEGER PRIMARY KEY, account_id INTEGER, kind TEXT, identifier_type TEXT, identifier_normalized TEXT, status TEXT);
            INSERT INTO pa_platform_operator (id,account_id,status) VALUES (11,111,'active'),(22,222,'active'),(33,333,'active'),(44,444,'active');
            INSERT INTO pa_platform_role (id,"key",status,is_builtin) VALUES (101,'platform.fixture.reader','active',0),(201,'platform.fixture.role-a','active',0),(202,'platform.fixture.role-b','active',0),(301,'platform.bootstrap-owner','active',1),(401,'platform.fixture.fake-owner','active',0);
            INSERT INTO pa_platform_operator_role VALUES (11,101),(22,201),(22,202),(33,301),(44,401);
            INSERT INTO pa_permission VALUES
                (1,'platform.role.read','active',0),(2,'platform.permission.api.read','active',0),(3,'platform.operator.read','active',0),
                (11,'platform.fixture.read','active',0),(12,'platform.fixture.prerequisite','active',0),(13,'platform.fixture.shared','active',0),
                (14,'platform.fixture.empty','active',1),(21,'tenant.fixture.foreign','active',0);
            INSERT INTO pa_platform_role_permission VALUES (101,1),(101,2),(101,3),(201,11),(202,12),(202,13),(202,14);
            INSERT INTO pa_platform_role_authorization_source (id,mode) VALUES (1,'legacy');
            INSERT INTO pa_system_menu VALUES (1,'platform.fixture.retired','platform','page','retired',0),(2,'platform.fixture.foreign','tenant','page','active',0);
            INSERT INTO pa_credential VALUES (1,222,'email_password','email','demo-target@example.test','active');
            SQL);

        $api = $pdo->prepare(<<<'SQL'
            INSERT INTO pa_system_api (id,method,path,audience,access_mode,status,module_key,default_permission_keys,prerequisite_permission_keys)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL);
        $rows = [
            ['a', 'GET', '/platformapi/fixture/read', 'platform', 'platform_permission', 'active', 'platform', ['platform.fixture.read', 'platform.fixture.shared'], []],
            ['b', 'POST', '/platformapi/fixture/write', 'platform', 'platform_permission', 'active', 'platform', ['platform.fixture.read'], ['platform.fixture.prerequisite']],
            ['c', 'GET', '/platformapi/fixture/optional', 'platform', 'platform_permission', 'active', 'fixture.optional', ['platform.fixture.read'], []],
            ['d', 'GET', '/platformapi/fixture/explicit-empty', 'platform', 'platform_permission', 'active', 'platform', ['platform.fixture.empty'], []],
            ['e', 'GET', '/adminapi/fixture/foreign', 'tenant', 'tenant_permission', 'active', 'peanut.admin', ['tenant.fixture.foreign'], []],
            ['f', 'GET', '/platformapi/fixture/retired', 'platform', 'platform_permission', 'retired', 'platform', [], []],
            ['1', 'GET', '/platformapi/fixture/owner', 'platform', 'platform_permission', 'active', 'platform', ['platform.role.read'], []],
        ];
        foreach ($rows as [$id, $method, $path, $audience, $mode, $status, $owner, $defaults, $prerequisites]) {
            $api->execute([str_repeat($id, 64), $method, $path, $audience, $mode, $status, $owner,
                json_encode($defaults, JSON_THROW_ON_ERROR), json_encode($prerequisites, JSON_THROW_ON_ERROR)]);
        }
        $binding = $pdo->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (?,?)');
        foreach ([[1, '1'], [11, 'a'], [11, 'b'], [11, 'c'], [13, 'a'], [21, 'e']] as [$permission, $id]) {
            $binding->execute([$permission, str_repeat($id, 64)]);
        }
    }

    /** @return array<string,mixed> */
    public static function exactCandidate(): array
    {
        $root = 'platform.fixture.group';
        $page = 'platform.fixture.page';
        $button = 'platform.fixture.write';
        $optional = 'platform.fixture.operation';
        return [
            'operator_id' => '22',
            'nodes' => [
                ['key' => $root, 'parent_key' => null, 'type' => 'group', 'api_ids' => []],
                ['key' => $page, 'parent_key' => $root, 'type' => 'page', 'api_ids' => [str_repeat('a', 64)]],
                ['key' => $button, 'parent_key' => $page, 'type' => 'action', 'api_ids' => [str_repeat('b', 64)]],
                ['key' => $optional, 'parent_key' => null, 'type' => 'operation', 'api_ids' => [str_repeat('c', 64)]],
            ],
            'role_assignments' => [
                ['role_id' => '201', 'node_keys' => [$page, $button, $optional]],
                ['role_id' => '202', 'node_keys' => [$page]],
            ],
        ];
    }
}
