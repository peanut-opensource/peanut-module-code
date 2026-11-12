<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Modules\File\Contract\FileAccess;
use PeanutAdmin\Modules\File\Contract\FileReferenceCommands;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 2) . '/Support/ThinkPhpTestConnection.php';
require_once dirname(__DIR__, 2) . '/Support/IdentityMysqlFixture.php';

$assertions = 0;
function fileAccessExpect(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) { throw new RuntimeException($message); }
    $assertions++;
}
function fileAccessDenied(callable $operation, string $message): void
{
    try { $operation(); } catch (Throwable $error) {
        fileAccessExpect($error instanceof \app\common\exception\BusinessException
            || $error instanceof \PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException
            || $error instanceof \PeanutAdmin\Kernel\Module\ModuleException, $message . ': unexpected failure ' . $error::class);
        return;
    }
    throw new RuntimeException($message . ': accepted');
}

$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
$paths = [];
$storedPaths = [];
try {
    $serverRoot = dirname(__DIR__, 3);
    IdentityMysqlFixture::install($pdo, [
        $serverRoot . '/app/modules/peanut/file/database/migrations',
        $serverRoot . '/app/modules/peanut/settings/database/migrations',
        $serverRoot . '/app/modules/peanut/reference_codes/database/migrations',
        $serverRoot . '/database/installation',
    ]);
    require_once $serverRoot . '/app/modules/peanut/file/database/seeds/PeanutFileDefaults.php';
    $seed = new PeanutFileDefaults();
    $seed->setAdapter(new \Phinx\Db\Adapter\MysqlAdapter(['name' => $database, 'connection' => $pdo]));
    $seed->run();
    ThinkPhpTestConnection::fromPdo($pdo);
    $bootstrap = new \PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService(
        new \PeanutAdmin\Modules\Identity\Audit\AuditService(), new \PeanutAdmin\Kernel\Identity\PasswordHasher(), new \PeanutAdmin\Kernel\Identity\PasswordPolicy(),
    );
    $platform = $bootstrap->bootstrapPlatformOwner('r0-owner@example.invalid', 'SyntheticR0Acceptance2026!', 'R0 Owner', 'r0-bootstrap');
    $owner = $bootstrap->provisionTenantOwnerCandidate($platform->operatorId, 'default', 'R0 Synthetic', 'r0-owner@example.invalid', null, 'R0 Owner', 'r0-owner');
    $bootstrap->activateTenantOwner($platform->operatorId, $owner->tenantId, $owner->memberId, 'r0-owner-active');
    $bootstrap->activateTenant($platform->operatorId, $owner->tenantId, 'r0-tenant-active');
    $serverRoot = dirname(__DIR__, 3);
    $app = think\Container::getInstance();
    $app->env->set(['DEPLOYMENT_MODE' => 'multi-tenant', 'APP_ENV' => 'development', 'APP_DEBUG' => true, 'JWT_SECRET' => str_repeat('f', 32)]);
    $app->initialize();
    $app->cache->clear();
    ThinkPhpTestConnection::fromPdo($pdo);
    $registry = $app->make(CompiledModuleRegistry::class);
    foreach (['peanut.identity', 'peanut.file', 'peanut.content-tools'] as $moduleKey) {
        $manifest = $registry->requireManifest($moduleKey);
        $s = $pdo->prepare('INSERT INTO pa_module_installation(module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,installed_at,activated_at,created_at,updated_at) VALUES(?,?,?,?,"active",1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))');
        $s->execute([$moduleKey, $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
    }
    $pdo->exec("INSERT INTO pa_plan(id,code,name,status,created_at,updated_at) VALUES(1,'fixture-file','File fixture','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_plan_module(plan_id,module_key) VALUES(1,'peanut.file'),(1,'peanut.content-tools')");
    $pdo->exec("INSERT INTO pa_tenant_license(tenant_id,plan_id,status,effective_at,created_at,updated_at) VALUES({$owner->tenantId},1,'active','2020-01-01 00:00:00.000',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $app->make(\app\platform\infrastructure\module\ModuleCatalogApplier::class)->apply($registry, ['peanut.identity', 'peanut.file', 'peanut.content-tools']);
    $pdo->exec("INSERT INTO pa_plan_permission(plan_id,permission_key) SELECT 1,`key` FROM pa_permission WHERE module_key='peanut.file'");
    $pdo->exec("INSERT INTO pa_role_permission(tenant_id,role_id,permission_id,granted_at) SELECT r.tenant_id,r.id,p.id,UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.module_key='peanut.file' WHERE r.`key`='core.tenant-owner'");
    $tenant = $owner->tenantId; $account = $owner->accountId; $member = $owner->memberId;
    $sessionKey = '01K00000000000000000000001';
    $s = $pdo->prepare('INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) SELECT ?,m.tenant_id,m.account_id,m.id,"admin-web","active",a.security_revision,t.security_revision,m.security_revision,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant_member m JOIN pa_account a ON a.id=m.account_id JOIN pa_tenant t ON t.id=m.tenant_id WHERE m.id=?');
    $s->execute([$sessionKey,$member]);
    $sessions = $app->make(TenantSessionAccess::class);
    $context = $sessions->revalidateSession($tenant,$account,$member,$sessionKey,'admin-web','r0-native');
    $stack = $app->make(ExecutionContextStore::class);
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context,'r0.file.edges'),$call);
    $storage = $app->make(StorageService::class);
    $xlsx = $app->make(\app\common\services\XlsxExportService::class);
    $original = tempnam(sys_get_temp_dir(),'r0-edge-image-'); $paths[] = $original;
    $image = imagecreatetruecolor(4,4); imagepng($image,$original); imagedestroy($image);
    $objectCount = (int)$pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn();
    $pdo->exec("UPDATE pa_role SET is_builtin=0 WHERE tenant_id={$tenant} AND `key`='core.tenant-owner'");
    $pdo->exec("DELETE rp FROM pa_role_permission rp JOIN pa_permission p ON p.id=rp.permission_id WHERE rp.tenant_id={$tenant} AND p.`key`='peanut.file.download-original'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    $context = $sessions->revalidateSession($tenant,$account,$member,$sessionKey,'admin-web','r0-export-denied');
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context,'r0.file.edges'),$call);
    fileAccessDenied(fn()=>$run(fn()=>$xlsx->create('denied-export',['name'],[['synthetic']])), 'export needs independent original-download right');
    fileAccessExpect((int)$pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn()===$objectCount, 'denied export creates no object');
    $pdo->exec("UPDATE pa_role SET is_builtin=1 WHERE tenant_id={$tenant} AND `key`='core.tenant-owner'");
    $pdo->exec("INSERT INTO pa_role_permission(tenant_id,role_id,permission_id,granted_at) SELECT r.tenant_id,r.id,p.id,UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.`key`='peanut.file.download-original' WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    $context = $sessions->revalidateSession($tenant,$account,$member,$sessionKey,'admin-web','r0-export-allowed');
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context,'r0.file.edges'),$call);
    $export = $run(fn()=>$xlsx->create('accepted-export',['name'],[['synthetic']]));
    $storedPaths[]=$serverRoot.'/private/storage/'.$export['object_key'];
    fileAccessExpect($export['access_type']==='private' && $export['url']!=='' && preg_match('/^file_[0-9a-f]{32}$/D',$export['file_key'])===1, 'export retains stable key and returns independent authorized download');
    parse_str((string)parse_url($export['url'],PHP_URL_QUERY),$delivery);
    $opened=$storage->authorizedDownload($tenant,$delivery['file_key'],$delivery['token']);
    $zip=new ZipArchive(); $zipOk=$zip->open($opened['path'])===true;
    fileAccessExpect($zipOk && str_contains((string)$zip->getFromName('xl/worksheets/sheet1.xml'),'synthetic') && $opened['disposition']==='attachment', 'real XLSX bytes download as attachment');
    if ($zipOk) $zip->close();
    $pdo->exec('DELETE FROM pa_file_delivery_grant');
    foreach (['public','download_original'] as $operation) {
        $constraint='chk_r0_grant_failure';
        $pdo->exec("ALTER TABLE pa_file_delivery_grant ADD CONSTRAINT {$constraint} CHECK (operation <> '{$operation}')");
        $name=$operation==='public'?'r0-public-grant-failed.png':'r0-export-grant-failed';
        try {
            if ($operation==='public') {
                $run(fn()=>$app->make(\PeanutAdmin\Modules\File\Contract\FileUploads::class)->image($context,
                    new \PeanutAdmin\Modules\File\Contract\Dto\UploadFile($original,$name,filesize($original),'image/png','png'),0));
            } else {
                $run(fn()=>$xlsx->create($name,['name'],[['synthetic']]));
            }
            throw new RuntimeException('grant failure accepted');
        } catch (Throwable $error) {
            fileAccessExpect(str_contains($error->getMessage(),$constraint),'original grant preparation error survives compensation');
        } finally { $pdo->exec("ALTER TABLE pa_file_delivery_grant DROP CHECK {$constraint}"); }
        $s=$pdo->prepare('SELECT file_key,object_key,status FROM pa_file_object WHERE original_name LIKE ? ORDER BY id DESC LIMIT 1');
        $s->execute([$name.'%']);$failed=$s->fetch(PDO::FETCH_ASSOC);
        $physical=$serverRoot.($operation==='public'?'/public/storage/':'/private/storage/').($failed['object_key']??'missing');
        fileAccessExpect(is_array($failed) && $failed['status']==='archived' && !is_file($physical)
            && (int)$pdo->query("SELECT COUNT(*) FROM pa_file WHERE file_key='{$failed['file_key']}'")->fetchColumn()===0, 'unreturned ready object is physically compensated with no metadata');
    }
    fileAccessExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE event_type='file.upload.compensated'")->fetchColumn()===2, 'grant failures have exact acknowledged compensation audits');
    foreach ([['html','<!DOCTYPE html><html><body><script>alert(1)</script></body></html>','text/html'],
        ['svg','<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>','image/svg+xml'],
        ['unknown',"\x00\x01\x02\x03\xff\xfe\x00\x04",'application/octet-stream']] as [$label,$bytes,$mime]) {
        $input=tempnam(sys_get_temp_dir(),'r0-active-');$paths[]=$input;file_put_contents($input,$bytes);
        $stored=$run(fn()=>$app->make(\PeanutAdmin\Modules\File\Contract\FileUploads::class)->file($context,
            new \PeanutAdmin\Modules\File\Contract\Dto\UploadFile($input,'r0-'.$label.'.txt',strlen($bytes),'text/plain','txt'),0));
        $object=$pdo->query("SELECT object_key,media_type FROM pa_file_object WHERE file_key='{$stored['file_key']}'")->fetch(PDO::FETCH_ASSOC);
        $storedPaths[]=$serverRoot.'/public/storage/'.$object['object_key'];
        $url=$storage->publicUrl($stored['file_key']);parse_str((string)parse_url($url,PHP_URL_QUERY),$delivery);
        $opened=$storage->authorizedDownload($tenant,$delivery['file_key'],$delivery['token']);
        fileAccessExpect($object['media_type']===$mime && $opened['disposition']==='attachment'
            && file_get_contents($opened['path'])===$bytes,'actual active/unknown MIME is attachment without altering bytes');
        $private=$run(fn()=>$storage->storePath($tenant,$member,'document.resource',$input,'r0-private-'.$label.'.txt','text/plain'));
        $storedPaths[]=$serverRoot.'/private/storage/'.$private['object_key'];
        $url=$run(fn()=>$storage->accessUrlForTenant($tenant,$private['file_key']));parse_str((string)parse_url($url,PHP_URL_QUERY),$delivery);
        $opened=$storage->authorizedDownload($tenant,$delivery['file_key'],$delivery['token']);
        fileAccessExpect($opened['disposition']==='attachment' && file_get_contents($opened['path'])===$bytes,'private active/unknown MIME original download remains attachment');
    }
    $safe=$run(fn()=>$storage->storePath($tenant,$member,'material.image',$original,'safe.png','image/png'));
    $storedPaths[]=$serverRoot.'/public/storage/'.$safe['object_key'];parse_str((string)parse_url($safe['url'],PHP_URL_QUERY),$delivery);
    $opened=$storage->authorizedDownload($tenant,$delivery['file_key'],$delivery['token']);
    fileAccessExpect($opened['disposition']==='inline' && hash_file('sha256',$opened['path'])===hash_file('sha256',$original),'safe verified PNG still previews inline');
    echo 'FILE_DELIVERY_EDGE_CASES_OK assertions='.$assertions.' real_mysql=8.4 local_bytes=real'.PHP_EOL;
} finally {
    foreach (array_merge($paths,$storedPaths) as $path) { if (is_string($path) && is_file($path)) unlink($path); }
    RegisteredMysqlTestResource::cleanup($pdo,$database,$created);
}
