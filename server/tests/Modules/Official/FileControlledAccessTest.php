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
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
}
function fileAccessDenied(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
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
        new \PeanutAdmin\Modules\Identity\Audit\AuditService(),
        new \PeanutAdmin\Kernel\Identity\PasswordHasher(),
        new \PeanutAdmin\Kernel\Identity\PasswordPolicy(),
    );
    $platform = $bootstrap->bootstrapPlatformOwner('r0-owner@example.invalid', 'SyntheticR0Acceptance2026!', 'R0 Owner', 'r0-bootstrap');
    $owner = $bootstrap->provisionTenantOwnerCandidate($platform->operatorId, 'default', 'R0 Synthetic', 'r0-owner@example.invalid', null, 'R0 Owner', 'r0-owner');
    $bootstrap->activateTenantOwner($platform->operatorId, $owner->tenantId, $owner->memberId, 'r0-owner-active');
    $bootstrap->activateTenant($platform->operatorId, $owner->tenantId, 'r0-tenant-active');
    $serverRoot = dirname(__DIR__, 3);
    $catalog = new \PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer(
        new \PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository(),
    );
    $catalog->synchronize();
    $opsIds = $pdo->query("SELECT id,`key` FROM pa_permission WHERE `key` IN ('platform.ops.module.manage','platform.ops.upgrade.manage') ORDER BY `key`")->fetchAll(PDO::FETCH_ASSOC);
    $opsBindings = (int) $pdo->query('SELECT COUNT(*) FROM pa_platform_role_permission')->fetchColumn();
    $catalog->synchronize();
    fileAccessExpect($opsIds === $pdo->query("SELECT id,`key` FROM pa_permission WHERE `key` IN ('platform.ops.module.manage','platform.ops.upgrade.manage') ORDER BY `key`")->fetchAll(PDO::FETCH_ASSOC)
        && $opsBindings === (int) $pdo->query('SELECT COUNT(*) FROM pa_platform_role_permission')->fetchColumn(), 'owner alignment is idempotent and preserves permission identities/bindings');
    $pdo->exec("UPDATE pa_permission SET module_key='unknown.synthetic' WHERE `key`='platform.ops.module.manage'");
    try {
        $catalog->synchronize();
        throw new RuntimeException('unknown catalog owner accepted');
    } catch (DomainException $error) {
        fileAccessExpect($error->getMessage() === 'Catalog key is already owned by another module.', 'unknown catalog owner fails before update');
    }
    fileAccessExpect($pdo->query("SELECT module_key FROM pa_permission WHERE `key`='platform.ops.module.manage'")->fetchColumn() === 'unknown.synthetic', 'unknown catalog owner is not taken over');
    $pdo->exec("UPDATE pa_permission SET module_key='platform' WHERE `key`='platform.ops.module.manage'");
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
    $tenant = $owner->tenantId;
    $account = $owner->accountId;
    $member = $owner->memberId;
    $sessionKey = '01K00000000000000000000001';
    $s = $pdo->prepare('INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) SELECT ?,m.tenant_id,m.account_id,m.id,"admin-web","active",a.security_revision,t.security_revision,m.security_revision,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant_member m JOIN pa_account a ON a.id=m.account_id JOIN pa_tenant t ON t.id=m.tenant_id WHERE m.id=?');
    $s->execute([$sessionKey,$member]);
    $sessions = $app->make(TenantSessionAccess::class);
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'r0-native');
    fileAccessExpect($context->tenantId === $tenant && $context->memberId === $member, 'native live session restoration');
    fileAccessDenied(fn() => $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'pc-web', 'r0-client'), 'wrong client');
    fileAccessDenied(fn() => $sessions->revalidateSession($tenant + 1, $account, $member, $sessionKey, 'admin-web', 'r0-foreign'), 'foreign tenant');
    fileAccessDenied(fn() => $sessions->revalidateSession($tenant, $account, $member, '01K00000000000000000000002', 'admin-web', 'r0-unknown'), 'unknown durable session');
    foreach (['idle_expires_at','absolute_expires_at'] as $expiry) {
        $oldExpiry = $pdo->query("SELECT idle_expires_at,absolute_expires_at FROM pa_tenant_session WHERE session_key='{$sessionKey}'")->fetch(PDO::FETCH_ASSOC);
        $alsoIdle = $expiry === 'absolute_expires_at' ? ',idle_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 SECOND)' : '';
        $pdo->exec("UPDATE pa_tenant_session SET {$expiry}=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND){$alsoIdle} WHERE session_key='{$sessionKey}'");
        fileAccessDenied(fn() => $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'r0-expired'), 'expired durable session');
        $s = $pdo->prepare('UPDATE pa_tenant_session SET idle_expires_at=?,absolute_expires_at=? WHERE session_key=?');
        $s->execute([$oldExpiry['idle_expires_at'],$oldExpiry['absolute_expires_at'],$sessionKey]);
    }
    $pdo->exec("UPDATE pa_tenant_member SET security_revision=security_revision+1 WHERE id={$member}");
    fileAccessDenied(fn() => $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'r0-security'), 'changed member security invalidates session');
    $pdo->exec("UPDATE pa_tenant_member SET security_revision=security_revision-1 WHERE id={$member}");
    $stack = $app->make(ExecutionContextStore::class);
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context, 'r0.file'), $call);
    $storage = $app->make(StorageService::class);
    $access = $app->make(FileAccess::class);
    $original = tempnam(sys_get_temp_dir(), 'r0-image-');
    $derivative = tempnam(sys_get_temp_dir(), 'r0-derivative-');
    $paths = [$original,$derivative];
    $image = imagecreatetruecolor(16, 16);
    imagefilledrectangle($image, 0, 0, 15, 15, imagecolorallocate($image, 20, 100, 180));
    imagepng($image, $original);
    $small = imagescale($image, 4, 4);
    imagepng($small, $derivative);
    imagedestroy($small);
    imagedestroy($image);
    $source = $run(fn() => $storage->storePath($tenant, $member, 'document.resource', $original, 'original.png', 'text/plain'));
    $variant = $run(fn() => $storage->storePath($tenant, $member, 'material.image-variant', $derivative, 'preview.png', 'text/plain'));
    $storedPaths = [$serverRoot . '/private/storage/' . $source['object_key'], $serverRoot . '/public/storage/' . $variant['object_key']];
    fileAccessExpect($source['url'] === '', 'private write does not manufacture a download grant');
    $run(fn() => \PeanutAdmin\Modules\File\Model\Storage\FileImageAsset::create(['tenant_id' => $tenant,'file_key' => $source['file_key'],'width' => 16,'height' => 16,'media_type' => 'image/png','created_at' => gmdate('Y-m-d H:i:s'),'updated_at' => gmdate('Y-m-d H:i:s')]));
    $run(fn() => $app->make(\PeanutAdmin\Modules\File\Contract\FileDerivatives::class)->bind($context, $source['file_key'], 'small', $variant['file_key'], 4, 4, 'image/png'));
    fileAccessDenied(fn() => $storage->publicUrl($variant['file_key']), 'public derivative cannot escape private source');
    $resolved = $run(fn() => $access->resolve($context, $source['file_key'], 'download_original'));
    fileAccessExpect(array_key_exists('variant_key', $resolved) && $resolved['variant_key'] === null, 'original delivery returns null selected variant');
    parse_str((string) parse_url($resolved['url'], PHP_URL_QUERY), $delivery);
    $opened = $storage->authorizedDownload((int) $delivery['tenant_id'], $delivery['file_key'], $delivery['token']);
    fileAccessExpect(hash_file('sha256', $opened['path']) === hash_file('sha256', $original) && $opened['disposition'] === 'attachment', 'actual local original bytes');
    fileAccessDenied(fn() => $storage->authorizedDownload((int) $delivery['tenant_id'], $delivery['file_key'], $delivery['token']), 'single-use replay');
    $fresh = $run(fn() => $access->resolve($context, $source['file_key'], 'download_original'));
    parse_str((string) parse_url($fresh['url'], PHP_URL_QUERY), $old);
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    fileAccessDenied(fn() => $storage->authorizedDownload($tenant, $old['file_key'], $old['token']), 'old authorization revision');
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'r0-new-revision');
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context, 'r0.file'), $call);
    $unsupported = $run(fn() => $access->resolve($context, $source['file_key'], 'preview', null, null, 'unregistered.viewer'));
    fileAccessExpect($unsupported['url'] === null && $unsupported['state'] === 'preview_unavailable', 'unsupported tool is explicit');
    $pdo->exec("UPDATE pa_role SET is_builtin=0 WHERE tenant_id={$tenant} AND `key`='core.tenant-owner'");
    $pdo->exec("DELETE rp FROM pa_role_permission rp JOIN pa_role r ON r.id=rp.role_id AND r.tenant_id=rp.tenant_id WHERE rp.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("INSERT IGNORE INTO pa_role_permission(tenant_id,role_id,permission_id,granted_by_member_id,granted_at) SELECT {$tenant},r.id,p.id,{$member},UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.`key`='peanut.file.list' WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'r0-preview-only');
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context, 'r0.file'), $call);
    fileAccessDenied(fn() => $run(fn() => $access->resolve($context, $source['file_key'], 'download_original')), 'preview permission never grants original download');
    $preview = $run(fn() => $access->resolve($context, $source['file_key'], 'preview', null, null, 'builtin.image'));
    fileAccessExpect($preview['variant_key'] === 'small', 'default preview returns actual selected variant');
    $exactPreview = $run(fn() => $access->resolve($context, $source['file_key'], 'preview', null, 'small', 'builtin.image'));
    fileAccessExpect($exactPreview['variant_key'] === 'small' && $exactPreview['bytes'] === 'derivative' && $exactPreview['delivery_file_key'] === $variant['file_key'], 'exact requested variant is the actual derivative');
    $missingPreview = $run(fn() => $access->resolve($context, $source['file_key'], 'preview', null, 'absent', 'builtin.image'));
    fileAccessExpect($missingPreview['state'] === 'preview_unavailable' && $missingPreview['url'] === null, 'missing exact variant cannot fall back to original or another derivative');
    fileAccessExpect(($preview['bytes'] ?? null) === 'derivative' && !$preview['can_download_original'] && ($preview['delivery_file_key'] ?? null) === $variant['file_key'], 'preview-only uses registered derivative');
    parse_str((string) parse_url($preview['url'], PHP_URL_QUERY), $previewDelivery);
    $opened = $storage->authorizedDownload($tenant, $previewDelivery['file_key'], $previewDelivery['token']);
    fileAccessExpect($opened['disposition'] === 'inline' && hash_file('sha256', $opened['path']) === hash_file('sha256', $derivative) && hash_file('sha256', $opened['path']) !== hash_file('sha256', $original), 'actual derivative bytes without download right');
    fileAccessDenied(fn() => $run(fn() => $storage->delete($tenant, $source['file_key'])), 'upload/preview permission is not ordinary delete permission');
    $pdo->exec("INSERT IGNORE INTO pa_role_permission(tenant_id,role_id,permission_id,granted_by_member_id,granted_at) SELECT {$tenant},r.id,p.id,{$member},UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.`key`='peanut.file.upload.image' WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("ALTER TABLE pa_file ADD CONSTRAINT chk_r0_upload_metadata_failure CHECK (name <> 'r0-failed.png')");
    try {
        $run(fn() => $app->make(\PeanutAdmin\Modules\File\Contract\FileUploads::class)->image(
            $context,
            new \PeanutAdmin\Modules\File\Contract\Dto\UploadFile($original, 'r0-failed.png', filesize($original), 'image/png', 'png'),
            0,
        ));
        throw new RuntimeException('metadata failure accepted');
    } catch (Throwable $error) {
        fileAccessExpect(str_contains($error->getMessage(), 'chk_r0_upload_metadata_failure'), 'original metadata transaction error survives compensation');
    } finally {
        $pdo->exec('ALTER TABLE pa_file DROP CHECK chk_r0_upload_metadata_failure');
    }
    $failedUpload = $pdo->query("SELECT file_key,object_key,status FROM pa_file_object WHERE original_name='r0-failed.png'")->fetch(PDO::FETCH_ASSOC);
    fileAccessExpect(is_array($failedUpload) && $failedUpload['status'] === 'archived'
        && !is_file($serverRoot . '/public/storage/' . $failedUpload['object_key'])
        && (int) $pdo->query("SELECT COUNT(*) FROM pa_file WHERE file_key='{$failedUpload['file_key']}'")->fetchColumn() === 0, 'failed metadata write rolls back and exact new upload is physically compensated');
    fileAccessExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE event_type='file.upload.compensated'")->fetchColumn() === 1, 'upload compensation has accurate audit');
    $pdo->exec("UPDATE pa_role SET is_builtin=1 WHERE tenant_id={$tenant} AND `key`='core.tenant-owner'");
    $pdo->exec("INSERT IGNORE INTO pa_role_permission(tenant_id,role_id,permission_id,granted_at) SELECT r.tenant_id,r.id,p.id,UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.module_key='peanut.file' WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'r0-owner-restored');
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context, 'r0.file'), $call);
    fileAccessDenied(fn() => $run(fn() => $access->resolve($context, $source['file_key'], 'preview', 39, null, 'builtin.image')), 'Document policy absent');
    fileAccessDenied(fn() => $run(fn() => $app->make(FileReferenceCommands::class)->replaceDocumentReferences($context, 39, [])), 'empty references still need Document owner');
    foreach ([$fresh['url'],'https://external.invalid/a.png','storage/tenants/v1/1/a.png','../private/a'] as $invalid) {
        fileAccessDenied(fn() => $storage->normalizePublicReference($tenant, $invalid), 'unstable reference rejected');
    }
    $s = $pdo->prepare('INSERT INTO pa_file_reference(tenant_id,file_key,owner_module_key,owner_type,owner_id,reference_digest,updated_at) VALUES(?,?,"peanut.document","document","39","synthetic-current-reference",UTC_TIMESTAMP(3))');
    $s->execute([$tenant,$source['file_key']]);
    $before = (int) $pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn();
    fileAccessDenied(fn() => $run(fn() => $storage->delete($tenant, $source['file_key'])), 'referenced deletion protected');
    fileAccessExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn() === $before, 'rejected delete emits no success audit');
    foreach (['revision','access_type'] as $changedField) {
        $fresh = $run(fn() => $access->resolve($context, $source['file_key'], 'download_original'));
        parse_str((string) parse_url($fresh['url'], PHP_URL_QUERY), $old);
        if ($changedField === 'revision') {
            $pdo->exec("UPDATE pa_file_object SET revision=revision+1 WHERE file_key='{$source['file_key']}'");
        } else {
            $pdo->exec("UPDATE pa_file_object SET access_type='public' WHERE file_key='{$source['file_key']}'");
        }
        fileAccessDenied(fn() => $storage->authorizedDownload($tenant, $old['file_key'], $old['token']), 'changed file revision/visibility rejects old grant');
        if ($changedField === 'access_type') {
            $pdo->exec("UPDATE pa_file_object SET access_type='private' WHERE file_key='{$source['file_key']}'");
        }
    }
    $fresh = $run(fn() => $access->resolve($context, $source['file_key'], 'download_original'));
    parse_str((string) parse_url($fresh['url'], PHP_URL_QUERY), $old);
    fileAccessDenied(fn() => $storage->authorizedDownload($tenant + 1, $old['file_key'], $old['token']), 'cross-tenant old grant rejected');
    $fresh = $run(fn() => $access->resolve($context, $source['file_key'], 'download_original'));
    parse_str((string) parse_url($fresh['url'], PHP_URL_QUERY), $old);
    $pdo->exec("UPDATE pa_tenant_license SET status='suspended',revision=revision+1 WHERE tenant_id={$tenant}");
    fileAccessDenied(fn() => $storage->authorizedDownload($tenant, $old['file_key'], $old['token']), 'disabled File rejects old grant');
    $pdo->exec("UPDATE pa_tenant_license SET status='active',revision=revision+1 WHERE tenant_id={$tenant}");
    $fresh = $run(fn() => $access->resolve($context, $source['file_key'], 'download_original'));
    parse_str((string) parse_url($fresh['url'],PHP_URL_QUERY),$old);
    $pdo->exec("UPDATE pa_tenant_session SET status='revoked',revoked_at=UTC_TIMESTAMP(3) WHERE session_key='{$sessionKey}'");
    fileAccessDenied(fn() => $storage->authorizedDownload($tenant,$old['file_key'],$old['token']),'revoked durable session rejects old grant');
    fileAccessDenied(fn() => $sessions->revalidateSession($tenant,$account,$member,$sessionKey,'admin-web','r0-revoked'),'revoked session cannot mint context');
    echo 'FILE_CONTROLLED_ACCESS_OK assertions=' . $assertions . ' real_mysql=8.4 local_bytes=real document_provider=deferred' . PHP_EOL;
} finally {
    foreach (array_merge($paths,$storedPaths) as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
    RegisteredMysqlTestResource::cleanup($pdo,$database,$created);
}
