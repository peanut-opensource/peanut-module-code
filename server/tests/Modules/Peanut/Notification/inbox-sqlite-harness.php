<?php

declare(strict_types=1);

/** Native Notification Service + real ThinkPHP repository, SQLite :memory:, inbox only. */
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/Support/ThinkPhpTestConnection.php';

use PeanutAdmin\Kernel\Auth\{TenantContext, ValidatedTenantSession};
use PeanutAdmin\Kernel\Context\{AuthorizationDecision, AuthorizedOperationContext};
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary;
use PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry;
use PeanutAdmin\Modules\Notification\Delivery\{Package};
use PeanutAdmin\Modules\Notification\Delivery\Application\{NotificationService, RecipientResolver, RecipientSnapshot, TemplateRenderer};
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationStore;
use PeanutAdmin\Modules\Notification\Delivery\Task\InboxTaskHandler;
use think\facade\Db;

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(<<<'SQL'
CREATE TABLE pa_notification_template(id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, template_key TEXT NOT NULL, name TEXT NOT NULL, subject_template TEXT NOT NULL, body_template TEXT NOT NULL, channels_json TEXT NOT NULL, variable_keys_json TEXT NOT NULL, status TEXT NOT NULL, created_by_member_id INTEGER NOT NULL, revision INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE pa_notification_message(id INTEGER PRIMARY KEY AUTOINCREMENT, message_key TEXT NOT NULL, tenant_id INTEGER NOT NULL, template_key TEXT NOT NULL, template_revision INTEGER NOT NULL, recipient_member_id INTEGER NOT NULL, recipient_account_id INTEGER NOT NULL, recipient_display_name TEXT NOT NULL, subject TEXT NOT NULL, body TEXT NOT NULL, status TEXT NOT NULL, created_by_member_id INTEGER NOT NULL, revision INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, read_at TEXT, archived_at TEXT, producer_key TEXT, producer_event_key TEXT, producer_business_key TEXT, producer_digest TEXT, producer_payload_sha256 TEXT);
CREATE TABLE pa_notification_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT, outbox_key TEXT NOT NULL, tenant_id INTEGER NOT NULL, message_id INTEGER NOT NULL, channel TEXT NOT NULL, recipient_phone_masked TEXT, recipient_phone_digest TEXT, status TEXT NOT NULL, dispatch_job_key TEXT, delivered_at TEXT, revision INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE pa_notification_attachment(id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, message_id INTEGER NOT NULL, file_key TEXT, original_name TEXT, media_type TEXT, size_bytes INTEGER, sha256 TEXT);
CREATE TABLE pa_notification_event(id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, message_id INTEGER NOT NULL, event_key TEXT, actor_member_id INTEGER, metadata_json TEXT, occurred_at TEXT);
SQL);
new think\App(dirname(__DIR__, 4));
ThinkPhpTestConnection::fromPdo($pdo);
$members = new class implements TenantMemberDirectory {
    private function projection(int $tenantId, int $memberId): ?TenantMemberSummary {
        return ($tenantId === 101 && $memberId === 501) || ($tenantId === 202 && $memberId === 502)
            ? new TenantMemberSummary($tenantId, $memberId, $tenantId === 101 ? 301 : 302, 'Synthetic Member', 1, 1, 1, 1, 1) : null;
    }
    public function activeMembership(int $tenantId, int $memberId): ?TenantMemberSummary { return $this->projection($tenantId,$memberId); }
    public function activeMembershipCount(int $accountId): int { return in_array($accountId,[301,302],true) ? 1 : 0; }
    public function current(TenantContext $context): ?TenantMemberSummary { $m=$this->projection($context->tenantId,$context->memberId);return $m && $m->accountId===$context->accountId ? $m : null; }
    public function activeMember(TenantContext $context,int $memberId): ?TenantMemberSummary {return $this->current($context) ? $this->projection($context->tenantId,$memberId) : null;}
};
$repo = new NotificationStore($members);
$recipients = new class implements RecipientResolver {
    public function snapshot(TenantContext $context,int $memberId,bool $requiresSms): RecipientSnapshot {
        if ($requiresSms || $context->tenantId !== 101 || $memberId !== 501) throw new RuntimeException('Synthetic inbox-only boundary violation');
        return new RecipientSnapshot(501,301,'Synthetic Member',null,null);
    }
};
$available = new class implements ModuleAvailability {
    public function assertAvailable(TenantScope $scope,string $moduleKey,DateTimeImmutable $now,bool $lock=false):void {if ($moduleKey !== Package::MODULE_KEY) throw new LogicException('Only Notification allowed');}
    public function assertDeployment(string $moduleKey,bool $lock=false):void {if($moduleKey!==Package::MODULE_KEY)throw new LogicException('Invalid module');}
    public function assertTenant(TenantScope $scope,string $moduleKey,DateTimeImmutable $now,bool $lock=false):void {$this->assertAvailable($scope,$moduleKey,$now,$lock);}
};
$handler=(new ReflectionClass(InboxTaskHandler::class))->newInstanceWithoutConstructor();
$service=new NotificationService($repo,$recipients,null,new TemplateRenderer(),new NotificationProducerRegistry(),$available,$handler);
function fixtureContext(int $tenant,int $account,int $member,string $operation): AuthorizedOperationContext {
    $session=new ValidatedTenantSession(1,'01J00000000000000000000000',$tenant,$account,$member,'admin-web',new DateTimeImmutable('2026-11-26T00:00:00Z'),1,1);
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(TenantContext::fromValidatedSession($session,'native-inbox-synthetic'),Package::RESOURCE_KEY,$operation,[],hash('sha256','notification-synthetic')));
}
$manage=fixtureContext(101,301,501,'manage');
$read=fixtureContext(101,301,501,'read');
$other=fixtureContext(202,302,502,'read');
$checks=0;
function check(bool $condition,string $message):void {global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
$template=$service->putTemplate($manage,'techqa.inbox','Test inbox','Notice {{code}}','Hello {{code}}',['inbox'],['code'],null);
check($template['revision']===1,'template revision');
$published=$service->publish($manage,'techqa.inbox',[['member_id'=>501,'variables'=>['code'=>'R1']]]);
check(count($published['messages'])===1 && count($published['outbox'])===1,'one inbox-only publication');
check($published['outbox'][0]->channel==='inbox','outbox channel');
check($service->inbox($read,'all',1,20)['total']===0,'undelivered hidden');
$key=$published['outbox'][0]->outboxKey;
$job='job_'.str_repeat('a',32);
$repo->bindJob(101,$key,$job);
$repo->bindJob(101,$key,$job);
$repo->deliverInbox(101,$key,$job);
$repo->deliverInbox(101,$key,$job);
$list=$service->inbox($read,'all',1,20);
check($list['total']===1,'delivered own inbox visible');
check($list['items'][0]->subject==='Notice R1','template rendered');
check($service->inbox($other,'all',1,20)['total']===0,'cross-tenant denied by read projection');
$readMessage=$service->markRead($read,$list['items'][0]->messageKey,1);
check($readMessage->status==='read' && $readMessage->revision===2,'mark-read CAS');
check((int)$pdo->query("SELECT COUNT(*) FROM pa_notification_outbox WHERE channel='sms'")->fetchColumn()===0,'no SMS outbox');
check((int)$pdo->query("SELECT COUNT(*) FROM pa_notification_event WHERE event_key='tenant.notification.delivered'")->fetchColumn()===1,'delivered deduplicated');
echo "NOTIFICATION_NATIVE_SQLITE_INBOX_PASS checks=$checks published=1 delivered=1 markRead=1 tenant202=0 sms=0\n";

