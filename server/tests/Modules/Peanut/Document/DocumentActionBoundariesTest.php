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
    if (!$condition) { throw new RuntimeException($message); }
    $assertions++;
}
function documentDenied(callable $operation, string $message, ?string $errorCode = null): void
{
    try { $operation(); } catch (Throwable $error) {
        documentExpect($error instanceof \app\common\exception\BusinessException
            || $error instanceof \PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException
            || $error instanceof \PeanutAdmin\Kernel\Module\ModuleException, $message . ': unexpected ' . $error::class . ': ' . $error->getMessage());
        if ($errorCode !== null) documentExpect($error instanceof \app\common\exception\BusinessException && $error->errorCode === $errorCode, $message . ': wrong rejection reason');
        return;
    }
    throw new RuntimeException($message . ': accepted');
}
function documentProjection(string $text, ?string $fileKey = null): array
{
    $nodes = [['type'=>'paragraph','content'=>[['type'=>'text','text'=>$text]]]];
    if ($fileKey !== null) $nodes[] = ['type'=>'media','attrs'=>['fileKey'=>$fileKey,'kind'=>'image']];
    return ['schemaVersion'=>RichTextProjection::SCHEMA,'editorModel'=>'tiptap-prosemirror','content'=>['type'=>'doc','content'=>$nodes],'annotations'=>[]];
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
        new \PeanutAdmin\Modules\Identity\Audit\AuditService(), new \PeanutAdmin\Kernel\Identity\PasswordHasher(), new \PeanutAdmin\Kernel\Identity\PasswordPolicy(),
    );
    $platform = $bootstrap->bootstrapPlatformOwner('b1-owner@example.invalid', 'SyntheticB1Acceptance2026!', 'B1 Owner', 'b1-bootstrap');
    $owner = $bootstrap->provisionTenantOwnerCandidate($platform->operatorId, 'default', 'B1 Synthetic', 'b1-owner@example.invalid', null, 'B1 Owner', 'b1-owner');
    $bootstrap->activateTenantOwner($platform->operatorId, $owner->tenantId, $owner->memberId, 'b1-owner-active');
    $bootstrap->activateTenant($platform->operatorId, $owner->tenantId, 'b1-tenant-active');
    $app = new think\App($serverRoot); $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $registry = $app->make(CompiledModuleRegistry::class);
    $pdo->exec("INSERT INTO pa_plan(code,name,status,revision,created_at,updated_at) VALUES('document-fixture','Document fixture','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $planId = (int) $pdo->lastInsertId();
    $license = $pdo->prepare("INSERT INTO pa_tenant_license(tenant_id,plan_id,status,effective_at,revision,created_at,updated_at) VALUES(?,?,'active',UTC_TIMESTAMP(3),1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $license->execute([$owner->tenantId, $planId]);
    foreach (['peanut.identity', 'peanut.file', 'peanut.content-tools', 'peanut.rich-text', 'peanut.document', 'peanut.article'] as $moduleKey) {
        $manifest = $registry->requireManifest($moduleKey);
        $s = $pdo->prepare('INSERT INTO pa_module_installation(module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,installed_at,activated_at,created_at,updated_at) VALUES(?,?,?,?,"active",1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))');
        $s->execute([$moduleKey, $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
        $s = $pdo->prepare('INSERT INTO pa_plan_module(plan_id,module_key) VALUES(?,?)');
        $s->execute([$planId, $moduleKey]);
    }
    $app->make(\app\platform\infrastructure\module\ModuleCatalogApplier::class)->apply($registry, ['peanut.identity', 'peanut.file', 'peanut.content-tools', 'peanut.rich-text', 'peanut.document', 'peanut.article']);
    $pdo->exec("INSERT INTO pa_plan_permission(plan_id,permission_key) SELECT {$planId},`key` FROM pa_permission WHERE status='active' AND `key` NOT LIKE 'platform.%'");

    $tenant = $owner->tenantId; $account = $owner->accountId; $member = $owner->memberId;
    $sessionKey = '01K00000000000000000000001';
    $s = $pdo->prepare('INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) SELECT ?,m.tenant_id,m.account_id,m.id,"admin-web","active",a.security_revision,t.security_revision,m.security_revision,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant_member m JOIN pa_account a ON a.id=m.account_id JOIN pa_tenant t ON t.id=m.tenant_id WHERE m.id=?');
    $s->execute([$sessionKey,$member]);
    $sessions = $app->make(TenantSessionAccess::class);
    $context = $sessions->revalidateSession($tenant,$account,$member,$sessionKey,'admin-web','b1-native');
    $stack = $app->make(ExecutionContextStore::class);
    $run = fn(callable $call) => $stack->run(new AdminExecutionContext($context,'b1.document',$app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class)->principal($context)->toArray()),$call);
    $storage = $app->make(StorageService::class); $access = $app->make(FileAccess::class);
    $commands = $app->make(DocumentCommands::class); $queries = $app->make(DocumentQueries::class);
    $snapshots = $app->make(FileDocumentSnapshots::class);
    $articleAdmin = $app->make(\PeanutAdmin\Modules\Article\Contract\ArticleAdministration::class);
    $category = $run(fn() => \PeanutAdmin\Modules\Article\Model\ArticleCate::create(['tenant_id'=>$tenant,'name'=>'B1 Synthetic','create_time'=>time(),'update_time'=>time()]));
    $makeArticle = fn(string $name) => $run(fn() => \PeanutAdmin\Modules\Article\Model\Article::create(['tenant_id'=>$tenant,'cid'=>$category->id,'title'=>$name,'content'=>'Legacy body unchanged','create_time'=>time(),'update_time'=>time()]));
    $article = $makeArticle('B1 Rich');
    $ownerRef=new DocumentOwnerReference('peanut.article','article',(string)$article->id,'body');
    $doc=$run(fn()=>$commands->create($context,$ownerRef,'rich_text','b1-actions-create'));$id=$doc['document_id'];
    $path=tempnam(sys_get_temp_dir(),'b1-actions-');$paths[]=$path;file_put_contents($path,'Synthetic reference');
    $file=$run(fn()=>$storage->storePath($tenant,$member,'document.resource',$path,'action.txt','text/plain'));
    $pdo->exec("UPDATE pa_role SET is_builtin=0 WHERE tenant_id={$tenant} AND `key`='core.tenant-owner'");
    $pdo->exec("DELETE rp FROM pa_role_permission rp JOIN pa_role r ON r.id=rp.role_id AND r.tenant_id=rp.tenant_id WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("INSERT IGNORE INTO pa_role_permission(tenant_id,role_id,permission_id,granted_by_member_id,granted_at) SELECT {$tenant},r.id,p.id,{$member},UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.`key` IN ('peanut.article.edit','peanut.file.list') WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");
    $context=$sessions->revalidateSession($tenant,$account,$member,$sessionKey,'admin-web','b1-actions-editor');
    $run=fn(callable $call)=>$stack->run(new AdminExecutionContext($context,'b1.actions',$app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class)->principal($context)->toArray()),$call);
    documentDenied(fn()=>$run(fn()=>$queries->detail($context,$id)),'edit permission does not grant view','ARTICLE_DOCUMENT_DENIED');
    $saved=$run(fn()=>$commands->saveSolo($context,$id,0,documentProjection('Editor only'),'b1-actions-empty'));
    documentExpect($saved['state_seq']===1,'edit-only saves empty references without extra view permission');
    $saved=$run(fn()=>$commands->saveSolo($context,$id,1,documentProjection('File preview separately allowed',$file['file_key']),'b1-actions-file'));
    documentExpect($saved['state_seq']===2 && (int)$pdo->query("SELECT COUNT(*) FROM pa_file_reference WHERE owner_id='{$id}'")->fetchColumn()===1,'edit plus independent File preview writes reference without Document view');
    documentDenied(fn()=>$run(fn()=>$access->resolve($context,$file['file_key'],'preview',$id,null,'builtin.file')),'explicit Document preview still needs current view','ARTICLE_DOCUMENT_DENIED');
    $resourceArticle=$makeArticle('B1 Actions File');
    $resource=$run(fn()=>$commands->create($context,new DocumentOwnerReference('peanut.article','article',(string)$resourceArticle->id,'body'),'file_resource','b1-actions-resource',$file['file_key']));
    documentDenied(fn()=>$run(fn()=>$commands->freeze($context,$resource['document_id'],1,'No original right','b1-actions-original-denied')),'File snapshot capture needs independent original right','FILE_ACCESS_DENIED');
    $pdo->exec("INSERT IGNORE INTO pa_role_permission(tenant_id,role_id,permission_id,granted_by_member_id,granted_at) SELECT {$tenant},r.id,p.id,{$member},UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.`key`='peanut.file.download-original' WHERE r.tenant_id={$tenant} AND r.`key`='core.tenant-owner'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");$context=$sessions->revalidateSession($tenant,$account,$member,$sessionKey,'admin-web','b1-actions-original');
    $run=fn(callable $call)=>$stack->run(new AdminExecutionContext($context,'b1.actions',$app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class)->principal($context)->toArray()),$call);
    $frozen=$run(fn()=>$commands->freeze($context,$resource['document_id'],1,'Independent File rights','b1-actions-file-freeze'));
    documentExpect($frozen['revision_id']>0 && $pdo->query("SELECT status FROM pa_file_immutable_snapshot WHERE claimed_document_id={$resource['document_id']}")->fetchColumn()==='claimed','file edit/version owner plus independent File rights can freeze without Doc view');
    $before=$pdo->query("SELECT state_seq FROM pa_document WHERE id={$id}")->fetchColumn();
    $pdo->exec("DELETE rp FROM pa_role_permission rp JOIN pa_permission p ON p.id=rp.permission_id WHERE rp.tenant_id={$tenant} AND p.`key`='peanut.file.list'");
    $pdo->exec("UPDATE pa_tenant_member SET authorization_revision=authorization_revision+1 WHERE id={$member}");$context=$sessions->revalidateSession($tenant,$account,$member,$sessionKey,'admin-web','b1-actions-no-file');
    $run=fn(callable $call)=>$stack->run(new AdminExecutionContext($context,'b1.actions',$app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class)->principal($context)->toArray()),$call);
    documentDenied(fn()=>$run(fn()=>$commands->saveSolo($context,$id,2,documentProjection('No File or Doc view',$file['file_key']),'b1-actions-denied')),'missing both independent and inherited File preview denied','ARTICLE_DOCUMENT_DENIED');
    documentExpect($before===$pdo->query("SELECT state_seq FROM pa_document WHERE id={$id}")->fetchColumn() && (int)$pdo->query("SELECT COUNT(*) FROM pa_document_operation WHERE operation_id='b1-actions-denied'")->fetchColumn()===0,'rejected File authorization rolls state and receipt back');
    echo 'DOCUMENT_ACTION_BOUNDARIES_OK assertions='.$assertions.' real_mysql=8.4 editions=engine-only'.PHP_EOL;
} finally {
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pa_file_object'")->fetchColumn()===1) {
            foreach($pdo->query('SELECT object_key,access_type FROM pa_file_object')->fetchAll(PDO::FETCH_ASSOC)as$object){
                $file=$serverRoot.'/'.($object['access_type']==='private'?'private':'public').'/storage/'.$object['object_key'];
                if(is_file($file))unlink($file);
            }
        }
        foreach($paths as$path){if(is_file($path))unlink($path);}
    } finally { RegisteredMysqlTestResource::cleanup($pdo,$database,$created); }
}
