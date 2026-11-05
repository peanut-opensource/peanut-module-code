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
            documentExpect($error instanceof \app\common\exception\BusinessException && $error->errorCode === $errorCode, $message . ': wrong rejection reason');
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
        ['settings', 'reference_codes', 'file', 'article', 'document', 'task'],
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
    $app = new think\App($serverRoot);
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $registry = $app->make(CompiledModuleRegistry::class);
    $pdo->exec("INSERT INTO pa_plan(code,name,status,revision,created_at,updated_at) VALUES('document-fixture','Document fixture','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $planId = (int) $pdo->lastInsertId();
    $license = $pdo->prepare("INSERT INTO pa_tenant_license(tenant_id,plan_id,status,effective_at,revision,created_at,updated_at) VALUES(?,?,'active',UTC_TIMESTAMP(3),1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $license->execute([$owner->tenantId, $planId]);
    foreach (['peanut.identity', 'peanut.file', 'peanut.content-tools', 'peanut.rich-text', 'peanut.document', 'peanut.article', 'peanut.task'] as $moduleKey) {
        $manifest = $registry->requireManifest($moduleKey);
        $s = $pdo->prepare('INSERT INTO pa_module_installation(module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,installed_at,activated_at,created_at,updated_at) VALUES(?,?,?,?,"active",1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))');
        $s->execute([$moduleKey, $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
        $s = $pdo->prepare('INSERT INTO pa_plan_module(plan_id,module_key) VALUES(?,?)');
        $s->execute([$planId, $moduleKey]);
    }
    $app->make(\app\platform\infrastructure\module\ModuleCatalogApplier::class)->apply($registry, ['peanut.identity', 'peanut.file', 'peanut.content-tools', 'peanut.rich-text', 'peanut.document', 'peanut.article', 'peanut.task']);
    $pdo->exec("INSERT INTO pa_plan_permission(plan_id,permission_key) SELECT {$planId},`key` FROM pa_permission WHERE status='active' AND `key` NOT LIKE 'platform.%'");

    $tenant = $owner->tenantId;
    $account = $owner->accountId;
    $member = $owner->memberId;
    $sessionKey = '01K00000000000000000000001';
    $s = $pdo->prepare('INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) SELECT ?,m.tenant_id,m.account_id,m.id,"admin-web","active",a.security_revision,t.security_revision,m.security_revision,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant_member m JOIN pa_account a ON a.id=m.account_id JOIN pa_tenant t ON t.id=m.tenant_id WHERE m.id=?');
    $s->execute([$sessionKey,$member]);
    $sessions = $app->make(TenantSessionAccess::class);
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'b1-native');
    $stack = $app->make(ExecutionContextStore::class);
    $run = function (callable $call) use ($stack, $app, &$context) {
        return $stack->run(new AdminExecutionContext($context, 'prepared.document', $app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class)->principal($context)->toArray()), $call);
    };
    $storage = $app->make(StorageService::class);
    $access = $app->make(FileAccess::class);
    $commands = $app->make(DocumentCommands::class);
    $queries = $app->make(DocumentQueries::class);
    $snapshots = $app->make(FileDocumentSnapshots::class);
    $articleAdmin = $app->make(\PeanutAdmin\Modules\Article\Contract\ArticleAdministration::class);
    $category = $run(fn() => \PeanutAdmin\Modules\Article\Model\ArticleCate::create(['tenant_id' => $tenant,'name' => 'B1 Synthetic','create_time' => time(),'update_time' => time()]));
    $makeArticle = fn(string $name) => $run(fn() => \PeanutAdmin\Modules\Article\Model\Article::create(['tenant_id' => $tenant,'cid' => $category->id,'title' => $name,'content' => 'Legacy body unchanged','create_time' => time(),'update_time' => time()]));

    documentExpect($commands === $queries, 'native Commands/Queries aliases share one concrete DocumentService');
    $article = $makeArticle('Prepared Rich');
    $ownerRef = new DocumentOwnerReference('peanut.article', 'article', (string) $article->id, 'body');
    $doc = $run(fn() => $commands->create($context, $ownerRef, 'rich_text', 'prepared-create'));
    $id = $doc['document_id'];
    $path = tempnam(sys_get_temp_dir(), 'prepared-');
    $paths[] = $path;
    file_put_contents($path, 'Actual immutable fixture bytes');
    $file = $run(fn() => $storage->storePath($tenant, $member, 'document.resource', $path, 'prepared.txt', 'text/plain'));
    $metadataBefore = [$pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM pa_file_delivery_grant')->fetchColumn()];
    $metadata = $run(fn() => $access->attachmentSnapshot($context, $file['file_key']));
    documentExpect($metadata === ['file_key' => $file['file_key'], 'original_name' => 'prepared.txt', 'verified_mime_type' => 'text/plain',
        'size_bytes' => strlen('Actual immutable fixture bytes'), 'sha256' => hash_file('sha256', $path)], 'attachment snapshot returns only actual source metadata');
    documentExpect($metadataBefore === [$pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM pa_file_delivery_grant')->fetchColumn()], 'attachment metadata creates no audit or delivery grant');
    foreach (["UPDATE pa_tenant_session SET status='revoked' WHERE tenant_member_id={$member}",
        "DELETE FROM pa_plan_module WHERE plan_id=(SELECT plan_id FROM pa_tenant_license WHERE tenant_id={$tenant}) AND module_key='peanut.file'",
        "UPDATE pa_file_object SET media_type='invalid' WHERE file_key='{$file['file_key']}'"] as $sql) {
        documentDenied(fn() => $run(fn() => Db::transaction(function () use ($pdo, $sql, $access, $context, $file): void {
            $pdo->exec($sql);
            $access->attachmentSnapshot($context, $file['file_key']);
        })), 'attachment snapshot rejects current revocation or malformed persistent metadata');
    }
    try {
        Db::transaction(fn() => $pdo->exec("UPDATE pa_file_object SET sha256='invalid' WHERE file_key='{$file['file_key']}'"));
        throw new RuntimeException('persistent File SHA check accepted malformed hash');
    } catch (PDOException $error) {
        documentExpect(($error->errorInfo[1] ?? null) === 3819 && str_contains($error->getMessage(), 'chk_file_object_sha256') && !$pdo->inTransaction(), 'native SHA constraint rejects malformed persistent hash and rolls back');
    }
    $system = new \app\common\execution\SystemExecutionContext(new \PeanutAdmin\Kernel\Context\TenantSystemContext($tenant, 'task-worker', 'prepared.metadata', 'prepared-metadata-job'));
    try {
        $stack->run($system, fn() => $access->attachmentSnapshot($context, $file['file_key']));
        throw new RuntimeException('System worker obtained human File metadata');
    } catch (DomainException $error) {
        documentExpect($error->getMessage() === 'EXECUTION_TENANT_ADMIN_CONTEXT_REQUIRED', 'System cannot obtain Admin attachment metadata');
    }
    $imagePath = tempnam(sys_get_temp_dir(), 'prepared-image-');
    $variantPath = tempnam(sys_get_temp_dir(), 'prepared-variant-');
    $paths[] = $imagePath;
    $paths[] = $variantPath;
    $image = imagecreatetruecolor(16, 16);
    imagejpeg($image, $imagePath);
    $small = imagescale($image, 4, 4);
    imagepng($small, $variantPath);
    imagedestroy($small);
    imagedestroy($image);
    $imageFile = $run(fn() => $storage->storePath($tenant, $member, 'document.resource', $imagePath, 'original.jpg', 'image/jpeg'));
    $imageVariant = $run(fn() => $storage->storePath($tenant, $member, 'material.image-variant', $variantPath, 'small.png', 'image/png'));
    $run(fn() => \PeanutAdmin\Modules\File\Model\Storage\FileImageAsset::create(['tenant_id' => $tenant, 'file_key' => $imageFile['file_key'], 'width' => 16, 'height' => 16, 'media_type' => 'image/jpeg', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]));
    $run(fn() => $app->make(\PeanutAdmin\Modules\File\Contract\FileDerivatives::class)->bind($context, $imageFile['file_key'], 'small', $imageVariant['file_key'], 4, 4, 'image/png'));
    $originalDelivery = $run(fn() => $access->resolve($context, $imageFile['file_key'], 'download_original', null, 'small', 'builtin.image'));
    documentExpect($originalDelivery['variant_key'] === null && $originalDelivery['bytes'] === 'original', 'original receipt never echoes requested preview variant');
    $exactDelivery = $run(fn() => $access->resolve($context, $imageFile['file_key'], 'preview', null, 'small', 'builtin.image'));
    documentExpect($exactDelivery['variant_key'] === 'small' && $exactDelivery['bytes'] === 'derivative' && $exactDelivery['delivery_file_key'] === $imageVariant['file_key']
        && $exactDelivery['media_type'] === 'image/png' && $exactDelivery['capabilities']['verifiedMimeType'] === 'image/png', 'selected variant binds actual derivative MIME even when source is JPEG');
    $missingDelivery = $run(fn() => $access->resolve($context, $imageFile['file_key'], 'preview', null, 'absent', 'builtin.image'));
    documentExpect($missingDelivery['state'] === 'preview_unavailable' && $missingDelivery['url'] === null, 'missing exact variant is unavailable without fallback');
    $saved = $run(fn() => $commands->saveSolo($context, $id, 0, documentProjection('Committed A', $file['file_key']), 'prepared-save-a'));
    $version = $run(fn() => $commands->freeze($context, $id, $saved['state_seq'], 'Version A', 'prepared-freeze-a'));
    $verify = fn($snapshot, $expected = null) => $queries->verifyPreparedVersion($context, $snapshot, $expected ?? $ownerRef);
    $outside = $queries->versionSnapshot($context, $id, $version['revision_id']);
    documentDenied(fn() => $run(fn() => Db::transaction(fn() => $verify($outside))), 'ordinary no-root read does not mint proof', 'DOCUMENT_PREPARED_VERSION_DENIED');
    $oldRootObject = $run(fn() => $queries->versionSnapshot($context, $id, $version['revision_id']));
    documentDenied(fn() => $run(fn() => Db::transaction(fn() => $verify($oldRootObject))), 'same requestId in different root is rejected', 'DOCUMENT_PREPARED_VERSION_DENIED');
    $run(function () use ($queries, $commands, $context, $stack, $id, $version, $ownerRef, $verify, $pdo, $article, $file): void {
        $root = $stack->rootIdentity();
        $original = $queries->versionSnapshot($context, $id, $version['revision_id']);
        $before = $pdo->query('SELECT COUNT(*) FROM pa_file_reference')->fetchColumn();
        $auditBefore = $pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn();
        $grantsBefore = $pdo->query('SELECT COUNT(*) FROM pa_file_delivery_grant')->fetchColumn();
        for ($i = 0; $i < 2; $i++) {
            Db::transaction(function () use ($verify, $original, $stack, $root, $pdo): void {
                $verify($original);
                documentExpect($pdo->inTransaction() && $stack->rootIdentity() === $root, 'original native connection and outer root survive verification');
            });
        }
        documentExpect($before === $pdo->query('SELECT COUNT(*) FROM pa_file_reference')->fetchColumn()
            && $auditBefore === $pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn()
            && $grantsBefore === $pdo->query('SELECT COUNT(*) FROM pa_file_delivery_grant')->fetchColumn(), 'readonly history verification writes no index/audit/grant');
        documentDenied(fn() => $verify($original), 'verify requires caller transaction', 'DOCUMENT_PREPARED_TRANSACTION_REQUIRED');
        documentDenied(fn() => Db::transaction(fn() => $queries->versionSnapshot($context, $id, $version['revision_id'])), 'prepare in caller TX remains rejected', 'DOCUMENT_VERSION_NOT_COMMITTED');
        $values = [$original->tenantId, $original->documentId, $original->revisionId, $original->revisionNo, $original->sourceStateSeq,
            $original->contentKind, $original->digest, $original->structuredContent, $original->fileKey, $original->fileSha256, $original->verifiedMimeType, $original->references];
        foreach ([new \PeanutAdmin\Modules\Document\Contract\VersionSnapshot(...$values), clone $original, unserialize(serialize($original))] as $forged) {
            documentDenied(fn() => Db::transaction(fn() => $verify($forged)), 'new/clone/unserialized DTO is not prepared proof', 'DOCUMENT_PREPARED_VERSION_DENIED');
        }
        foreach ([new DocumentOwnerReference('peanut.file', 'article', $ownerRef->objectId, 'body'),
            new DocumentOwnerReference('peanut.article', 'other', $ownerRef->objectId, 'body'),
            new DocumentOwnerReference('peanut.article', 'article', '999999', 'body'),
            new DocumentOwnerReference('peanut.article', 'article', $ownerRef->objectId, 'other')] as $wrong) {
            documentDenied(fn() => Db::transaction(fn() => $verify($original, $wrong)), 'all four expected owner fields are bound', 'DOCUMENT_PREPARED_VERSION_DENIED');
        }
        foreach (['revision_no' => ['revision_no+20','DOCUMENT_PREPARED_VERSION_CHANGED'], 'source_state_seq' => ['source_state_seq+1','DOCUMENT_VERSION_DIGEST_INVALID'],
            'snapshot_sha256' => ["REPEAT('f',64)",'DOCUMENT_VERSION_DIGEST_INVALID']] as $field => [$sql, $code]) {
            documentDenied(fn() => Db::transaction(function () use ($pdo, $version, $field, $sql, $verify, $original, $article): void {
                $pdo->exec("UPDATE pa_article SET title='Must roll back' WHERE id={$article->id}");
                $pdo->exec("UPDATE pa_document_revision SET {$field}={$sql} WHERE id={$version['revision_id']}");
                $verify($original);
            }), 'persisted immutable field mutation rejected', $code);
            documentExpect($pdo->query("SELECT title FROM pa_article WHERE id={$article->id}")->fetchColumn() === 'Prepared Rich', 'caller native TX business write rolls back on verification denial');
        }
        $newer = $commands->saveSolo($context, $id, 1, documentProjection('Draft B'), 'prepared-draft-b');
        Db::transaction(fn() => $verify($original));
        documentExpect($original->structuredContent['content']['content'][0]['content'][0]['text'] === 'Committed A'
            && $original->references[0]['file_key'] === $file['file_key'] && $newer['state_seq'] === 2, 'immutable A and its refs unaffected by draft B');
        $newVersion = $commands->freeze($context, $id, 2, 'Version B', 'prepared-freeze-b');
        documentDenied(fn() => Db::transaction(fn() => $queries->versionSnapshot($context, $id, $newVersion['revision_id'])), 'same-TX prepare cannot mint committed proof', 'DOCUMENT_VERSION_NOT_COMMITTED');
        $map = (new ReflectionProperty($queries, 'preparedVersions'))->getValue($queries);
        $proof = $map[$original];
        $proof['expires_at'] = 0;
        $map[$original] = $proof;
        documentDenied(fn() => Db::transaction(fn() => $verify($original)), 'monotonic proof expiry is enforced', 'DOCUMENT_PREPARED_VERSION_DENIED');
    });
    documentExpect($stack->rootIdentity() === null && $stack->isEmpty(), 'root finally releases even after rejected proofs');
    $run(function () use ($queries, $context, $id, $version, $verify, $pdo, $tenant, $member): void {
        $original = $queries->versionSnapshot($context, $id, $version['revision_id']);
        foreach (['session' => "UPDATE pa_tenant_session SET status='revoked' WHERE tenant_member_id={$member}",
            'authRevision' => "UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}",
            'module' => "DELETE FROM pa_plan_module WHERE plan_id=(SELECT plan_id FROM pa_tenant_license WHERE tenant_id={$tenant}) AND module_key='peanut.document'",
            'owner' => "UPDATE pa_article SET delete_time=UNIX_TIMESTAMP() WHERE id=(SELECT owner_object_id FROM pa_document WHERE id={$id})"] as $label => $sql) {
            try {
                Db::transaction(function () use ($pdo, $sql, $verify, $original): void {
                    $pdo->exec($sql);
                    $verify($original);
                });
                throw new RuntimeException('revoked ' . $label . ' was accepted');
            } catch (\app\common\exception\BusinessException | \PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException | \PeanutAdmin\Kernel\Module\ModuleException $error) {
                documentExpect(!$pdo->inTransaction(), 'live ' . $label . ' revocation denies and rolls native TX back');
            }
        }
    });
    foreach ([new \app\common\execution\InstanceExecutionContext('prepared.instance', 'prepared-instance'),
        new \app\common\execution\SystemExecutionContext(new \PeanutAdmin\Kernel\Context\TenantSystemContext($tenant, 'task-worker', 'prepared.system', 'prepared-job'))] as $ambient) {
        documentDenied(fn() => $stack->run($ambient, fn() => $queries->versionSnapshot($context, $id, $version['revision_id'])), 'Instance/System cannot become Admin Doc proof', 'DOCUMENT_CONTEXT_MISMATCH');
    }
    $resourceArticle = $makeArticle('Prepared immutable File');
    $fileOwner = new DocumentOwnerReference('peanut.article', 'article', (string) $resourceArticle->id, 'body');
    $resource = $run(fn() => $commands->create($context, $fileOwner, 'file_resource', 'prepared-file-create', $file['file_key']));
    $fileVersion = $run(fn() => $commands->freeze($context, $resource['document_id'], 1, 'File A', 'prepared-file-freeze'));
    $run(function () use ($queries, $context, $fileOwner, $resource, $fileVersion, $pdo): void {
        $original = $queries->versionSnapshot($context, $resource['document_id'], $fileVersion['revision_id']);
        Db::transaction(fn() => $queries->verifyPreparedVersion($context, $original, $fileOwner));
        $s = $pdo->prepare("SELECT object_key FROM pa_file_object WHERE file_key=?");
        $s->execute([$original->fileKey]);
        documentExpect(hash_file('sha256', dirname(__DIR__, 4) . '/private/storage/' . $s->fetchColumn()) === $original->fileSha256, 'captured fixture bytes match immutable revision SHA');
        documentDenied(fn() => Db::transaction(function () use ($pdo, $original, $queries, $context, $fileOwner): void {
            $s = $pdo->prepare("UPDATE pa_file_object SET media_type='image/png' WHERE file_key=?");
            $s->execute([$original->fileKey]);
            $queries->verifyPreparedVersion($context, $original, $fileOwner);
        }), 'File-owned MIME mismatch rejects', 'FILE_DOCUMENT_SNAPSHOT_CHANGED');
        documentDenied(fn() => Db::transaction(function () use ($pdo, $original, $queries, $context, $fileOwner): void {
            $s = $pdo->prepare('UPDATE pa_file_immutable_snapshot SET claimed_document_id=999999 WHERE file_key=?');
            $s->execute([$original->fileKey]);
            $queries->verifyPreparedVersion($context, $original, $fileOwner);
        }), 'claimed capture owner mismatch rejects', 'FILE_DOCUMENT_SNAPSHOT_CHANGED');
    });
    $sensitive = $run(fn() => $storage->storePath($tenant, $member, 'attachment.sensitive', $path, 'sensitive.txt', 'text/plain'));
    $sensitiveArticle = $makeArticle('Prepared sensitive');
    $sensitiveOwner = new DocumentOwnerReference('peanut.article', 'article', (string) $sensitiveArticle->id, 'body');
    $sensitiveDoc = $run(fn() => $commands->create($context, $sensitiveOwner, 'file_resource', 'prepared-sensitive-create', $sensitive['file_key']));
    $sensitiveVersion = $run(fn() => $commands->freeze($context, $sensitiveDoc['document_id'], 1, 'Sensitive A', 'prepared-sensitive-freeze'));
    $pdo->exec("UPDATE pa_role SET is_builtin=0 WHERE tenant_id={$tenant} AND `key`='core.tenant-owner'");
    $pdo->exec("DELETE rp FROM pa_role_permission rp JOIN pa_role r ON r.id=rp.role_id AND r.tenant_id=rp.tenant_id WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("INSERT INTO pa_role_permission(tenant_id,role_id,permission_id,granted_by_member_id,granted_at) SELECT {$tenant},r.id,p.id,{$member},UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.`key`='peanut.article.detail' WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'prepared-restricted');
    documentDenied(fn() => $run(fn() => $access->attachmentSnapshot($context, $file['file_key'])), 'Document view alone cannot grant independent attachment metadata permission');
    $run(function () use ($queries, $context, $sensitiveDoc, $sensitiveVersion, $sensitiveOwner): void {
        $original = $queries->versionSnapshot($context, $sensitiveDoc['document_id'], $sensitiveVersion['revision_id']);
        documentDenied(fn() => Db::transaction(fn() => $queries->verifyPreparedVersion($context, $original, $sensitiveOwner)), 'sensitive history cannot inherit Doc view', 'FILE_ACCESS_DENIED');
    });
    $pdo->exec("INSERT INTO pa_role_permission(tenant_id,role_id,permission_id,granted_by_member_id,granted_at) SELECT {$tenant},r.id,p.id,{$member},UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.`key`='peanut.file.list' WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'prepared-independent');
    documentExpect($run(fn() => $access->attachmentSnapshot($context, $sensitive['file_key']))['sha256'] === hash_file('sha256', $path), 'current independent File preview permits sensitive source metadata');
    $run(function () use ($queries, $context, $sensitiveDoc, $sensitiveVersion, $sensitiveOwner): void {
        $original = $queries->versionSnapshot($context, $sensitiveDoc['document_id'], $sensitiveVersion['revision_id']);
        Db::transaction(fn() => $queries->verifyPreparedVersion($context, $original, $sensitiveOwner));
        documentExpect(true, 'independent File preview grants sensitive immutable history read');
    });
    echo 'DOCUMENT_PREPARED_VERSION_OK assertions=' . $assertions . ' real_mysql=8.4 editions=engine-only' . PHP_EOL;
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
