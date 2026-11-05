<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Model\DocumentRevision;
use PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots;
use PeanutAdmin\Modules\File\Contract\FileAccess;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Modules\File\Service\FileAuthorizationService;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use think\facade\Db;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/Support/IdentityMysqlFixture.php';
require_once dirname(__DIR__, 3) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 3) . '/Support/ThinkPhpTestConnection.php';

$assertions = 0;
function documentExpect(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
}
function documentDenied(callable $operation, string $message, ?string $errorCode = null): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        documentExpect($error instanceof \app\common\exception\BusinessException
            || $error instanceof \PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException
            || $error instanceof \PeanutAdmin\Kernel\Module\ModuleException, $message . ': unexpected ' . $error::class . ': ' . $error->getMessage());
        if ($errorCode !== null) {
            documentExpect(($error->errorCode ?? null) === $errorCode, $message . ': wrong rejection reason: ' . ($error->errorCode ?? $error::class));
        }
        return;
    }
    throw new RuntimeException($message . ': accepted');
}
function documentProjection(string $text, ?string $fileKey = null): array
{
    $nodes = [['type' => 'paragraph','content' => [['type' => 'text','text' => $text]]]];
    if ($fileKey !== null) {
        $nodes[] = ['type' => 'media','attrs' => ['fileKey' => $fileKey,'kind' => 'image']];
    }
    return ['schemaVersion' => RichTextProjection::SCHEMA,'editorModel' => 'tiptap-prosemirror','content' => ['type' => 'doc','content' => $nodes],'annotations' => []];
}
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
$paths = [];
try {
    $serverRoot = dirname(__DIR__, 4);
    IdentityMysqlFixture::install($pdo, array_map(
        static fn(string $module): string => $serverRoot . '/app/modules/peanut/' . $module . '/database/migrations',
        ['settings', 'reference_codes', 'file', 'member', 'article', 'document', 'task', 'collaboration'],
    ));
    ThinkPhpTestConnection::fromPdo($pdo);
    $bootstrap = new \PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService(
        new \PeanutAdmin\Modules\Identity\Audit\AuditService(),
        new \PeanutAdmin\Kernel\Identity\PasswordHasher(),
        new \PeanutAdmin\Kernel\Identity\PasswordPolicy(),
    );
    $platform = $bootstrap->bootstrapPlatformOwner('b1-owner@example.invalid', 'SyntheticB1Acceptance2026!', 'B1 Owner', 'b1-bootstrap');
    $owner = $bootstrap->provisionTenantOwnerCandidate($platform->operatorId, 'default', 'B1 Synthetic', 'b1-owner@example.invalid', null, 'B1 Owner', 'b1-owner');
    $bootstrap->activateTenantOwner($platform->operatorId, $owner->tenantId, $owner->memberId, 'b1-owner-active');
    $bootstrap->activateTenant($platform->operatorId, $owner->tenantId, 'b1-tenant-active');
    $app = \think\Container::getInstance();
    if (!$app instanceof \think\App) {
        throw new RuntimeException('EXPLICIT_TEST_APPLICATION_REQUIRED');
    }
    $app->env->set(['DEPLOYMENT_MODE' => 'multi-tenant', 'APP_ENV' => 'development', 'APP_DEBUG' => 'true', 'JWT_SECRET' => str_repeat('d', 48), 'ASYNC_SIGNING_KEY' => str_repeat('e', 32)]);
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $registry = $app->make(CompiledModuleRegistry::class);
    $seeds = new \app\common\module\ModuleSeedRun($registry->requireManifest('peanut.file'));
    $seeds->setApp($app);
    $seeds->run(new \think\console\Input([]), new \think\console\Output('buffer'));
    $pdo->exec("INSERT INTO pa_plan(code,name,status,revision,created_at,updated_at) VALUES('document-fixture','Document fixture','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $planId = (int) $pdo->lastInsertId();
    $license = $pdo->prepare("INSERT INTO pa_tenant_license(tenant_id,plan_id,status,effective_at,revision,created_at,updated_at) VALUES(?,?,'active',UTC_TIMESTAMP(3),1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $license->execute([$owner->tenantId, $planId]);
    foreach (['peanut.identity', 'peanut.file', 'peanut.content-tools', 'peanut.rich-text', 'peanut.document', 'peanut.article', 'peanut.collaboration'] as $moduleKey) {
        $manifest = $registry->requireManifest($moduleKey);
        $s = $pdo->prepare('INSERT INTO pa_module_installation(module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,installed_at,activated_at,created_at,updated_at) VALUES(?,?,?,?,"active",1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))');
        $s->execute([$moduleKey, $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
        $s = $pdo->prepare('INSERT INTO pa_plan_module(plan_id,module_key) VALUES(?,?)');
        $s->execute([$planId, $moduleKey]);
    }
    $app->make(\app\platform\infrastructure\module\ModuleCatalogApplier::class)->apply($registry, ['peanut.identity', 'peanut.file', 'peanut.content-tools', 'peanut.rich-text', 'peanut.document', 'peanut.article', 'peanut.collaboration']);
    $pdo->exec("INSERT INTO pa_plan_permission(plan_id,permission_key) SELECT {$planId},`key` FROM pa_permission WHERE status='active' AND `key` NOT LIKE 'platform.%'");
    $pdo->exec("INSERT INTO pa_role_permission(tenant_id,role_id,permission_id,granted_by_member_id,granted_at) SELECT r.tenant_id,r.id,p.id,{$owner->memberId},UTC_TIMESTAMP(3) FROM pa_role r CROSS JOIN pa_permission p WHERE r.tenant_id={$owner->tenantId} AND r.`key`='core.tenant-owner' AND p.status='active' AND p.`key` NOT LIKE 'platform.%'");

    $tenant = $owner->tenantId;
    $account = $owner->accountId;
    $member = $owner->memberId;
    $sessionKey = '01K00000000000000000000001';
    $s = $pdo->prepare('INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) SELECT ?,m.tenant_id,m.account_id,m.id,"admin-web","active",a.security_revision,t.security_revision,m.security_revision,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant_member m JOIN pa_account a ON a.id=m.account_id JOIN pa_tenant t ON t.id=m.tenant_id WHERE m.id=?');
    $s->execute([$sessionKey,$member]);
    $sessions = $app->make(TenantSessionAccess::class);
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'b1-native');
    $stack = $app->make(ExecutionContextStore::class);
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context, 'b1.document', $app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class)->principal($context)->toArray()), $call);
    $storage = $app->make(StorageService::class);
    $access = $app->make(FileAccess::class);
    $commands = $app->make(DocumentCommands::class);
    $queries = $app->make(DocumentQueries::class);
    $snapshots = $app->make(FileDocumentSnapshots::class);
    $articleAdmin = $app->make(\PeanutAdmin\Modules\Article\Contract\ArticleAdministration::class);
    $category = $run(fn() => \PeanutAdmin\Modules\Article\Model\ArticleCate::create(['tenant_id' => $tenant,'name' => 'B1 Synthetic','create_time' => time(),'update_time' => time()]));
    $makeArticle = fn(string $name) => $run(fn() => \PeanutAdmin\Modules\Article\Model\Article::create(['tenant_id' => $tenant,'cid' => $category->id,'title' => $name,'content' => 'Legacy body unchanged','create_time' => time(),'update_time' => time()]));
    $article = $makeArticle('B1 Rich');
    $foreignOwner = $bootstrap->provisionTenantOwnerCandidate($platform->operatorId, 'b1-foreign', 'B1 Foreign', 'b1-foreign@example.invalid', 'SyntheticB1Foreign2026!', 'Foreign owner', 'b1-foreign-owner');
    $bootstrap->activateTenantOwner($platform->operatorId, $foreignOwner->tenantId, $foreignOwner->memberId, 'b1-foreign-active');
    $bootstrap->activateTenant($platform->operatorId, $foreignOwner->tenantId, 'b1-foreign-tenant');
    $pdo->exec("INSERT INTO pa_article_cate(tenant_id,name)VALUES({$foreignOwner->tenantId},'Foreign category')");
    $foreignCategory = $pdo->lastInsertId();
    $pdo->exec("INSERT INTO pa_article(tenant_id,cid,title)VALUES({$foreignOwner->tenantId},{$foreignCategory},'Foreign article')");
    $foreignArticle = $pdo->lastInsertId();
    documentDenied(fn() => $run(fn() => $commands->create($context, new DocumentOwnerReference('peanut.article', 'article', $foreignArticle, 'body'), 'rich_text', 'b1-foreign-doc')), 'current tenant cannot claim foreign business object', 'ARTICLE_NOT_FOUND');
    $ownerRef = new DocumentOwnerReference('peanut.article', 'article', (string) $article->id, 'body');
    documentDenied(fn() => $run(fn() => $commands->create($context, new DocumentOwnerReference('unknown.owner', 'article', (string) $article->id, 'body'), 'rich_text', 'b1-unknown')), 'unknown owner denied');
    documentDenied(fn() => $run(fn() => $commands->create($context, new DocumentOwnerReference('peanut.article', 'article', (string) $article->id, 'unsafe'), 'rich_text', 'b1-slot')), 'owner rejects unregistered slot');
    $createdDoc = $run(fn() => $commands->create($context, $ownerRef, 'rich_text', 'b1-create'));
    $id = $createdDoc['document_id'];
    documentExpect($createdDoc['state_seq'] === 0 && $createdDoc['created'], 'rich document initially solo projection only');
    $replayed = $run(fn() => $commands->create($context, $ownerRef, 'rich_text', 'b1-create'));
    $existing = $run(fn() => $commands->create($context, $ownerRef, 'rich_text', 'b1-create-again'));
    documentExpect($replayed == $createdDoc && $existing['document_id'] === $id && !$existing['created'] && (int) $pdo->query('SELECT COUNT(*) FROM pa_document')->fetchColumn() === 1, 'owner/slot unique and durable create replay');
    $path = tempnam(sys_get_temp_dir(), 'b1-png-');
    $paths[] = $path;
    $im = imagecreatetruecolor(3, 3);
    imagepng($im, $path);
    imagedestroy($im);
    $source = $run(fn() => $storage->storePath($tenant, $member, 'document.resource', $path, 'b1-source.png', 'text/plain'));
    $projectionA = documentProjection('A', $source['file_key']);
    documentDenied(fn() => $run(fn() => $commands->create($context, $ownerRef, 'file_resource', 'b1-kind', $source['file_key'])), 'occupied slot cannot change kind');
    $saved = $run(fn() => $commands->saveSolo($context, $id, 0, $projectionA, 'b1-save-a'));
    documentExpect($saved['state_seq'] === 1 && $run(fn() => $commands->saveSolo($context, $id, 0, $projectionA, 'b1-save-a')) == $saved, 'solo CAS commits and exact input replays');
    documentDenied(fn() => $run(fn() => $commands->saveSolo($context, $id, 0, documentProjection('changed'), 'b1-save-a')), 'idempotency changed input denied');
    documentDenied(fn() => $run(fn() => $commands->saveSolo($context, $id, 0, documentProjection('stale'), 'b1-stale')), 'stale CAS denied');
    documentExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_document_file_ref WHERE document_id={$id} AND `usage`='current'")->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM pa_file_reference WHERE owner_id='{$id}'")->fetchColumn() === 1, 'Document and File persistent indexes commit together');
    $before = $pdo->query("SELECT state_seq FROM pa_document WHERE id={$id}")->fetchColumn();
    $auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn();
    try {
        $run(fn() => Db::transaction(function () use ($commands, $context, $id) {
            $commands->saveSolo($context, $id, 1, documentProjection('rollback'), 'b1-rollback');
            throw new RuntimeException('synthetic outer rollback');
        }));
    } catch (RuntimeException $error) {
        documentExpect($error->getMessage() === 'synthetic outer rollback', 'outer native transaction original error');
    }
    documentExpect($before === $pdo->query("SELECT state_seq FROM pa_document WHERE id={$id}")->fetchColumn() && $auditBefore === (int) $pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn() && (int) $pdo->query("SELECT COUNT(*) FROM pa_document_operation WHERE operation_id='b1-rollback'")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM pa_file_reference WHERE owner_id='{$id}'")->fetchColumn() === 1, 'outer rollback restores state/reference/audit/operation atomically');
    documentDenied(fn() => $run(fn() => $commands->saveSolo($context, $id, 1, documentProjection('missing', 'file_' . str_repeat('0', 32)), 'b1-missing')), 'missing reference has stable public business rejection', 'DOCUMENT_FILE_REFERENCE_NOT_FOUND');
    documentExpect((int) $pdo->query("SELECT state_seq FROM pa_document WHERE id={$id}")->fetchColumn() === 1 && $auditBefore === (int) $pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn() && (int) $pdo->query("SELECT COUNT(*) FROM pa_document_operation WHERE operation_id='b1-missing'")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM pa_file_reference WHERE owner_id='{$id}'")->fetchColumn() === 1, 'missing file leaves state/audit/operation/references unchanged');
    try {
        $pdo->exec("INSERT INTO pa_document_file_ref(tenant_id,document_id,file_key,`usage`,derived_state_seq,updated_at)VALUES({$tenant},{$id},'file_00000000000000000000000000000000','current',1,UTC_TIMESTAMP(3))");
        throw new RuntimeException('missing File FK accepted');
    } catch (PDOException $error) {
        documentExpect($error->errorInfo[0] === '23000' && (int) $error->errorInfo[1] === 1452 && str_contains($error->getMessage(), 'fk_document_file_ref_object'), 'native FK remains strict on direct invalid target write');
    }
    documentDenied(fn() => $run(fn() => $storage->delete($tenant, $source['file_key'])), 'live reference protects source deletion');
    documentDenied(fn() => RichTextProjection::fromStructured(['schemaVersion' => RichTextProjection::SCHEMA,'editorModel' => 'tiptap-prosemirror','annotations' => [],'content' => ['type' => 'doc','content' => [['type' => 'text','text' => 'illegal direct child']]]]), 'projection hierarchy rejected');
    $unsafe = documentProjection('unsafe');
    $unsafe['content']['content'][] = ['type' => 'media','attrs' => ['src' => 'https://external.invalid/a.png','kind' => 'image']];
    documentDenied(fn() => RichTextProjection::fromStructured($unsafe), 'unstable URL media rejected');
    $markedCode = documentProjection('code');
    $markedCode['content']['content'] = [['type' => 'codeBlock','content' => [['type' => 'text','text' => 'code','marks' => [['type' => 'bold']]]]]];
    documentDenied(fn() => RichTextProjection::fromStructured($markedCode), 'code block text marks rejected without projection loss');
    $versionA = $run(fn() => $commands->freeze($context, $id, 1, 'A immutable', 'b1-freeze-a'));
    $snapshotA = $run(fn() => $queries->versionSnapshot($context, $id, $versionA['revision_id']))->toArray();
    $run(fn() => $commands->saveSolo($context, $id, 1, documentProjection('B'), 'b1-save-b'));
    $snapshotAgain = $run(fn() => $queries->versionSnapshot($context, $id, $versionA['revision_id']))->toArray();
    documentExpect($snapshotA === $snapshotAgain && $snapshotA['structured_content'] == $projectionA && $snapshotA['file_keys'] === [$source['file_key']] && $snapshotA['confirmed'], 'committed A unchanged by draft B and keeps immutable references');
    documentExpect($snapshotA['digest'] === hash('sha256', json_encode(['rich_text',1,RichTextProjection::fromStructured($snapshotA['structured_content'])->json,null,null,null], JSON_THROW_ON_ERROR)) && $snapshotA['digest'] === $versionA['digest'], 'committed version tuple digest agrees with canonical persisted projection/source seq and source result');
    documentDenied(fn() => $run(fn() => Db::transaction(fn() => $queries->versionSnapshot($context, $id, $versionA['revision_id']))), 'snapshot refuses uncommitted outer transaction');
    foreach (['save','delete'] as $method) {
        try {
            $run(function () use ($versionA, $method) {
                $row = DocumentRevision::find($versionA['revision_id']);
                $method === 'save' ? $row->save(['reason' => 'mutated']) : $row->delete();
            });
            throw new RuntimeException('immutable accepted');
        } catch (LogicException $error) {
            documentExpect(str_contains($error->getMessage(), 'IMMUTABLE'), 'native revision model refuses ' . $method);
        }
    }
    $restored = $run(fn() => $commands->restoreRevision($context, $id, $versionA['revision_id'], 2, 'b1-restore-a'));
    documentExpect($restored['revision_id'] !== $versionA['revision_id'] && $restored['source_state_seq'] === 3 && $run(fn() => $queries->versionSnapshot($context, $id, $versionA['revision_id']))->toArray() === $snapshotA, 'restore creates another immutable revision');
    $resourceArticle = $makeArticle('B1 File');
    $resourceOwner = new DocumentOwnerReference('peanut.article', 'article', (string) $resourceArticle->id, 'body');
    $resourceDoc = $run(fn() => $commands->create($context, $resourceOwner, 'file_resource', 'b1-file-create', $source['file_key']));
    $resourceId = $resourceDoc['document_id'];
    $description = $run(fn() => $snapshots->describeForDocument($context, $source['file_key']));
    documentDenied(fn() => $run(fn() => Db::transaction(fn() => $snapshots->captureForDocument($context, $source['file_key'], $description['sha256'], $description['revision'], 'b1-outer-capture'))), 'physical capture refuses existing transaction');
    documentDenied(fn() => $run(fn() => $snapshots->captureForDocument($context, $source['file_key'], $description['sha256'], $description['revision'] + 1, 'b1-stale-capture')), 'capture binds current source revision');
    $fileVersion = $run(fn() => $commands->freeze($context, $resourceId, 1, 'File A', 'b1-file-freeze'));
    $fileSnapshot = $run(fn() => $queries->versionSnapshot($context, $resourceId, $fileVersion['revision_id']))->toArray();
    $immutableKey = $fileSnapshot['file_key'];
    $immutable = $pdo->query("SELECT * FROM pa_file_object WHERE file_key='{$immutableKey}'")->fetch(PDO::FETCH_ASSOC);
    $immutablePath = $serverRoot . '/private/storage/' . $immutable['object_key'];
    documentExpect($immutableKey !== $source['file_key'] && is_file($immutablePath) && hash_file('sha256', $immutablePath) === $description['sha256'] && $immutable['access_type'] === 'private' && $fileSnapshot['verified_mime_type'] === 'image/png' && $pdo->query("SELECT status FROM pa_file_immutable_snapshot WHERE file_key='{$immutableKey}'")->fetchColumn() === 'claimed', 'file version owns real private immutable bytes/MIME/SHA and claimed ledger');
    $pathB = tempnam(sys_get_temp_dir(), 'b1-b-');
    $paths[] = $pathB;
    file_put_contents($pathB, 'Second resource bytes');
    $sourceB = $run(fn() => $storage->storePath($tenant, $member, 'document.resource', $pathB, 'b1-b.txt', 'image/png'));
    $run(fn() => $commands->bindResource($context, $resourceId, $sourceB['file_key'], 1, 'b1-bind-b'));
    documentExpect($run(fn() => $queries->versionSnapshot($context, $resourceId, $fileVersion['revision_id']))->toArray() === $fileSnapshot && hash_file('sha256', $immutablePath) === $description['sha256'], 'draft file B cannot overwrite historic A bytes');
    $fileRestore = $run(fn() => $commands->restoreRevision($context, $resourceId, $fileVersion['revision_id'], 2, 'b1-file-restore'));
    documentExpect($fileRestore['revision_id'] !== $fileVersion['revision_id'] && $run(fn() => $queries->versionSnapshot($context, $resourceId, $fileRestore['revision_id']))->fileKey === $immutableKey, 'file restore appends version using protected immutable bytes');
    documentDenied(fn() => $run(fn() => $storage->delete($tenant, $immutableKey)), 'history reference protects snapshot delete');
    documentDenied(fn() => $run(fn() => $snapshots->recoverDocumentSnapshot($context, $immutableKey)), 'claimed history cannot be recovered');
    $opened = $run(fn() => $storage->openForTenant($tenant, $immutableKey));
    documentExpect(hash_file('sha256', $opened['path']) === $description['sha256'], 'actual authorized historic download bytes');
    $capture = $run(fn() => $snapshots->captureForDocument($context, $source['file_key'], $description['sha256'], $description['revision'], 'b1-abandon'));
    $captureObject = $pdo->query("SELECT object_key FROM pa_file_object WHERE file_key='{$capture['file_key']}'")->fetch(PDO::FETCH_ASSOC);
    $capturePath = $serverRoot . '/private/storage/' . $captureObject['object_key'];
    documentExpect(is_file($capturePath), 'unclaimed capture exists physically');
    $run(fn() => $snapshots->abandonDocumentSnapshot($context, $capture));
    documentExpect(!is_file($capturePath) && $pdo->query("SELECT status FROM pa_file_immutable_snapshot WHERE file_key='{$capture['file_key']}'")->fetchColumn() === 'abandoned', 'same-request compensation deletes actual bytes using complete original config');
    $recovery = $run(fn() => $snapshots->captureForDocument($context, $source['file_key'], $description['sha256'], $description['revision'], 'b1-recovery'));
    $recoveryObject = $pdo->query("SELECT object_key,storage_space_id FROM pa_file_object WHERE file_key='{$recovery['file_key']}'")->fetch(PDO::FETCH_ASSOC);
    $recoveryPath = $serverRoot . '/private/storage/' . $recoveryObject['object_key'];
    $pdo->exec("UPDATE pa_file_object SET created_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 HOUR) WHERE file_key='{$recovery['file_key']}'");
    documentExpect(in_array($recovery['file_key'], array_column($run(fn() => $snapshots->pendingDocumentSnapshots($context)), 'file_key'), true), 'durable unclaimed capture discoverable after interruption');
    // Native Local IO failure: a nonempty directory at the exact registered synthetic object path cannot be unlinked.
    rename($recoveryPath, $recoveryPath . '.held');
    mkdir($recoveryPath);
    file_put_contents($recoveryPath . '/guard', 'synthetic');
    try {
        $pending = $run(fn() => $snapshots->recoverDocumentSnapshot($context, $recovery['file_key']));
        documentExpect($pending['cleanup_pending'] && !$pending['provider_delete_acknowledged'] && $pdo->query("SELECT status FROM pa_file_immutable_snapshot WHERE file_key='{$recovery['file_key']}'")->fetchColumn() === 'cleanup_pending', 'real Local failed deletion remains pending');
    } finally {
        unlink($recoveryPath . '/guard');
        rmdir($recoveryPath);
        rename($recoveryPath . '.held', $recoveryPath);
    }
    $recovered = $run(fn() => $snapshots->recoverDocumentSnapshot($context, $recovery['file_key']));
    documentExpect(!$recovered['cleanup_pending'] && $recovered['provider_delete_acknowledged'] && !is_file($recoveryPath), 'archived cleanup retries with full config and deletes actual bytes');
    $sensitive = $run(fn() => $storage->storePath($tenant, $member, 'attachment.sensitive', $path, 'sensitive.png', 'image/png'));
    $sensitiveDescription = $run(fn() => $snapshots->describeForDocument($context, $sensitive['file_key']));
    $sensitiveCapture = $run(fn() => $snapshots->captureForDocument($context, $sensitive['file_key'], $sensitiveDescription['sha256'], $sensitiveDescription['revision'], 'b1-sensitive'));
    documentExpect($pdo->query("SELECT purpose FROM pa_file_object WHERE file_key='{$sensitiveCapture['file_key']}'")->fetchColumn() === 'document.snapshot-sensitive', 'immutable copy retains File-owned sensitive classification');
    $sensitiveArticle = $makeArticle('B1 Sensitive');
    $sensitiveDoc = $run(fn() => $commands->create($context, new DocumentOwnerReference('peanut.article', 'article', (string) $sensitiveArticle->id, 'body'), 'file_resource', 'b1-sensitive-doc', $sensitive['file_key']));
    $sensitiveVersion = $run(fn() => $commands->freeze($context, $sensitiveDoc['document_id'], 1, 'Sensitive immutable', 'b1-sensitive-freeze'));
    $sensitiveHistory = $run(fn() => $queries->versionSnapshot($context, $sensitiveDoc['document_id'], $sensitiveVersion['revision_id']))->fileKey;
    $pdo->exec("UPDATE pa_role SET is_builtin=0 WHERE tenant_id={$tenant} AND `key`='core.tenant-owner'");
    $pdo->exec("INSERT IGNORE INTO pa_role_permission(tenant_id,role_id,permission_id,granted_by_member_id,granted_at) SELECT {$tenant},r.id,p.id,{$member},UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.`key`='peanut.article.detail' WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'b1-doc-only');
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context, 'b1.document', $app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class)->principal($context)->toArray()), $call);
    documentExpect($run(fn() => $queries->detail($context, $sensitiveDoc['document_id']))['document_id'] === $sensitiveDoc['document_id'], 'owner viewer can read sensitive document metadata');
    foreach ([$sensitive['file_key'],$sensitiveHistory] as $key) {
        documentDenied(fn() => $run(fn() => $access->resolve($context, $key, 'preview', $sensitiveDoc['document_id'], null, 'builtin.image')), 'sensitive source/history cannot inherit Document-only preview');
    }
    $pdo->exec("UPDATE pa_role SET is_builtin=1 WHERE tenant_id={$tenant} AND `key`='core.tenant-owner'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'b1-owner-restored');
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context, 'b1.document', $app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class)->principal($context)->toArray()), $call);
    documentExpect(is_file($run(fn() => $storage->openForTenant($tenant, $sensitiveHistory))['path']), 'sensitive historic original still available with independent File download permission');
    $pdo->exec("UPDATE pa_document SET collaboration_enabled=1 WHERE id={$id}");
    documentDenied(fn() => $run(fn() => $commands->saveSolo($context, $id, 3, documentProjection('No fake collab'), 'b1-collab-save')), 'collaboration solo save fails closed', 'DOCUMENT_COLLABORATION_BARRIER_REQUIRED');
    documentDenied(fn() => $run(fn() => $commands->freeze($context, $id, 3, 'No fake barrier', 'b1-collab-freeze')), 'collaboration freeze requires confirmed click target', 'DOCUMENT_TARGET_NOT_CONFIRMED');
    documentDenied(fn() => $run(fn() => $articleAdmin->delete($context, (int) $article->id)), 'collaboration archive requires real native room barrier', 'COLLAB_UNAVAILABLE');
    documentExpect($pdo->query("SELECT delete_time FROM pa_article WHERE id={$article->id}")->fetchColumn() === null && $pdo->query("SELECT status FROM pa_document WHERE id={$id}")->fetchColumn() === 'active', 'owner soft delete rolls back with missing barrier');
    $pdo->exec("UPDATE pa_document SET collaboration_enabled=0 WHERE id={$id}");
    $run(fn() => $articleAdmin->delete($context, (int) $article->id));
    documentExpect($pdo->query("SELECT status FROM pa_document WHERE id={$id}")->fetchColumn() === 'archived', 'owner soft delete archives Document atomically');
    $result = $run(fn() => $articleAdmin->forceDelete($context, [(int) $article->id]));
    documentExpect($result['failed'] !== [] && (int) $pdo->query("SELECT COUNT(*) FROM pa_article WHERE id={$article->id}")->fetchColumn() === 1, 'owner purge cannot orphan protected versions');
    $run(fn() => $articleAdmin->restore($context, [(int) $article->id]));
    documentExpect($pdo->query("SELECT status FROM pa_document WHERE id={$id}")->fetchColumn() === 'active', 'owner restoration restores Document current authorization');
    $pdo->exec("DELETE FROM pa_plan_module WHERE plan_id=(SELECT plan_id FROM pa_tenant_license WHERE tenant_id={$tenant}) AND module_key='peanut.article'");
    documentDenied(fn() => $run(fn() => $queries->detail($context, $id)), 'disabled owner module denied');
    $pdo->exec("INSERT INTO pa_plan_module(plan_id,module_key) SELECT plan_id,'peanut.article' FROM pa_tenant_license WHERE tenant_id={$tenant}");
    $pdo->exec("UPDATE pa_tenant_session SET status='revoked',revoked_at=UTC_TIMESTAMP(3) WHERE session_key='{$sessionKey}'");
    documentDenied(fn() => $run(fn() => $queries->detail($context, $id)), 'revoked trusted durable session denied');
    echo 'DOCUMENT_PERSISTENCE_OK assertions=' . $assertions . ' real_mysql=8.4 local_bytes=real collaboration=deferred editions=engine-only' . PHP_EOL;
} finally {
    try {
        if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pa_file_object'")->fetchColumn() === 1) {
            foreach ($pdo->query('SELECT object_key,access_type FROM pa_file_object')->fetchAll(PDO::FETCH_ASSOC) as $object) {
                $file = $serverRoot . '/' . ($object['access_type'] === 'private' ? 'private' : 'public') . '/storage/' . $object['object_key'];
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    } finally {
        RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
    }
}
