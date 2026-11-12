<?php

declare(strict_types=1);

use PeanutAdmin\Modules\File\Contract\FileAdministration;
use PeanutAdmin\Modules\File\Contract\FileUploads;
use PeanutAdmin\Modules\File\Contract\Dto\UploadFile;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\File\Value\Storage\StoragePath;
use PeanutAdmin\Modules\File\Model\File;
use PeanutAdmin\Modules\File\Model\FileCate;
use PeanutAdmin\Kernel\Auth\TenantContext;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../Support/CiBootstrap.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';

function expectFileTenant(bool $condition, string $message): void
{
    $GLOBALS['fileTenantChecks'] = ($GLOBALS['fileTenantChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fileTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return app(\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess::class)->revalidateSession(
        $tenantId, $memberId + 10000, $memberId, str_pad((string) $memberId, 26, '0', STR_PAD_LEFT), 'admin-web', $requestId,
    );
}

function createFileTenantSchema(PDO $pdo, string $serverRoot): void
{
    IdentityMysqlFixture::install($pdo, [
        $serverRoot . '/app/modules/peanut/file/database/migrations',
        $serverRoot . '/app/modules/peanut/settings/database/migrations',
        $serverRoot . '/app/modules/peanut/reference_codes/database/migrations',
        $serverRoot . '/database/installation',
    ]);
    require_once $serverRoot . '/app/modules/peanut/file/database/seeds/PeanutFileDefaults.php';
    $seed = new PeanutFileDefaults();
    $seed->setAdapter(new \Phinx\Db\Adapter\MysqlAdapter(['name' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'connection' => $pdo]));
    $seed->run();
}

$serverRoot = dirname(__DIR__, 2);
$runId = 'a1-file';
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $databaseCreated] = RegisteredMysqlTestResource::openEmptyDatabase($database);

$alphaObject = '';
$betaObject = '';
$alphaSource = tempnam(sys_get_temp_dir(), 'pa-file-alpha-');
$betaSource = tempnam(sys_get_temp_dir(), 'pa-file-beta-');
if (!is_string($alphaSource) || !is_string($betaSource)) {
    throw new RuntimeException('file upload fixtures could not be created');
}
foreach ([[$alphaSource, 30], [$betaSource, 180]] as [$source, $color]) {
    $image = imagecreatetruecolor(2, 2);
    imagefill($image, 0, 0, imagecolorallocate($image, $color, 60, 90));
    imagepng($image, $source);
    imagedestroy($image);
}

try {
    createFileTenantSchema($pdo, $serverRoot);
    $pdo->exec("INSERT INTO pa_account (id,display_name,status,security_revision,created_at,updated_at) VALUES (10501,'Alpha','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(10502,'Beta','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,locale,timezone,security_revision,authorization_revision,revision,activated_at,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active','zh-CN','Asia/Shanghai',1,1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active','zh-CN','Asia/Shanghai',1,1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant_member (id,tenant_id,account_id,member_no,display_name,member_type,status,security_revision,authorization_revision,joined_at,created_at,updated_at) VALUES (501,101,10501,'alpha-501','Alpha','internal','active',1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(502,202,10502,'beta-502','Beta','internal','active',1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_file_cate (id, tenant_id, pid, type, name) VALUES (11, 101, 0, 10, 'Alpha seed')");
    $app = think\Container::getInstance();
    $app->env->set(['DEPLOYMENT_MODE' => 'multi-tenant', 'APP_ENV' => 'development', 'APP_DEBUG' => true, 'JWT_SECRET' => str_repeat('f', 32)]);
    $app->initialize();
    $app->cache->clear();
    ThinkPhpTestConnection::fromPdo($pdo);
    $pdo->exec("INSERT INTO pa_credential(account_id,kind,identifier_type,identifier_normalized,secret_hash,status,verified_at,secret_changed_at,created_at,updated_at) SELECT id,'email_password','email',CONCAT('fixture-',id,'@example.test'),'fixture','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_account");
    $registry = $app->make(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class);
    foreach (['peanut.identity', 'peanut.file', 'peanut.content-tools'] as $moduleKey) {
        $manifest = $registry->requireManifest($moduleKey);
        $pdo->prepare('INSERT INTO pa_module_installation(module_key,installed_version,manifest_schema_version,manifest_digest,status,installed_at,activated_at,created_at,updated_at) VALUES(?,?,?,?,"active",UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))')
            ->execute([$moduleKey, $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
    }
    $app->make(\app\platform\infrastructure\module\ModuleCatalogApplier::class)->apply($registry, ['peanut.identity', 'peanut.file', 'peanut.content-tools']);
    $pdo->exec("INSERT INTO pa_plan(id,code,name,status,created_at,updated_at) VALUES(1,'fixture-file','File fixture','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_plan_module(plan_id,module_key) VALUES(1,'peanut.file')");
    $pdo->exec("INSERT INTO pa_plan_permission(plan_id,permission_key) SELECT 1,`key` FROM pa_permission WHERE module_key='peanut.file'");
    $pdo->exec("INSERT INTO pa_tenant_license(tenant_id,plan_id,status,effective_at,created_at,updated_at) SELECT id,1,'active','2020-01-01 00:00:00.000',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant");
    $pdo->exec("INSERT INTO pa_role(id,tenant_id,`key`,name,is_builtin,status,created_at,updated_at) VALUES(601,101,'core.tenant-owner','Alpha owner',1,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(602,202,'core.tenant-owner','Beta owner',1,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_member_role(tenant_id,tenant_member_id,role_id,assigned_at) VALUES(101,501,601,UTC_TIMESTAMP(3)),(202,502,602,UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) SELECT LPAD(m.id,26,'0'),m.tenant_id,m.account_id,m.id,'admin-web','active',a.security_revision,t.security_revision,m.security_revision,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant_member m JOIN pa_account a ON a.id=m.account_id JOIN pa_tenant t ON t.id=m.tenant_id");
    $pdo->exec("INSERT INTO pa_role_permission(tenant_id,role_id,permission_id,granted_at) SELECT r.tenant_id,r.id,p.id,UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.module_key='peanut.file' WHERE r.`key`='core.tenant-owner'");
    $files = app(FileAdministration::class);
    $uploads = app(FileUploads::class);

    $alpha = fileTenantContext(101, 501, 'mt03-file-alpha-' . $runId);
    $beta = fileTenantContext(202, 502, 'mt03-file-beta-' . $runId);
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly succeeded');
    } catch (Throwable $exception) {
        expectFileTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }

    expectFileTenant(
        StoragePath::objectKey($alpha->tenantId, 'image.upload', 'file_' . str_repeat('a', 32), 'png')
            !== StoragePath::objectKey($beta->tenantId, 'image.upload', 'file_' . str_repeat('a', 32), 'png'),
        'two Tenants share the same object namespace',
    );
    expectFileTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.add.alpha'),
            fn() => $files->addCategory(['tenant_id' => 202, 'pid' => 0, 'type' => 10, 'name' => 'Same category']),
        ) === null,
        'Alpha category was not created',
    );
    expectFileTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.category.add.beta'),
            fn() => $files->addCategory(['tenant_id' => 101, 'pid' => 0, 'type' => 10, 'name' => 'Same category']),
        ) === null,
        'Beta category was not created',
    );
    $alphaCategory = (int) app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.query.alpha'),
        fn() => FileCate::where([])->where('name', 'Same category')->value('id'),
    );
    $betaCategory = (int) app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($beta, 'test.file.category.query.beta'),
        fn() => FileCate::where([])->where('name', 'Same category')->value('id'),
    );
    expectFileTenant($alphaCategory > 0 && $betaCategory > 0, 'same-name Tenant categories were not created');
    expectFileTenant(
        (int) $pdo->query("SELECT tenant_id FROM pa_file_cate WHERE id = {$alphaCategory}")->fetchColumn() === 101,
        'request payload forged category Tenant ownership',
    );

    $alphaUpload = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.file.create.alpha'),
        fn() => $uploads->image(
            $alpha,
            new UploadFile($alphaSource, 'same.png', filesize($alphaSource), 'image/png', 'png'),
            $alphaCategory,
            501,
        ),
    );
    $betaUpload = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($beta, 'test.file.create.beta'),
        fn() => $uploads->image(
            $beta,
            new UploadFile($betaSource, 'same.png', filesize($betaSource), 'image/png', 'png'),
            $betaCategory,
            502,
        ),
    );
    $alphaFile = (int) $alphaUpload['id'];
    $betaFile = (int) $betaUpload['id'];
    $alphaObjectKey = (string) $pdo->query("SELECT object_key FROM pa_file_object WHERE file_key=" . $pdo->quote((string) $alphaUpload['file_key']))->fetchColumn();
    $betaObjectKey = (string) $pdo->query("SELECT object_key FROM pa_file_object WHERE file_key=" . $pdo->quote((string) $betaUpload['file_key']))->fetchColumn();
    $alphaObject = $serverRoot . '/public/storage/' . $alphaObjectKey;
    $betaObject = $serverRoot . '/public/storage/' . $betaObjectKey;
    expectFileTenant($alphaFile > 0 && $betaFile > 0, 'same-name Tenant files were not created');
    expectFileTenant(is_file($alphaObject) && is_file($betaObject), 'canonical storage driver did not persist both Tenant objects');

    expectFileTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.file.list.alpha'),
            fn() => $files->lists(['type' => 10, 'name' => 'same.png']),
        )->items) === 1,
        'Alpha file list leaked or lost same-name files',
    );
    expectFileTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.list.beta'),
            fn() => $files->lists(['type' => 10, 'name' => 'same.png']),
        )->items) === 1,
        'Beta file list leaked or lost same-name files',
    );
    expectFileTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.list.alpha'),
            fn() => $files->categoryLists(10),
        )) === 2,
        'Alpha category tree leaked or lost categories',
    );
    expectFileTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.category.list.beta'),
            fn() => $files->categoryLists(10),
        )) === 1,
        'Beta category tree leaked or lost categories',
    );

    foreach ([$betaCategory, 999999] as $target) {
        try {
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.delete.denied'),
                fn() => $files->deleteCategory($target),
            );
            throw new RuntimeException('cross/missing category delete unexpectedly succeeded');
        } catch (InvalidArgumentException $exception) {
            expectFileTenant($exception->getMessage() === '分类不存在', 'category denial enumerated Tenant ownership');
        }
    }
    foreach ([$betaFile, 999999] as $target) {
        try {
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($alpha, 'test.file.delete.denied'),
                fn() => $files->delete([$target]),
            );
            throw new RuntimeException('cross/missing file delete unexpectedly succeeded');
        } catch (InvalidArgumentException $exception) {
            expectFileTenant($exception->getMessage() === '包含不存在的素材', 'file denial enumerated Tenant ownership');
        }
    }
    try {
        StoragePath::objectKey(101, 'image.upload', 'forged', 'png');
        throw new RuntimeException('invalid file identity unexpectedly produced an object key');
    } catch (InvalidArgumentException $exception) {
        expectFileTenant($exception->getMessage() === '文件身份无效', 'object identity denial changed');
    }

    $result = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.delete.alpha'),
        fn() => $files->deleteCategory($alphaCategory),
    );
    expectFileTenant($result === ['categories_deleted' => 1, 'files_deleted' => 1, 'storage_deleted' => 1], 'Alpha category cleanup result changed');
    expectFileTenant(!file_exists($alphaObject), 'Alpha object survived Tenant cleanup');
    expectFileTenant(file_exists($betaObject) && file_get_contents($betaObject) === file_get_contents($betaSource), 'Alpha cleanup touched Beta object');
    expectFileTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.category.query.beta'),
            fn() => FileCate::where([])->where('id', $betaCategory)->find() !== null,
        ),
        'Alpha cleanup deleted Beta category',
    );
    expectFileTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.query.beta'),
            fn() => File::where([])->where('id', $betaFile)->find() !== null,
        ),
        'Alpha cleanup deleted Beta file row',
    );
    expectFileTenant((int) $pdo->query("SELECT COUNT(*) FROM pa_file WHERE tenant_id = 202 AND delete_time IS NULL")->fetchColumn() === 1, 'Beta active file count changed');

    echo 'MT03-FILE-TENANT-OWNERSHIP-001 passed checks=' . $GLOBALS['fileTenantChecks'] . PHP_EOL;
} finally {
    if ($alphaObject !== '' && file_exists($alphaObject)) {
        unlink($alphaObject);
    }
    if ($betaObject !== '' && file_exists($betaObject)) {
        unlink($betaObject);
    }
    foreach ([$alphaObject, $betaObject] as $path) {
        if ($path !== '') {
            @rmdir(dirname($path));
            @rmdir(dirname($path, 2));
            @rmdir(dirname($path, 3));
        }
    }
    foreach ([$alphaSource, $betaSource] as $source) {
        if (is_file($source)) {
            unlink($source);
        }
    }
    RegisteredMysqlTestResource::cleanup($pdo, $database, $databaseCreated);
}
