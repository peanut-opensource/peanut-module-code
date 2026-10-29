<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Service;

use app\common\exception\BusinessException;
use app\common\services\audit\AuditContractHost;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\VersionSnapshot;
use PeanutAdmin\Modules\Document\Contract\DocumentCollaborativeWrites;
use PeanutAdmin\Modules\Document\Contract\AcceptedStoreInput;
use PeanutAdmin\Modules\Document\Contract\AcceptedWritePlan;
use PeanutAdmin\Modules\Document\Contract\DurableSaveReceipt;
use PeanutAdmin\Modules\Document\Contract\BusinessBarrierPlan;
use PeanutAdmin\Modules\Document\Contract\DrainedBusinessBarrier;
use PeanutAdmin\Modules\Document\Contract\DocumentBusinessReceipt;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationReadFence;
use PeanutAdmin\Modules\File\Contract\AcceptedReferenceTransfer;
use PeanutAdmin\Modules\File\Contract\CollaborationReferenceInput;
use PeanutAdmin\Modules\Document\Contract\ExplicitTargetInput;
use PeanutAdmin\Modules\Document\Contract\ExplicitTargetPreparationInput;
use PeanutAdmin\Modules\File\Contract\ReservationReleaseInput;
use PeanutAdmin\Modules\Document\Model\Document;
use PeanutAdmin\Modules\Document\Model\DocumentState;
use PeanutAdmin\Modules\Document\Model\DocumentRevision;
use PeanutAdmin\Modules\Document\Model\DocumentResourceBinding;
use PeanutAdmin\Modules\Document\Model\DocumentFileReference;
use PeanutAdmin\Modules\Document\Model\DocumentOperation;
use PeanutAdmin\Modules\File\Contract\FileDocumentReferencePolicy;
use PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots;
use PeanutAdmin\Modules\File\Contract\FileReferenceCommands;
use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext;
use PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService;
use think\facade\Db;

/** One native owner/Document transaction per mutation; versions and command receipts are append-only. */
final readonly class DocumentService implements DocumentCommands, DocumentQueries, DocumentCollaborativeWrites, FileDocumentReferencePolicy
{
    private \WeakMap $preparedVersions;
    private \WeakMap $acceptedTransfers;
    private \WeakMap $acceptedReleases;
    private \WeakMap $targetTransfers;
    private const PREPARED_LIFETIME_NANOSECONDS = 120_000_000_000;

    public function __construct(
        private DocumentAuthorizationService $authorization,
        private FileReferenceCommands $references,
        private FileDocumentSnapshots $snapshots,
        private FileStorage $files,
        private AuditContractHost $audit,
        private ContentCapabilitiesService $tools,
        private ExecutionContextStore $execution,
        private DocumentAcceptedWriteRegistry $acceptedWrites,
        private DocumentOwnerRegistry $owners,
        private TenantAuthorizationReadFence $authorizationFence,
        private \PeanutAdmin\Kernel\Module\ModuleRuntimeRepository $modules,
    ) {
        $this->preparedVersions = new \WeakMap();
        $this->acceptedTransfers = new \WeakMap();
        $this->acceptedReleases = new \WeakMap();
        $this->targetTransfers = new \WeakMap();
    }

    public function create(TenantContext $context, DocumentOwnerReference $owner, string $contentKind, string $requestId, ?string $fileKey = null): array
    {
        if (!in_array($contentKind, ['rich_text','file_resource'], true) || ($contentKind === 'file_resource') !== ($fileKey !== null)) {
            throw BusinessException::invalid('DOCUMENT_KIND_INVALID', '文档类型或主资源无效');
        }
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $owner, $contentKind, $requestId, $fileKey): array {
            $this->authorization->owner($current, $owner, 'create', true);
            $input = [$owner->moduleKey,$owner->objectType,$owner->objectId,$owner->slot,$contentKind,$fileKey];
            $document = Document::where('owner_module_key', $owner->moduleKey)->where('owner_object_type', $owner->objectType)
                ->where('owner_object_id', $owner->objectId)->where('slot', $owner->slot)->lock(true)->find();
            if ($document !== null) {
                $document = $this->authorization->document($current, (int) $document->id, 'edit', true);
                if ($document->content_kind !== $contentKind) {
                    throw BusinessException::conflict('DOCUMENT_SLOT_OCCUPIED', '业务槽位已有其他类型文档');
                }
                $replay = $this->replay($document, 'create', $requestId, $input);
                if ($replay !== null) {
                    return $replay;
                }
                return $this->receipt($current, $document, 'create', $requestId, $input, $this->summary($document) + ['created' => false]);
            }
            $resource = $fileKey === null ? null : $this->snapshots->describeForDocument($current, $fileKey);
            $document = Document::create(['tenant_id' => $current->tenantId,'owner_module_key' => $owner->moduleKey,'owner_object_type' => $owner->objectType,
                'owner_object_id' => $owner->objectId,'slot' => $owner->slot,'content_kind' => $contentKind,'body_type' => $contentKind === 'rich_text' ? 'rich_text' : null,
                'schema_version' => $contentKind === 'rich_text' ? RichTextProjection::SCHEMA : 'peanut.file-resource/1','status' => 'active','auth_revision' => 1,
                'state_seq' => $resource === null ? 0 : 1,'collaboration_enabled' => 0,'lease_epoch' => 0,'created_by_member_id' => $current->memberId,
                'updated_by_member_id' => $current->memberId,'create_time' => time(),'update_time' => time()]);
            if ($resource === null) {
                $projection = RichTextProjection::empty();
                DocumentState::create(['tenant_id' => $current->tenantId,'document_id' => $document->id,'yjs_state_binary' => null,'state_seq' => 0,
                    'state_sha256' => hash('sha256', $projection->json),'projected_json' => $projection->json,'projection_schema_version' => RichTextProjection::SCHEMA,
                    'persisted_at' => self::now(),'last_store_id' => $requestId]);
                $this->writeReferences($current, $document, []);
            } else {
                $this->writeBinding($current, $document, $resource);
                $this->writeReferences($current, $document, [['file_key' => $fileKey,'variant_key' => null]]);
            }
            return $this->receipt($current, $document, 'create', $requestId, $input, $this->summary($document) + ['created' => true]);
        }));
    }

    public function assertOwnerDeletionAllowed(TenantContext $context, DocumentOwnerReference $owner): void
    {
        $this->authorization->run($context, function (TenantContext $current) use ($owner): void {
            $this->authorization->owner($current, $owner, 'purge', true);
            if (Document::where('owner_module_key', $owner->moduleKey)->where('owner_object_type', $owner->objectType)->where('owner_object_id', $owner->objectId)->lock(true)->find() !== null) {
                throw BusinessException::conflict('DOCUMENT_OWNER_RETAINED', '业务仍有受保护文档，不可永久删除');
            }
        });
    }

    public function saveSolo(TenantContext $context, int $documentId, int $expectedStateSeq, array $structuredContent, string $requestId): array
    {
        $projection = RichTextProjection::fromStructured($structuredContent);
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $documentId, $expectedStateSeq, $projection, $requestId): array {
            $document = $this->authorization->document($current, $documentId, 'edit', true);
            $this->solo($document, 'rich_text');
            $input = [$expectedStateSeq,hash('sha256', $projection->json)];
            $replay = $this->replay($document, 'solo.save', $requestId, $input);
            if ($replay !== null) {
                return $replay;
            }
            $this->cas($document, $expectedStateSeq);
            $next = $expectedStateSeq + 1;
            $state = DocumentState::where('document_id', $documentId)->lock(true)->find();
            if ($state === null || $state->yjs_state_binary !== null) {
                throw BusinessException::conflict('DOCUMENT_SOLO_STATE_INVALID', '文档持久模式不允许单人保存');
            }
            $state->save(['state_seq' => $next,'state_sha256' => hash('sha256', $projection->json),'projected_json' => $projection->json,
                'persisted_at' => self::now(),'last_store_id' => $requestId]);
            $document->save(['state_seq' => $next,'updated_by_member_id' => $current->memberId,'update_time' => time()]);
            $this->writeReferences($current, $document, $projection->fileReferences);
            return $this->receipt($current, $document, 'solo.save', $requestId, $input, ['document_id' => $documentId,'state_seq' => $next,'persisted_at' => self::now(),'digest' => hash('sha256', $projection->json)]);
        }));
    }

    public function bindResource(TenantContext $context, int $documentId, string $fileKey, int $expectedStateSeq, string $requestId): array
    {
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $documentId, $fileKey, $expectedStateSeq, $requestId): array {
            $document = $this->authorization->document($current, $documentId, 'edit', true);
            $this->solo($document, 'file_resource');
            $input = [$expectedStateSeq,$fileKey];
            $replay = $this->replay($document, 'resource.bind', $requestId, $input);
            if ($replay !== null) {
                return $replay;
            }
            $this->cas($document, $expectedStateSeq);
            $resource = $this->snapshots->describeForDocument($current, $fileKey);
            $document->save(['state_seq' => $expectedStateSeq + 1,'updated_by_member_id' => $current->memberId,'update_time' => time()]);
            $this->writeBinding($current, $document, $resource);
            $this->writeReferences($current, $document, [['file_key' => $fileKey,'variant_key' => null]]);
            return $this->receipt($current, $document, 'resource.bind', $requestId, $input, ['document_id' => $documentId,'state_seq' => $expectedStateSeq + 1,'file_key' => $fileKey,'digest' => $resource['sha256']]);
        }));
    }

    public function freeze(TenantContext $context, int $documentId, int $expectedStateSeq, string $reason, string $requestId): array
    {
        if (trim($reason) === '' || mb_strlen($reason) > 255) {
            throw BusinessException::invalid('DOCUMENT_REVISION_REASON_INVALID', '版本原因无效');
        }
        if ((bool) Document::where('id', $documentId)->value('collaboration_enabled')) {
            return $this->freezeExplicitTarget($context, $documentId, $expectedStateSeq, $reason, $requestId);
        }
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $expectedStateSeq, $reason, $requestId): array {
            $input = [$expectedStateSeq,$reason];
            $plan = Db::transaction(function () use ($current, $documentId, $expectedStateSeq, $requestId, $input): array {
                $document = $this->authorization->document($current, $documentId, 'version.create', true);
                $this->solo($document);
                $replay = $this->replay($document, 'version.create', $requestId, $input);
                if ($replay !== null) {
                    return ['replay' => $replay];
                }
                $this->cas($document, $expectedStateSeq);
                return ['binding' => $document->content_kind === 'file_resource' ? $this->binding($document) : null];
            });
            if (isset($plan['replay'])) {
                return $plan['replay'];
            }
            $snapshot = null;
            if ($plan['binding'] !== null) {
                $snapshot = $this->snapshots->captureForDocument($current, $plan['binding']['primary_file_key'], $plan['binding']['source_sha256'], (int) $plan['binding']['source_file_revision'], 'document:' . $documentId . ':' . $requestId);
            }
            try {
                return Db::transaction(function () use ($current, $documentId, $expectedStateSeq, $reason, $requestId, $input, $snapshot, $plan): array {
                    $document = $this->authorization->document($current, $documentId, 'version.create', true);
                    $this->solo($document);
                    $replay = $this->replay($document, 'version.create', $requestId, $input);
                    if ($replay !== null) {
                        return $replay;
                    }
                    $this->cas($document, $expectedStateSeq);
                    if ($snapshot !== null && $this->binding($document) !== $plan['binding']) {
                        throw BusinessException::conflict('DOCUMENT_BINDING_CHANGED', '主资源绑定已改变');
                    }
                    $revision = $this->appendRevision($current, $document, $reason, $snapshot);
                    if ($snapshot !== null) {
                        $this->revisionReference($current, $document, $snapshot['file_key']);
                        $this->syncReferences($current, $document);
                        $this->snapshots->claimDocumentSnapshot($current, $documentId, $snapshot);
                    }
                    return $this->receipt($current, $document, 'version.create', $requestId, $input, $revision);
                });
            } catch (\Throwable $error) {
                if ($snapshot !== null) {
                    try {
                        $this->snapshots->abandonDocumentSnapshot($current, $snapshot);
                    } catch (\Throwable) { /* The durable File recovery ledger survives; preserve the owner/CAS error. */
                    }
                }
                throw $error;
            }
        });
    }

    public function restoreRevision(TenantContext $context, int $documentId, int $revisionId, int $expectedStateSeq, string $requestId): array
    {
        if ((bool) Document::where('id', $documentId)->value('collaboration_enabled')) {
            return $this->collaborativeBusiness($context, $documentId, 'version.restore', $requestId, [$revisionId,$expectedStateSeq]);
        }
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $documentId, $revisionId, $expectedStateSeq, $requestId): array {
            $document = $this->authorization->document($current, $documentId, 'edit', true);
            $this->solo($document);
            $this->authorization->document($current, $documentId, 'version.create', true);
            $input = [$revisionId,$expectedStateSeq];
            $replay = $this->replay($document, 'version.restore', $requestId, $input);
            if ($replay !== null) {
                return $replay;
            }
            $this->cas($document, $expectedStateSeq);
            $revision = $this->revision($document, $revisionId);
            if ($revision->content_kind !== $document->content_kind) {
                throw BusinessException::conflict('DOCUMENT_REVISION_KIND_MISMATCH', '版本类型不一致');
            }
            $document->save(['state_seq' => $expectedStateSeq + 1,'updated_by_member_id' => $current->memberId,'update_time' => time()]);
            $snapshot = null;
            if ($document->content_kind === 'rich_text') {
                $projection = RichTextProjection::fromStructured(self::json($revision->content_snapshot_json));
                $state = DocumentState::where('document_id', $documentId)->lock(true)->find();
                if ($state === null || $revision->yjs_snapshot !== null) {
                    throw BusinessException::conflict('DOCUMENT_COLLABORATION_BARRIER_REQUIRED', '协作版本恢复需要真实服务屏障');
                }
                $state->save(['projected_json' => $projection->json,'state_sha256' => hash('sha256', $projection->json),'yjs_state_binary' => null,
                    'state_seq' => $expectedStateSeq + 1,'persisted_at' => self::now(),'last_store_id' => $requestId]);
                $this->writeReferences($current, $document, $projection->fileReferences);
            } else {
                $snapshot = $this->snapshots->describeForDocument($current, (string) $revision->immutable_file_key);
                if (!hash_equals((string) $revision->immutable_file_sha256, $snapshot['sha256'])) {
                    throw BusinessException::conflict('DOCUMENT_REVISION_BYTES_CHANGED', '历史字节校验失败');
                }
                $this->writeBinding($current, $document, $snapshot);
                $this->writeReferences($current, $document, [['file_key' => $snapshot['file_key'],'variant_key' => null]]);
            }
            return $this->receipt($current, $document, 'version.restore', $requestId, $input, $this->appendRevision($current, $document, 'restore:' . $revisionId, $snapshot));
        }));
    }

    public function archive(TenantContext $context, int $documentId, int $expectedAuthRevision, string $requestId): array
    {
        return $this->lifecycle($context, $documentId, $expectedAuthRevision, $requestId, 'archive');
    }
    public function restore(TenantContext $context, int $documentId, int $expectedAuthRevision, string $requestId): array
    {
        return $this->lifecycle($context, $documentId, $expectedAuthRevision, $requestId, 'restore');
    }
    private function lifecycle(TenantContext $context, int $id, int $expected, string $requestId, string $action): array
    {
        if ((bool) Document::where('id', $id)->value('collaboration_enabled')) {
            return $this->collaborativeBusiness($context, $id, $action, $requestId, [$expected]);
        }
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $id, $expected, $requestId, $action): array {
            $document = $this->authorization->document($current, $id, $action, true);
            $this->solo($document);
            $input = [$expected];
            $replay = $this->replay($document, $action, $requestId, $input);
            if ($replay !== null) {
                return $replay;
            }
            if ((int) $document->auth_revision !== $expected) {
                throw BusinessException::conflict('DOCUMENT_AUTH_REVISION_CONFLICT', '文档授权状态已改变');
            }
            $status = $action === 'archive' ? 'archived' : 'active';
            if ($document->status !== $status) {
                $document->save(['status' => $status,'auth_revision' => $expected + 1,'archived_at' => $action === 'archive' ? self::now() : null,'updated_by_member_id' => $current->memberId,'update_time' => time()]);
            }
            return $this->receipt($current, $document, $action, $requestId, $input, $this->summary($document));
        }));
    }
    public function runOwnerLifecycle(TenantContext $context, DocumentOwnerReference $owner, string $action, callable $commitOwner): mixed
    {
        if (!in_array($action, ['archive','restore'], true)) {
            throw BusinessException::invalid('DOCUMENT_OWNER_ACTION_INVALID', '归属生命周期动作无效');
        }
        $this->outsideTransaction();
        $state = $this->owners->requirePolicy($owner)->resolveOwner($context, $owner);
        $already = $state->archived === ($action === 'archive');
        $authorizationAction = $already ? $action : $action . '.prepare';
        $prepared = $this->businessTransaction($context, $owner, $authorizationAction, function (TenantContext $current) use ($owner, $action, $already): array {
            $ownerState = $this->authorization->owner($current, $owner, $already ? $action : $action . '.prepare', true);
            $documents = $this->ownerDocuments($owner)->lock(true)->select();
            $out = [];
            foreach ($documents as $doc) {
                if ($doc->content_kind === 'rich_text') {
                    DocumentState::where('document_id', $doc->id)->lock(true)->find();
                }
                DocumentOperation::where('document_id', $doc->id)->lock(true)->select();
            }
            foreach ($documents as $doc) {
                $pending = DocumentOperation::where('document_id', $doc->id)->where('action', $action)->whereNotNull('barrier_plan_json')
                    ->whereNull('released_at')->order('id', 'desc')->lock(true)->find();
                if ($pending !== null) {
                    $this->assertBusinessActor($current, $pending);
                    $out[] = ['id' => (int) $doc->id,'operation_id' => $pending->operation_id,'input' => self::json($pending->command_input_json),
                        'plan' => new BusinessBarrierPlan(self::json($pending->barrier_plan_json)),'completed' => $pending->phase === 'completed'];
                    continue;
                }
                if ($already) {
                    if ($doc->status !== ($action === 'archive' ? 'archived' : 'active')) {
                        throw BusinessException::conflict('DOCUMENT_OWNER_LIFECYCLE_INCOMPLETE', '归属与槽位生命周期不一致');
                    }
                    continue;
                }
                $input = [(int) $doc->auth_revision];
                $key = 'owner:' . $action . ':' . hash('sha256', $ownerState->revision . ':' . $doc->id . ':' . $doc->auth_revision);
                $plan = (bool) $doc->collaboration_enabled ? $this->prepareBusiness($current, $doc, $action, $key, $input, $ownerState->revision) : null;
                $out[] = ['id' => (int) $doc->id,'operation_id' => $key,'input' => $input,'plan' => $plan,'completed' => false];
            }
            return ['owner_revision' => $ownerState->revision,'items' => $out];
        });
        $provider = null;
        foreach ($prepared['items'] as $item) {
            if ($item['plan'] !== null && !$item['completed']) {
                $provider ??= $this->acceptedWrites->requireProvider();
                $provider->drainBusinessBarrier($item['plan']);
            }
        }
        if (!$already) {
            $result = $this->businessTransaction($context, $owner, $action . '.prepare', function (TenantContext $current) use ($owner, $action, $commitOwner, $prepared): mixed {
                $before = $this->authorization->owner($current, $owner, $action . '.prepare', true);
                if (!hash_equals($before->revision, $prepared['owner_revision'])) {
                    throw BusinessException::conflict('DOCUMENT_OWNER_REVISION_CONFLICT', '归属在排空期间已改变');
                }
                $documents = $this->ownerDocuments($owner)->lock(true)->select();
                if (array_map(static fn($d): int => (int) $d->id, $documents->all()) !== array_column($prepared['items'], 'id')) {
                    throw BusinessException::conflict('DOCUMENT_OWNER_SLOTS_CHANGED', '归属槽位在排空期间已改变');
                }
                $proofs = [];
                // Lock every Doc/state/operation before any Room/barrier; File comes last.
                foreach ($documents as $doc) {
                    if ($doc->content_kind === 'rich_text') {
                        DocumentState::where('document_id', $doc->id)->lock(true)->find();
                    }
                    DocumentOperation::where('document_id', $doc->id)->lock(true)->select();
                }
                foreach ($prepared['items'] as $item) {
                    if ($item['plan'] !== null) {
                        $proofs[$item['id']] = $this->acceptedWrites->requireProvider()->lockDrainedBusinessBarrier($current, $item['plan']);
                    }
                }
                $pdo = Db::connect()->getPdo();
                $result = $commitOwner();
                if ($pdo !== Db::connect()->getPdo() || !$pdo->inTransaction()) {
                    throw new \LogicException('DOCUMENT_OWNER_TRANSACTION_CHANGED');
                }
                foreach ($documents as $doc) {
                    $this->authorization->document($current, (int) $doc->id, $action, true);
                    $item = $prepared['items'][array_search((int) $doc->id, array_column($prepared['items'], 'id'), true)];
                    if ($item['plan'] !== null) {
                        $this->finishBusiness($current, $doc, $item['plan'], $proofs[(int) $doc->id], $item['input']);
                    } else {
                        $this->casLifecycle($doc, $item['input'][0]);
                        $doc->save(['status' => $action === 'archive' ? 'archived' : 'active','auth_revision' => (int) $doc->auth_revision + 1,
                            'archived_at' => $action === 'archive' ? self::now() : null,'update_time' => time()]);
                        $this->receipt($current, $doc, $action, $item['operation_id'], $item['input'], $this->summary($doc));
                    }
                }
                return $result;
            });
        } else {
            $result = $this->businessTransaction($context, $owner, $authorizationAction, function (TenantContext $current) use ($owner, $authorizationAction, $commitOwner): mixed {
                $this->authorization->owner($current, $owner, $authorizationAction, true);
                return $commitOwner();
            });
        }
        foreach ($prepared['items'] as $item) {
            if ($item['plan'] !== null) {
                $this->releaseBusiness($context, $item['plan']);
            }
        }
        return $result;
    }

    private function ownerDocuments(DocumentOwnerReference $owner): \think\db\BaseQuery
    {
        return Document::where('owner_module_key', $owner->moduleKey)->where('owner_object_type', $owner->objectType)->where('owner_object_id', $owner->objectId)->order('id');
    }

    private function outsideTransaction(): void
    {
        $pdo = Db::connect()->getPdo();
        if ($pdo instanceof \PDO && $pdo->inTransaction()) {
            throw BusinessException::conflict('DOCUMENT_OUTER_TRANSACTION_DENIED', '业务排空必须先提交短准备事务');
        }
    }

    /** Native decision remains authoritative; the digest only prevents RR snapshots from missing revocation. */
    private function businessTransaction(TenantContext $context, DocumentOwnerReference $owner, string $action, callable $operation): mixed
    {
        $this->outsideTransaction();
        return $this->authorization->run($context, function (TenantContext $current) use ($owner, $action, $operation): mixed {
            $policy = $this->owners->requirePolicy($owner);
            $permissions = $policy->authorizationPermissionKeys($action);
            $modules = ['peanut.document','peanut.file',$owner->moduleKey];
            // Collaboration is optional for solo owner slots; include only if installed provider is actually required.
            if ($this->ownerDocuments($owner)->where('collaboration_enabled', 1)->count() > 0) {
                $modules[] = 'peanut.collaboration';
            }
            foreach ($modules as $module) {
                $this->authorization->requireModule($current, $module);
            }
            $before = $this->authorizationFence->snapshot($current, $permissions, $modules);
            $this->authorization->owner($current, $owner, $action, false);
            if (!hash_equals($before, $this->authorizationFence->snapshot($current, $permissions, $modules))) {
                throw BusinessException::conflict('DOCUMENT_AUTHORIZATION_CHANGED', '准备期间授权输入改变');
            }
            return Db::transaction(function () use ($current, $permissions, $modules, $before, $operation, $action): mixed {
                try {
                    $this->authorizationFence->lockAndAssert($current, $permissions, $modules, $before);
                } catch (\DomainException) {
                    throw BusinessException::conflict('DOCUMENT_AUTHORIZATION_CHANGED', '当前授权输入已改变');
                }
                return $operation($current);
            });
        });
    }

    private static function businessActor(TenantContext $current): array
    {
        // The original actor is fixed; a fresh validated session is still required on every continuation.
        // Session expiry must not make a durable operation permanently unrecoverable for that same actor.
        return ['tenant_id' => $current->tenantId,'account_id' => $current->accountId,'member_id' => $current->memberId];
    }

    private function assertBusinessActor(TenantContext $current, DocumentOperation $operation): void
    {
        if (RichTextProjection::canonical(self::json($operation->initiator_json)) !== RichTextProjection::canonical(self::businessActor($current))) {
            throw BusinessException::forbidden('DOCUMENT_BUSINESS_ACTOR_MISMATCH', '业务操作必须由原人员的当前合法登录身份继续');
        }
    }

    private function collaborativeBusiness(TenantContext $context, int $id, string $action, string $key, array $input): array
    {
        $this->outsideTransaction();
        self::requestKey($key);
        $candidate = Document::where('id', $id)->find();
        if ($candidate === null) {
            throw BusinessException::notFound('DOCUMENT_NOT_FOUND', '文档不存在');
        }
        $owner = DocumentAuthorizationService::reference($candidate);
        $permissionAction = str_starts_with($action, 'version.') ? 'version.create' : $action;
        $plan = $this->businessTransaction($context, $owner, $permissionAction, function (TenantContext $current) use ($id, $action, $key, $input, $permissionAction): BusinessBarrierPlan {
            $doc = $this->authorization->document($current, $id, $permissionAction, true);
            DocumentState::where('document_id', $id)->lock(true)->find();
            $old = DocumentOperation::where('document_id', $id)->where('operation_id', $key)->lock(true)->find();
            if ($old !== null) {
                if ($old->action !== $action || !hash_equals($old->input_sha256, self::inputHash($input)) || $old->barrier_plan_json === null) {
                    throw BusinessException::conflict('DOCUMENT_IDEMPOTENCY_CONFLICT', '同一请求标识不能改变动作或内容');
                }
                $this->assertBusinessActor($current, $old);
                return new BusinessBarrierPlan(self::json($old->barrier_plan_json));
            }
            if (str_starts_with($action, 'version.')) {
                $this->cas($doc, $action === 'version.restore' ? $input[1] : $input[0]);
            } else {
                $this->casLifecycle($doc, $input[0]);
            }
            $ownerState = $this->owners->requirePolicy(DocumentAuthorizationService::reference($doc))->resolveOwner($current, DocumentAuthorizationService::reference($doc), true);
            return $this->prepareBusiness($current, $doc, $action, $key, $input, $ownerState->revision);
        });
        $old = DocumentOperation::where('document_id', $id)->where('operation_id', $key)->find();
        if ($old->phase !== 'completed') {
            try {
                $this->acceptedWrites->requireProvider()->drainBusinessBarrier($plan);
                $this->businessTransaction($context, $owner, $permissionAction, function (TenantContext $current) use ($id, $plan, $input, $permissionAction): void {
                    $doc = $this->authorization->document($current, $id, $permissionAction, true);
                    $state = DocumentState::where('document_id', $id)->lock(true)->find();
                    $operation = DocumentOperation::where('document_id', $id)->where('operation_id', $plan->data['operation_id'])->lock(true)->find();
                    $this->assertBusinessActor($current, $operation);
                    if ($operation->phase === 'completed') {
                        return;
                    }
                    $ownerState = $this->owners->requirePolicy($plan->owner)->resolveOwner($current, $plan->owner, true);
                    if (!hash_equals($plan->data['owner_revision'], hash('sha256', $ownerState->revision))) {
                        throw BusinessException::conflict('DOCUMENT_OWNER_REVISION_CONFLICT', '排空期间业务归属改变');
                    }
                    $proof = $this->acceptedWrites->requireProvider()->lockDrainedBusinessBarrier($current, $plan);
                    $this->finishBusiness($current, $doc, $plan, $proof, $input);
                });
            } catch (\Throwable $error) {
                $this->markBusinessAttention($context, $plan, $permissionAction);
                throw $error;
            }
        }
        return $this->releaseBusiness($context, $plan);
    }

    private function prepareBusiness(TenantContext $current, Document $doc, string $action, string $key, array $input, string $ownerRevision): BusinessBarrierPlan
    {
        self::requestKey($key);
        $operation = DocumentOperation::create(['tenant_id' => $current->tenantId,'document_id' => $doc->id,'operation_id' => $key,
            'action' => $action,'input_sha256' => self::inputHash($input),'result_json' => '{}','command_input_json' => json_encode($input, JSON_THROW_ON_ERROR),
            'phase' => 'prepared','initiator_json' => json_encode(self::businessActor($current), JSON_THROW_ON_ERROR),'created_at' => self::now()]);
        $plan = $this->acceptedWrites->requireProvider()->prepareBusinessBarrier(
            $current,
            (int) $doc->id,
            DocumentAuthorizationService::reference($doc),
            $action,
            $key,
            self::inputHash($input),
            (int) $doc->state_seq,
            (int) $doc->auth_revision,
            hash('sha256', $ownerRevision),
        );
        $operation->save(['barrier_plan_json' => json_encode($plan->data, JSON_THROW_ON_ERROR)]);
        if ($action === 'archive') {
            $doc->save(['status' => 'archiving']);
        }
        return $plan;
    }

    private function markBusinessAttention(TenantContext $context, BusinessBarrierPlan $plan, string $action): void
    {
        try {
            $this->businessTransaction($context, $plan->owner, $action, function (TenantContext $current) use ($plan, $action): void {
                $this->authorization->owner($current, $plan->owner, $action, true);
                Document::where('id', $plan->data['binding']['document_id'])->lock(true)->find();
                $op = DocumentOperation::where('document_id', $plan->data['binding']['document_id'])->where('operation_id', $plan->data['operation_id'])->lock(true)->find();
                $this->assertBusinessActor($current, $op);
                if ($op->phase !== 'completed') {
                    $op->save(['phase' => 'attention']);
                    $this->audit->appendTenantMember(
                        $current,
                        'document.business.attention',
                        'document.' . $plan->data['action'],
                        'document',
                        (string) $plan->data['binding']['document_id'],
                        null,
                        null,
                        0,
                        null,
                        ['operation_id' => $plan->data['operation_id']],
                    );
                }
            });
        } catch (\Throwable) {
            // Revocation can deny even diagnostics. Original prepared ledger/gate remains; never unlock or hide the original failure.
        }
    }

    private function casLifecycle(Document $doc, int $expected): void
    {
        if ((int) $doc->auth_revision !== $expected) {
            throw BusinessException::conflict('DOCUMENT_AUTH_REVISION_CONFLICT', '文档生命周期已改变');
        }
    }

    private function finishBusiness(TenantContext $current, Document $doc, BusinessBarrierPlan $plan, DrainedBusinessBarrier $proof, array $input): array
    {
        $operation = DocumentOperation::where('document_id', $doc->id)->where('operation_id', $plan->data['operation_id'])->lock(true)->find();
        $state = DocumentState::where('document_id', $doc->id)->lock(true)->find();
        if ($operation === null || $state === null || $operation->phase === 'completed' || !hash_equals($operation->input_sha256, $plan->data['input_sha256'])
            || !hash_equals(self::inputHash($input), $operation->input_sha256) || (int) $doc->state_seq !== $proof->stateSeq
            || (int) $state->state_seq !== $proof->stateSeq || $state->state_sha256 !== $proof->binarySha256
            || !is_string($state->yjs_state_binary) || hash('sha256', $state->yjs_state_binary) !== $proof->binarySha256
            || (int) $doc->auth_revision !== $plan->data['base_auth_revision']) {
            throw BusinessException::conflict('DOCUMENT_BUSINESS_CAS_CONFLICT', '业务提交与排空后的持久状态不一致');
        }
        $this->assertBusinessActor($current, $operation);
        $action = $plan->data['action'];
        $revisionResult = [];
        if ($action === 'version.restore') {
            $revision = $this->revision($doc, $input[0]);
            $snapshot = $this->snapshotFromRevision($current, $doc, $revision);
            if ($revision->content_kind !== 'rich_text' || !is_string($revision->yjs_snapshot) || $revision->yjs_snapshot === '' || strlen($revision->yjs_snapshot) > 4194304) {
                throw BusinessException::conflict('DOCUMENT_VERSION_BINARY_MISSING', '历史版本缺少不可变协作字节');
            }
            $projection = RichTextProjection::fromStructured($snapshot->structuredContent);
            $next = $proof->stateSeq + 1;
            $doc->save(['state_seq' => $next,'auth_revision' => (int) $doc->auth_revision + 1,
                'lease_epoch' => $plan->data['lease_epoch'] + 1,'updated_by_member_id' => $current->memberId,'update_time' => time()]);
            $state->save(['yjs_state_binary' => $revision->yjs_snapshot,'projected_json' => $projection->json,'state_seq' => $next,
                'state_sha256' => hash('sha256', $revision->yjs_snapshot),'projection_schema_version' => RichTextProjection::SCHEMA,
                'persisted_at' => self::now(),'last_store_id' => $plan->data['operation_id']]);
            $this->writeReferences($current, $doc, $projection->fileReferences);
            $revisionResult = $this->appendRevision($current, $doc, 'restore:' . $revision->id, null);
        } else {
            $doc->save(['status' => $action === 'archive' ? 'archived' : 'active','auth_revision' => (int) $doc->auth_revision + 1,
                'lease_epoch' => $plan->data['lease_epoch'] + 1,'archived_at' => $action === 'archive' ? self::now() : null,
                'updated_by_member_id' => $current->memberId,'update_time' => time()]);
        }
        $result = $this->summary($doc) + $revisionResult + ['operation_id' => $plan->data['operation_id'],'phase' => 'completed',
            'lease_epoch' => (int) $doc->lease_epoch,'binary_sha256' => $state->state_sha256,
            'store_id' => $proof->storeId,'target_progress' => $proof->targetProgress];
        $hash = self::inputHash($result);
        $operation->save(['phase' => 'completed','result_json' => json_encode($result, JSON_THROW_ON_ERROR),'business_sha256' => $hash]);
        $this->audit->appendTenantMember(
            $current,
            'document.' . $action,
            'document.' . $action,
            'document',
            (string) $doc->id,
            null,
            null,
            1,
            $result['digest'] ?? $result['binary_sha256'],
            ['state_seq' => (int) $doc->state_seq,'operation_id' => $plan->data['operation_id'],'barrier_key' => $plan->data['barrier_key']],
        );
        $this->acceptedWrites->requireProvider()->consumeBusinessBarrier($proof, new DocumentBusinessReceipt($plan, $result));
        $this->releaseFencedCollaborationReferences((int) $doc->id, DocumentAuthorizationService::reference($doc), $plan->data['lease_epoch']);
        return $result;
    }

    private function releaseBusiness(TenantContext $context, BusinessBarrierPlan $plan): array
    {
        $action = str_starts_with($plan->data['action'], 'version.') ? 'version.create' : $plan->data['action'];
        $result = $this->businessTransaction($context, $plan->owner, $action, function (TenantContext $current) use ($plan): array {
            $doc = $this->authorization->document($current, $plan->data['binding']['document_id'], str_starts_with($plan->data['action'], 'version.') ? 'version.create' : $plan->data['action'], true);
            $operation = DocumentOperation::where('document_id', $doc->id)->where('operation_id', $plan->data['operation_id'])->lock(true)->find();
            if ($operation === null || $operation->phase !== 'completed' || $operation->input_sha256 !== $plan->data['input_sha256']) {
                throw BusinessException::conflict('DOCUMENT_BUSINESS_PENDING', '业务操作尚未完成，继续原操作');
            }
            $this->assertBusinessActor($current, $operation);
            return ['result' => self::json($operation->result_json),'released' => $operation->released_at !== null];
        });
        if ($result['released']) {
            return $result['result'];
        }
        $result = $result['result'];
        $this->acceptedWrites->requireProvider()->releaseBusinessBarrier($plan, new DocumentBusinessReceipt($plan, $result));
        $this->businessTransaction($context, $plan->owner, $action, function (TenantContext $current) use ($plan): void {
            $this->authorization->owner($current, $plan->owner, str_starts_with($plan->data['action'], 'version.') ? 'version.create' : $plan->data['action'], true);
            Document::where('id', $plan->data['binding']['document_id'])->lock(true)->find();
            $operation = DocumentOperation::where('document_id', $plan->data['binding']['document_id'])->where('operation_id', $plan->data['operation_id'])->lock(true)->find();
            $this->assertBusinessActor($current, $operation);
            $operation->save(['released_at' => self::now()]);
        });
        return $result;
    }

    public function businessOperation(TenantContext $context, int $documentId, string $operationId): array
    {
        self::requestKey($operationId);
        $doc = Document::where('id', $documentId)->find();
        $op = DocumentOperation::where('document_id', $documentId)->where('operation_id', $operationId)->find();
        if ($doc === null || $op === null || $op->barrier_plan_json === null && $op->action !== 'version.create') {
            throw BusinessException::notFound('DOCUMENT_OPERATION_NOT_FOUND', '业务操作不存在');
        }
        $owner = DocumentAuthorizationService::reference($doc);
        $state = $this->owners->requirePolicy($owner)->resolveOwner($context, $owner);
        $action = str_starts_with($op->action, 'version.') ? 'version.create' : $op->action;
        if (($action === 'archive' && !$state->archived) || ($action === 'restore' && $state->archived)) {
            $action .= '.prepare';
        }
        return $this->businessTransaction($context, $owner, $action, function (TenantContext $current) use ($documentId, $operationId, $owner, $action): array {
            $this->authorization->owner($current, $owner, $action, true);
            $doc = Document::where('id', $documentId)->lock(true)->find();
            if ($doc === null || DocumentAuthorizationService::reference($doc) != $owner) {
                throw BusinessException::conflict('DOCUMENT_OPERATION_BINDING_CHANGED', '操作归属已改变');
            }
            $op = DocumentOperation::where('document_id', $documentId)->where('operation_id', $operationId)->lock(true)->find();
            if ($op->action === 'version.create' && $op->barrier_plan_json === null) {
                $target = DocumentOperation::where('document_id', $documentId)->where('operation_id', 'target:' . hash('sha256', $operationId))->lock(true)->find();
                if ($target === null) {
                    throw BusinessException::notFound('DOCUMENT_OPERATION_NOT_FOUND', '点击版本操作不存在');
                }
                $this->assertBusinessActor($current, $target);
            } else {
                $this->assertBusinessActor($current, $op);
            }
            $result = $op->phase === 'completed' ? self::json($op->result_json) : null;
            return ['document_id' => $documentId,'operation_id' => $operationId,'action' => $op->action,'input_sha256' => $op->input_sha256,
                'phase' => $op->phase,'target_progress' => $result['target_progress'] ?? null,'store_id' => $result['store_id'] ?? null,
                'state_seq' => $result['state_seq'] ?? null,'binary_sha256' => $result['binary_sha256'] ?? null,
                'released' => $op->barrier_plan_json === null || $op->released_at !== null,'result' => $result];
        });
    }

    public function explicitTarget(TenantContext $context, int $documentId, string $requestId): array
    {
        self::requestKey($requestId);
        return $this->authorization->run($context, fn(TenantContext $current): array => Db::transaction(function () use ($current, $documentId, $requestId): array {
            $this->authorization->document($current, $documentId, 'version.create', true);
            $target = DocumentOperation::where('document_id', $documentId)->where('operation_id', 'target:' . hash('sha256', $requestId))->lock(true)->find();
            if ($target === null) {
                return ['document_id' => $documentId,'request_id' => $requestId,'phase' => 'not_prepared',
                    'target_binary_sha256' => null,'target_projection_sha256' => null,'target_progress' => null,
                    'store_id' => null,'state_seq' => null,'committed_binary_sha256' => null,'revision_id' => null];
            }
            if ($target->action !== 'collaboration.target') {
                throw BusinessException::notFound('DOCUMENT_TARGET_NOT_FOUND', '点击目标不存在');
            }
            $this->assertBusinessActor($current, $target);
            $command = self::json($target->command_input_json);
            $frozen = $command['frozen'] ?? [];
            $proof = self::json($target->result_json);
            return ['document_id' => $documentId,'request_id' => $requestId,
                'phase' => ($command['canceled'] ?? false) ? 'canceled' : ($target->phase === 'completed' ? 'consumed' : (isset($command['confirmed']) ? 'confirmed' : 'prepared')),
                'target_binary_sha256' => $frozen['target_binary_sha256'] ?? null,'target_projection_sha256' => $frozen['target_projection_sha256'] ?? null,
                'target_progress' => $frozen['target_progress'] ?? null,'store_id' => $proof['store_id'] ?? null,
                'state_seq' => $proof['state_seq'] ?? null,'committed_binary_sha256' => $proof['committed_binary_sha256'] ?? null,
                'revision_id' => $command['revision_id'] ?? null];
        }));
    }

    public function versions(TenantContext $context, int $documentId, int $limit = 20, ?int $beforeRevisionNo = null): array
    {
        if ($documentId < 1 || $limit < 1 || $limit > 100 || ($beforeRevisionNo !== null && $beforeRevisionNo < 1)) {
            throw BusinessException::invalid('DOCUMENT_INPUT_INVALID', '历史分页参数无效');
        }
        if (($pdo = Db::connect()->getPdo()) instanceof \PDO && $pdo->inTransaction()) {
            throw BusinessException::conflict('DOCUMENT_VERSION_NOT_COMMITTED', '不可把外层事务中的版本声明为已提交');
        }
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $limit, $beforeRevisionNo): array {
            $document = $this->authorization->document($current, $documentId, 'view', false);
            $query = DocumentRevision::where('tenant_id', $current->tenantId)->where('document_id', $document->id)
                ->field('id,revision_no,created_at,source_state_seq,content_kind');
            if ($beforeRevisionNo !== null) {
                $query->where('revision_no', '<', $beforeRevisionNo);
            }
            $rows = $query->order('revision_no', 'desc')->limit($limit + 1)->select()->toArray();
            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }
            $items = array_map(static fn(array $row): array => [
                'revision_id' => (int) $row['id'], 'revision_no' => (int) $row['revision_no'],
                'created_at' => $row['created_at'], 'source_state_seq' => (int) $row['source_state_seq'],
                'content_kind' => $row['content_kind'],
            ], $rows);
            return [
                'document_id' => (int) $document->id, 'owner' => self::ownerArray(DocumentAuthorizationService::reference($document)),
                'items' => $items, 'next_before_revision_no' => $hasMore ? $items[array_key_last($items)]['revision_no'] : null,
            ];
        });
    }

    public function detail(TenantContext $context, int $documentId, ?int $revisionId = null): array
    {
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $revisionId): array {
            $document = $this->authorization->document($current, $documentId, 'view');
            $result = $this->summary($document);
            $result['owner'] = ['module_key' => $document->owner_module_key,'object_type' => $document->owner_object_type,'object_id' => $document->owner_object_id,'slot' => $document->slot];
            $result['authorization_revision'] = $this->authorization->revision($current, $document);
            if ($revisionId !== null) {
                return $result + $this->versionSnapshot($current, $documentId, $revisionId)->toArray();
            }
            if ($document->content_kind === 'rich_text') {
                $state = DocumentState::where('document_id', $documentId)->find();
                if ($state === null) {
                    throw BusinessException::conflict('DOCUMENT_STATE_MISSING', '文档正文不可用');
                }
                return $result + ['structured_content' => self::json($state->projected_json),'digest' => $state->state_sha256,'persisted_at' => $state->persisted_at];
            }
            $binding = $this->binding($document);
            return $result + ['file_key' => $binding['primary_file_key'],'verified_mime_type' => $binding['verified_mime_type'],'digest' => $binding['source_sha256']];
        });
    }

    public function runOwnerVersionSave(TenantContext $context, DocumentOwnerReference $owner, callable $commitOwner): mixed
    {
        return $this->businessTransaction($context, $owner, 'version.create', function (TenantContext $current) use ($owner, $commitOwner): mixed {
            $state = $this->authorization->owner($current, $owner, 'version.create', true);
            $pdo = Db::connect()->getPdo();
            $result = $commitOwner($current, $state);
            if ($pdo !== Db::connect()->getPdo() || !$pdo->inTransaction()) {
                throw new \LogicException('DOCUMENT_OWNER_TRANSACTION_CHANGED');
            }
            return $result;
        });
    }

    public function ownerEditor(TenantContext $context, int $documentId, DocumentOwnerReference $owner): array
    {
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $owner): array {
            $doc = $this->authorization->document($current, $documentId, 'edit');
            if (DocumentAuthorizationService::reference($doc) != $owner || $doc->content_kind !== 'rich_text') {
                throw BusinessException::forbidden('DOCUMENT_OWNER_MISMATCH', '正文编辑归属不一致');
            }
            $state = DocumentState::where('document_id', $documentId)->find();
            if ($state === null || (int) $state->state_seq !== (int) $doc->state_seq) {
                throw BusinessException::conflict('DOCUMENT_STATE_MISSING', '正文持久水位不一致');
            }
            $revision = $this->authorization->revision($current, $doc, 'edit');
            $modules = [];
            foreach (['peanut.content-tools','peanut.rich-text'] as $key) {
                try {
                    $this->authorization->requireModule($current, $key);
                    $modules[] = $key;
                } catch (\PeanutAdmin\Kernel\Module\ModuleException) {
                }
            }
            $authorized = new AuthorizedContentContext('rich_text', 'application/vnd.peanut.richtext+json', true, true, false, $revision, 'browser', $modules, []);
            return $this->summary($doc) + ['owner' => self::ownerArray($owner),'authorization_revision' => $revision,
                'collaboration_enabled' => (bool) $doc->collaboration_enabled,'structured_content' => self::json($state->projected_json),
                'digest' => $state->state_sha256,'persisted_at' => $state->persisted_at,'can_edit' => true,'can_download_original' => false,
                'capabilities' => $this->tools->resolve($authorized, 'edit', null, null)];
        });
    }

    public function publicationBody(DocumentOwnerReference $owner): array
    {
        $execution = $this->execution->current();
        $tenantId = $execution?->tenantId();
        if (!($execution instanceof AdminExecutionContext || $execution instanceof \app\common\execution\ConsumerExecutionContext)
            || $tenantId === null || $tenantId < 1) {
            throw BusinessException::forbidden('DOCUMENT_PUBLICATION_CONTEXT_REQUIRED', '固定正文须使用可信租户公开入口');
        }
        $guard = new \PeanutAdmin\Kernel\Module\ModuleGuard($this->modules);
        foreach (['peanut.document', 'peanut.rich-text', $owner->moduleKey] as $module) {
            $guard->assertDeployment($module);
            $guard->assertTenant($tenantId, $module, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }
        return Db::transaction(function () use ($tenantId, $owner): array {
            $policy = $this->owners->requirePolicy($owner);
            if (!$policy instanceof \PeanutAdmin\Modules\Document\Contract\DocumentPublicationOwnerPolicy) {
                throw BusinessException::forbidden('DOCUMENT_PUBLICATION_UNSUPPORTED', '此业务未授权固定正文公开读取');
            }
            // The business owner supplies the binding under its current publication/lifecycle lock.
            $binding = $policy->publishedBinding($tenantId, $owner);
            $document = Document::where('id', $binding['document_id'])->where('owner_module_key', $owner->moduleKey)
                ->where('owner_object_type', $owner->objectType)->where('owner_object_id', $owner->objectId)->where('slot', $owner->slot)
                ->where('status', 'active')->where('content_kind', 'rich_text')->lock(true)->find();
            if ($document === null) {
                throw BusinessException::notFound('DOCUMENT_PUBLICATION_UNAVAILABLE', '固定正文不可用');
            }
            $revision = $this->revision($document, $binding['revision_id']);
            $snapshot = $this->snapshotFromRevision($tenantId, $document, $revision);
            if ($snapshot->contentKind !== 'rich_text' || $snapshot->structuredContent === null || $snapshot->binding() !== $binding) {
                throw BusinessException::conflict('DOCUMENT_PUBLICATION_BINDING_CHANGED', '正式保存的正文固定版本不一致');
            }
            // Internal projection only: no Yjs/history metadata or File delivery authority.
            // The Article public renderer must omit File nodes instead of exposing their internal keys.
            return $snapshot->structuredContent;
        });
    }

    public function ownerSaveSnapshot(TenantContext $context, int $documentId, int $revisionId, DocumentOwnerReference $owner): VersionSnapshot
    {
        return $this->prepareVersion($context, $documentId, $revisionId, 'version.create', $owner);
    }

    public function versionSnapshot(TenantContext $context, int $documentId, int $revisionId): VersionSnapshot
    {
        return $this->prepareVersion($context, $documentId, $revisionId, 'view', null);
    }

    private function prepareVersion(TenantContext $context, int $documentId, int $revisionId, string $action, ?DocumentOwnerReference $expectedOwner): VersionSnapshot
    {
        // Capture the external lifetime before DocAuth creates its transient nested run.
        $root = $this->execution->current() instanceof AdminExecutionContext ? $this->execution->rootIdentity() : null;
        if (($pdo = Db::connect()->getPdo()) instanceof \PDO && $pdo->inTransaction()) {
            throw BusinessException::conflict('DOCUMENT_VERSION_NOT_COMMITTED', '不可把外层事务中的版本声明为已提交');
        }
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $revisionId, $root, $action, $expectedOwner) {
            $document = $this->authorization->document($current, $documentId, $action);
            if ($expectedOwner !== null && DocumentAuthorizationService::reference($document) != $expectedOwner) {
                throw BusinessException::forbidden('DOCUMENT_OWNER_MISMATCH', '保存版本业务归属不一致');
            }
            $revision = $this->revision($document, $revisionId);
            $snapshot = $this->snapshotFromRevision($current, $document, $revision);
            if ($root !== null && $root === $this->execution->rootIdentity()) {
                $this->preparedVersions[$snapshot] = [
                    'root' => $root, 'actor' => self::preparedActor($current), 'owner' => DocumentAuthorizationService::reference($document), 'action' => $action,
                    'canonical' => self::snapshotHash($snapshot), 'expires_at' => hrtime(true) + self::PREPARED_LIFETIME_NANOSECONDS,
                ];
            }
            return $snapshot;
        });
    }

    public function verifyPreparedVersion(TenantContext $context, VersionSnapshot $snapshot, DocumentOwnerReference $expectedOwner): void
    {
        $proof = $this->preparedVersions[$snapshot] ?? null;
        if ($proof === null || !$this->execution->current() instanceof AdminExecutionContext
            || $proof['root'] !== $this->execution->rootIdentity() || hrtime(true) >= $proof['expires_at']
            || $proof['actor'] !== self::preparedActor($context) || $proof['owner'] != $expectedOwner
            || !hash_equals($proof['canonical'], self::snapshotHash($snapshot))) {
            throw BusinessException::forbidden('DOCUMENT_PREPARED_VERSION_DENIED', '文档版本准备证明无效或已过期');
        }
        if (!(($pdo = Db::connect()->getPdo()) instanceof \PDO) || !$pdo->inTransaction()) {
            throw BusinessException::conflict('DOCUMENT_PREPARED_TRANSACTION_REQUIRED', '版本复核必须在业务外层事务内');
        }
        $this->authorization->run($context, function (TenantContext $current) use ($snapshot, $expectedOwner, $proof): void {
            $document = $this->authorization->document($current, $snapshot->documentId, $proof['action'], true);
            if ($proof['action'] === 'version.create' && !(bool) $document->collaboration_enabled && (int) $document->state_seq !== $snapshot->sourceStateSeq) {
                throw BusinessException::conflict('DOCUMENT_SAVE_STATE_CHANGED', '版本确认后正文水位已改变');
            }
            if (DocumentAuthorizationService::reference($document) != $expectedOwner || $proof['actor'] !== self::preparedActor($current)) {
                throw BusinessException::forbidden('DOCUMENT_PREPARED_VERSION_DENIED', '文档版本业务归属或身份已改变');
            }
            $revision = DocumentRevision::where('document_id', $document->id)->where('id', $snapshot->revisionId)->lock(true)->find();
            if ($revision === null || !hash_equals($proof['canonical'], self::snapshotHash($this->snapshotFromRevision($current, $document, $revision)))) {
                throw BusinessException::conflict('DOCUMENT_PREPARED_VERSION_CHANGED', '不可变文档版本与准备结果不一致');
            }
            $keys = array_values(array_unique(array_column($snapshot->references, 'file_key')));
            $this->references->assertDocumentReferencesReadable($current, $snapshot->documentId, $keys);
            if ($snapshot->contentKind === 'file_resource') {
                $this->snapshots->assertDocumentSnapshotReadable($current, $snapshot->documentId, $snapshot->fileKey, $snapshot->fileSha256, $snapshot->verifiedMimeType);
            }
        });
    }

    private function snapshotFromRevision(TenantContext|int $context, Document $document, DocumentRevision $revision): VersionSnapshot
    {
        $projection = $revision->content_snapshot_json === null ? null : RichTextProjection::fromStructured(self::json($revision->content_snapshot_json));
        $content = $projection?->json;
        if (!in_array($revision->content_kind, ['rich_text', 'file_resource'], true)
            || ($revision->content_kind === 'rich_text' && ($content === null || $revision->immutable_file_key !== null || $revision->immutable_file_sha256 !== null || $revision->immutable_file_mime_type !== null))
            || ($revision->content_kind === 'file_resource' && ($content !== null || !is_string($revision->immutable_file_key)
                || preg_match('/^file_[0-9a-f]{32}$/D', $revision->immutable_file_key) !== 1 || !is_string($revision->immutable_file_sha256)
                || preg_match('/^[0-9a-f]{64}$/D', $revision->immutable_file_sha256) !== 1 || !is_string($revision->immutable_file_mime_type) || $revision->immutable_file_mime_type === ''))) {
            throw BusinessException::conflict('DOCUMENT_VERSION_DIGEST_INVALID', '不可变文档版本字段不一致');
        }
        $digest = hash('sha256', json_encode([(string) $revision->content_kind, (int) $revision->source_state_seq, $content,
            $revision->yjs_snapshot === null ? null : hash('sha256', $revision->yjs_snapshot), $revision->immutable_file_key, $revision->immutable_file_sha256], JSON_THROW_ON_ERROR));
        if (!hash_equals((string) $revision->snapshot_sha256, $digest)) {
            throw BusinessException::conflict('DOCUMENT_VERSION_DIGEST_INVALID', '不可变文档版本摘要不一致');
        }
        return new VersionSnapshot(
            $context instanceof TenantContext ? $context->tenantId : $context,
            (int) $document->id,
            (int) $revision->id,
            (int) $revision->revision_no,
            (int) $revision->source_state_seq,
            (string) $revision->content_kind,
            $digest,
            $projection === null ? null : self::json($projection->json),
            $revision->immutable_file_key,
            $revision->immutable_file_sha256,
            $revision->immutable_file_mime_type,
            $projection?->fileReferences ?? [['file_key' => (string) $revision->immutable_file_key, 'variant_key' => null]],
        );
    }

    private static function snapshotHash(VersionSnapshot $snapshot): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($snapshot->toArray()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function preparedActor(TenantContext $context): array
    {
        return [$context->tenantId, $context->accountId, $context->memberId, $context->sessionKey,
            $context->clientKey, $context->requestId, $context->authorizationRevision];
    }

    public function capabilities(TenantContext $context, int $documentId, string $intent, ?int $revisionId = null): array
    {
        if (!in_array($intent, ['view','edit'], true)) {
            throw BusinessException::invalid('DOCUMENT_INTENT_INVALID', '内容意图无效');
        }
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $intent, $revisionId): array {
            $data = $this->detail($current, $documentId, $revisionId);
            $document = $this->authorization->document($current, $documentId, 'view');
            $canEdit = false;
            $canDownload = false;
            if ($revisionId === null && !(bool) $document->collaboration_enabled) {
                try {
                    $this->authorization->document($current, $documentId, 'edit');
                    $canEdit = true;
                } catch (BusinessException) {
                }
            }
            if ($document->content_kind === 'file_resource') {
                try {
                    $this->files->requireOriginalDownloadPermission($current->tenantId);
                    $canDownload = true;
                } catch (BusinessException) {
                }
            }
            $modules = [];
            foreach (['peanut.content-tools','peanut.rich-text'] as $key) {
                try {
                    $this->authorization->requireModule($current, $key);
                    $modules[] = $key;
                } catch (\PeanutAdmin\Kernel\Module\ModuleException) {
                }
            }
            $authorized = new AuthorizedContentContext(
                (string) $document->content_kind,
                $data['verified_mime_type'] ?? 'application/vnd.peanut.richtext+json',
                true,
                $canEdit,
                $canDownload,
                $data['authorization_revision'],
                'browser',
                $modules,
                [],
            );
            return ['document_id' => $documentId,'can_view' => true,'can_edit' => $canEdit,'can_download_original' => $canDownload,
                'collaboration_enabled' => (bool) $document->collaboration_enabled,'capabilities' => $this->tools->resolve($authorized, $intent, null, null)];
        });
    }

    public function authorizePreview(TenantContext $context, int $documentId, string $fileKey): array
    {
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $fileKey): array {
            $document = $this->authorization->document($current, $documentId, 'view');
            if (DocumentFileReference::where('document_id', $documentId)->where('file_key', $fileKey)->find() === null) {
                throw BusinessException::forbidden('DOCUMENT_FILE_REFERENCE_DENIED', '资源不在当前获准文档引用中');
            }
            return ['authorization_revision' => $this->authorization->revision($current, $document),'reference_digest' => $this->referenceDigest($document),'inherit_preview' => true];
        });
    }
    public function referencesForUpdate(TenantContext $context, int $documentId): array
    {
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId): array {
            $document = $this->authorization->document($current, $documentId, 'edit', true);
            $keys = DocumentFileReference::where('document_id', $documentId)->lock(true)->order('file_key')->column('file_key');
            $keys = array_values(array_unique($keys));
            return ['authorization_revision' => $this->authorization->revision($current, $document, 'edit'),'file_keys' => $keys,'reference_digest' => $this->referenceDigest($document)];
        });
    }

    public function collaborationReferencesForUpdate(TenantContext $context, CollaborationReferenceInput $input): array
    {
        $this->requireCollaborationTransaction($context->tenantId, false);
        return $this->authorization->run($context, function (TenantContext $current) use ($input): array {
            if ($input->tenantId !== $current->tenantId) {
                throw BusinessException::forbidden('DOCUMENT_CONTEXT_MISMATCH', '资源接纳与当前租户不一致');
            }
            $document = $this->authorization->document($current, $input->documentId, 'edit', true);
            $this->authorization->requireModule($current, 'peanut.collaboration');
            if ($document->content_kind !== 'rich_text' || $document->status !== 'active' || !(bool) $document->collaboration_enabled) {
                throw BusinessException::forbidden('DOCUMENT_COLLABORATION_KIND_INVALID', '当前文档不允许接纳资源引用');
            }
            $revision = $this->authorization->revision($current, $document, 'edit');
            $this->acceptedWrites->requireProvider()->assertReferenceReservation(
                $current,
                $input,
                DocumentAuthorizationService::reference($document),
                $revision,
            );
            $keys = DocumentFileReference::where('document_id', $input->documentId)->where('usage', 'current')->order('file_key')->column('file_key');
            return ['authorization_revision' => $revision,
                'current_file_keys' => array_values(array_unique($keys))];
        });
    }

    public function authorizeCollaboration(TenantContext $context, int $documentId, string $mode, bool $enable = false): array
    {
        if (!in_array($mode, ['read','write'], true) || $enable && $mode !== 'write') {
            throw BusinessException::invalid('DOCUMENT_COLLABORATION_MODE_INVALID', '协作模式无效');
        }
        $this->requireCollaborationTransaction($context->tenantId, false);
        $action = $mode === 'write' ? 'edit' : 'view';
        $grant = $this->authorization->prepareGrant($context, $documentId, $action);
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $mode, $enable, $action, $grant): array {
            $this->authorization->requireModule($current, 'peanut.collaboration');
            $document = $this->authorization->document($current, $documentId, $mode === 'write' ? 'edit' : 'view', true);
            if ($document->content_kind !== 'rich_text' || $document->status !== 'active') {
                throw BusinessException::conflict('DOCUMENT_COLLABORATION_KIND_INVALID', '当前文档不允许协作');
            }
            $state = DocumentState::where('document_id', $documentId)->lock(true)->find();
            if ($state === null || (int) $state->state_seq !== (int) $document->state_seq) {
                throw BusinessException::conflict('RECOVERY_REQUIRED', '文档持久状态不完整');
            }
            if ($enable && !(bool) $document->collaboration_enabled) {
                $document->save(['collaboration_enabled' => 1,'lease_epoch' => max(1, (int) $document->lease_epoch)]);
            }
            return ['document_id' => $documentId,'tenant_id' => $current->tenantId,
                'owner' => self::ownerArray(DocumentAuthorizationService::reference($document)),
                'authorization_revision' => $this->authorization->revision($current, $document, $action, $grant),
                'document_auth_revision' => (int) $document->auth_revision,'state_seq' => (int) $document->state_seq,
                'state_sha256' => $state->state_sha256,'lease_epoch' => (int) $document->lease_epoch,
                'collaboration_enabled' => (bool) $document->collaboration_enabled];
        });
    }

    public function compareRevocationGrant(TenantContext $identity, int $documentId, string $mode, string $issuedRevision, string $factId, string $factHash): array
    {
        return $this->authorization->compareRevocationGrant($identity, $documentId, $mode, $issuedRevision, $factId, $factHash);
    }

    public function loadAuthorizedCollaboration(TenantContext $context, int $documentId, DocumentOwnerReference $owner, string $mode): array
    {
        if (!in_array($mode, ['read','write'], true)) {
            throw BusinessException::invalid('DOCUMENT_COLLABORATION_MODE_INVALID', '协作模式无效');
        }
        $this->requireCollaborationTransaction($context->tenantId, false);
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $owner, $mode): array {
            $document = $this->authorization->document($current, $documentId, $mode === 'write' ? 'edit' : 'view', true);
            if (DocumentAuthorizationService::reference($document) != $owner || $document->content_kind !== 'rich_text'
                || !(bool) $document->collaboration_enabled) {
                throw BusinessException::forbidden('COLLABORATION_DOCUMENT_BINDING_INVALID', '协作读取与当前文档不一致');
            }
            $state = DocumentState::where('document_id', $documentId)->lock(true)->find();
            $this->acceptedWrites->requireProvider()->assertServiceDocument($documentId, $owner);
            if ($state === null || (int) $state->state_seq !== (int) $document->state_seq) {
                throw BusinessException::conflict('RECOVERY_REQUIRED', '文档持久状态不完整');
            }
            return ['document_id' => $documentId,'state_seq' => (int) $state->state_seq,'state_sha256' => $state->state_sha256,
                'binary_base64' => $state->yjs_state_binary === null ? null : base64_encode($state->yjs_state_binary),
                'initialization_required' => $state->yjs_state_binary === null,
                'structured_content' => self::json($state->projected_json),'schema_version' => $state->projection_schema_version,
                'lease_epoch' => (int) $document->lease_epoch,'store_id' => $this->baselineReceipt($document, $state)];
        });
    }

    public function recoverCollaborationEpoch(int $documentId, DocumentOwnerReference $owner, int $expectedEpoch, string $requestId): array
    {
        return $this->advanceCollaborationEpoch($documentId, $owner, $expectedEpoch, $requestId, false);
    }

    public function migrateCollaborationEpoch(int $documentId, DocumentOwnerReference $owner, int $expectedEpoch, string $requestId): array
    {
        if (!$this->execution->current() instanceof \app\common\execution\SystemExecutionContext
            || $this->execution->current()->operation() !== 'collaboration.native-migration') {
            throw BusinessException::forbidden('DOCUMENT_NATIVE_MIGRATION_CONTEXT_INVALID', '迁移只接受显式原生owner入口建立的技术执行身份');
        }
        return $this->advanceCollaborationEpoch($documentId, $owner, $expectedEpoch, $requestId, true);
    }

    private function advanceCollaborationEpoch(int $documentId, DocumentOwnerReference $owner, int $expectedEpoch, string $requestId, bool $migration): array
    {
        $tenant = $this->execution->current()?->tenantId() ?? 0;
        $this->requireCollaborationTransaction($tenant, true);
        // Technical recovery preserves archived ownership as well as active ownership;
        // it never grants a human join/edit capability or restores an archived document.
        foreach (['peanut.document','peanut.file','peanut.collaboration',$owner->moduleKey] as $module) {
            $this->authorization->requireDeploymentModule($module);
        }
        $ownerState = $this->owners->requirePolicy($owner)->resolvePersistedOwner($tenant, $owner, true);
        $document = Document::where('id', $documentId)->lock(true)->find();
        if ($document === null || $ownerState->tenantId !== $tenant || $ownerState->reference != $owner
            || DocumentAuthorizationService::reference($document) != $owner || $document->content_kind !== 'rich_text'
            || !(bool) $document->collaboration_enabled || !in_array($document->status, ['active','archived'], true)
            || $ownerState->archived !== ($document->status === 'archived')) {
            throw BusinessException::conflict('DOCUMENT_RECOVERY_BINDING_INVALID', '原文档归属或归档事实不一致');
        }
        $state = DocumentState::where('document_id', $documentId)->lock(true)->find();
        self::requestKey($requestId);
        $key = ($migration ? 'migration:' : 'recovery:') . hash('sha256', $requestId);
        $action = $migration ? 'collaboration.migrate' : 'collaboration.recover';
        $hash = self::inputHash([$expectedEpoch,$requestId,self::ownerArray($owner),$migration]);
        $existing = DocumentOperation::where('document_id', $documentId)->where('operation_id', $key)->lock(true)->find();
        if ($existing !== null) {
            if ($existing->action !== $action || $existing->input_sha256 !== $hash) {
                throw BusinessException::conflict('DOCUMENT_RECOVERY_IDEMPOTENCY_CONFLICT', '恢复请求不能改变原代际');
            }
            return self::json($existing->result_json);
        }
        if ((int) $document->lease_epoch !== $expectedEpoch || $state === null
            || (int) $state->state_seq !== (int) $document->state_seq
            || DocumentOperation::where('document_id', $documentId)->where('action', '<>', 'collaboration.target')
                ->where('phase', '<>', 'completed')->lock(true)->find() !== null) {
            throw BusinessException::conflict('DOCUMENT_RECOVERY_PENDING', '原业务状态未确认，不允许恢复自动解锁');
        }
        $document->save(['lease_epoch' => $expectedEpoch + 1]);
        $result = ['document_id' => $documentId,'lease_epoch' => $expectedEpoch + 1,'state_seq' => (int) $state->state_seq,
            'state_sha256' => $state->state_sha256,'binary_base64' => $state->yjs_state_binary === null ? null : base64_encode($state->yjs_state_binary),
            'structured_content' => self::json($state->projected_json),'initialization_required' => $state->yjs_state_binary === null,
            'store_id' => $this->baselineReceipt($document, $state),'status' => $document->status];
        DocumentOperation::create(['tenant_id' => $tenant,'document_id' => $documentId,'operation_id' => $key,
            'action' => $action,'input_sha256' => $hash,'result_json' => json_encode($result, JSON_THROW_ON_ERROR),'created_at' => self::now()]);
        return $result;
    }

    public function releaseFencedCollaborationReferences(int $documentId, DocumentOwnerReference $owner, int $oldEpoch): void
    {
        $current = $this->execution->current();
        $tenant = $current?->tenantId() ?? 0;
        if ($tenant < 1 || (!$current instanceof AdminExecutionContext && !$current instanceof \app\common\execution\SystemExecutionContext)
            || !Db::connect()->getPdo()->inTransaction()) {
            throw BusinessException::forbidden('DOCUMENT_FENCE_CONTEXT_INVALID', '引用收口需要真实同一事务身份');
        }
        $ownerState = $this->owners->requirePolicy($owner)->resolvePersistedOwner($tenant, $owner, true);
        $document = Document::where('id', $documentId)->lock(true)->find();
        if ($document === null || $ownerState->tenantId !== $tenant || $ownerState->reference != $owner
            || DocumentAuthorizationService::reference($document) != $owner || (int) $document->lease_epoch <= $oldEpoch) {
            throw BusinessException::forbidden('DOCUMENT_FENCE_BINDING_INVALID', '旧引用代际尚未被真实文档隔离');
        }
        $provider = $this->acceptedWrites->requireProvider();
        $reservations = $provider->fencedReferenceReservations($documentId, $owner, $oldEpoch);
        $hash = self::inputHash([$documentId,$oldEpoch,$reservations]);
        $input = new ReservationReleaseInput($tenant, $documentId, $hash);
        $this->acceptedReleases[$input] = ['scope' => $this->execution->rootIdentity(),'pdo' => Db::connect()->getPdo(),
            'hash' => $hash,'reservations' => $reservations];
        $this->references->releaseSettledCollaborationReferences($input);
        $provider->retireFencedReferences($documentId, $owner, $oldEpoch);
    }

    public function prepareExplicitTarget(TenantContext $context, int $documentId, DocumentOwnerReference $owner, ExplicitTargetPreparationInput $input): array
    {
        $this->requireCollaborationTransaction($context->tenantId, false);
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $owner, $input): array {
            $document = $this->authorization->document($current, $documentId, 'version.create', true);
            $this->explicitTargetDocument($document, $owner);
            $reservations = $this->acceptedWrites->requireProvider()->targetReferenceReservations($current, $documentId, $owner);
            $key = $input->operationKey();
            $old = DocumentOperation::where('document_id', $documentId)->where('operation_id', $key)->lock(true)->find();
            if ($old !== null) {
                $this->assertBusinessActor($current, $old);
                if ($old->action !== 'collaboration.target') {
                    throw BusinessException::conflict('DOCUMENT_TARGET_IDEMPOTENCY_CONFLICT', '点击目标不可改变');
                }
                $original = self::json($old->command_input_json);
                if (($original['canceled'] ?? false) === true) {
                    throw BusinessException::conflict('DOCUMENT_TARGET_CANCELED', '已明确放弃的目标不能重新准备');
                }
                if ($old->input_sha256 !== $input->hash()) {
                    throw BusinessException::conflict('DOCUMENT_TARGET_IDEMPOTENCY_CONFLICT', '点击目标不可改变');
                }
                if (($original['document_auth_revision'] ?? null) !== (int) $document->auth_revision || RichTextProjection::canonical($original['owner'] ?? null) !== RichTextProjection::canonical(self::ownerArray($owner))) {
                    throw BusinessException::conflict('DOCUMENT_TARGET_BINDING_CHANGED', '点击目标业务授权或归属已改变');
                }
                return $original['prepared_result'];
            }
            if (DocumentOperation::where('document_id', $documentId)->where('action', 'collaboration.target')->where('phase', 'prepared')->count() >= 16) {
                throw BusinessException::conflict('DOCUMENT_TARGET_BACKPRESSURE', '待处理点击目标过多，须先完成原保存');
            }
            $result = ['document_id' => $documentId,'request_id' => $input->tuple['request_id'],
                'target_binary_sha256' => $input->tuple['target_binary_sha256'],'target_projection_sha256' => $input->tuple['target_projection_sha256'],
                'target_progress' => $input->tuple['target_progress']];
            $command = ['frozen' => $input->tuple,'lease_epoch' => (int) $document->lease_epoch,
                'document_auth_revision' => (int) $document->auth_revision,'owner' => self::ownerArray($owner),'prepared_result' => $result];
            DocumentOperation::create(['tenant_id' => $current->tenantId,'document_id' => $documentId,'operation_id' => $key,
                'action' => 'collaboration.target','input_sha256' => $input->hash(),'result_json' => '{}','phase' => 'prepared',
                'initiator_json' => json_encode(self::businessActor($current), JSON_THROW_ON_ERROR),
                'command_input_json' => json_encode($command, JSON_THROW_ON_ERROR),'created_at' => self::now()]);
            $fileInput = $this->targetFileInput($current, $documentId, $input);
            $this->targetTransfers[$fileInput] = ['root' => $this->execution->rootIdentity(),'pdo' => Db::connect()->getPdo(),
                'consume' => false,'document' => $document,'reservations' => $reservations];
            $this->references->reserveExplicitTargetReferences($current, $fileInput);
            return $result;
        });
    }

    public function registerExplicitTarget(TenantContext $context, int $documentId, DocumentOwnerReference $owner, ExplicitTargetInput $input): array
    {
        $this->requireCollaborationTransaction($context->tenantId, false);
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $owner, $input): array {
            $document = $this->authorization->document($current, $documentId, 'version.create', true);
            $this->explicitTargetDocument($document, $owner);
            $this->acceptedWrites->requireProvider()->targetReferenceReservations($current, $documentId, $owner);
            $prepared = DocumentOperation::where('document_id', $documentId)->where('operation_id', $input->operationKey())->lock(true)->find();
            if ($prepared === null || $prepared->action !== 'collaboration.target') {
                throw BusinessException::conflict('DOCUMENT_TARGET_NOT_PREPARED', '点击目标必须先占用真实资源');
            }
            $this->assertBusinessActor($current, $prepared);
            $command = self::json($prepared->command_input_json);
            if (($command['canceled'] ?? false) === true) {
                throw BusinessException::conflict('DOCUMENT_TARGET_CANCELED', '已明确放弃的目标不能确认');
            }
            $frozen = array_intersect_key($input->tuple, array_flip(['protocol','connection_key','request_id','target_binary_sha256',
                'target_projection_sha256','binary_base64','structured_content','projector_version','reference_digest','target_progress']));
            $preparation = new ExplicitTargetPreparationInput($frozen);
            if ($preparation->hash() !== $prepared->input_sha256 || ($command['document_auth_revision'] ?? null) !== (int) $document->auth_revision
                || RichTextProjection::canonical($command['owner'] ?? null) !== RichTextProjection::canonical(self::ownerArray($owner))) {
                throw BusinessException::conflict('DOCUMENT_TARGET_CHANGED', '确认不得改变点击目标或代际');
            }
            if (isset($command['confirmation_sha256'])) {
                if ($command['confirmation_sha256'] !== $input->hash()) {
                    throw BusinessException::conflict('DOCUMENT_TARGET_CHANGED', '已确认目标不可重新绑定提交');
                }
                return self::json($prepared->result_json);
            }
            $store = DocumentOperation::where('document_id', $documentId)->where('operation_id', $input->tuple['store_id'])->lock(true)->find();
            $receipt = $store === null ? [] : self::json($store->result_json);
            if ($store === null || !in_array($store->action, ['collaboration.store','collaboration.baseline'], true)
                || ($receipt['state_seq'] ?? null) !== $input->tuple['state_seq']
                || ($receipt['lease_epoch'] ?? null) !== $command['lease_epoch']
                || ($receipt['binary_sha256'] ?? null) !== $input->tuple['committed_binary_sha256']
                || !isset($receipt['target_progress']) || $receipt['target_progress'] < $input->tuple['target_progress']) {
                throw BusinessException::conflict('DOCUMENT_TARGET_COMMIT_UNCONFIRMED', '点击目标缺少真实已提交覆盖证明');
            }
            // Delete-aware Yjs coverage is the authenticated service's pinned-version attestation.
            // This stores an explicit immutable target only; the current draft is never rewritten.
            $result = ['document_id' => $documentId,'request_id' => $input->tuple['request_id'],'store_id' => $input->tuple['store_id'],
                'state_seq' => $input->tuple['state_seq'],'committed_binary_sha256' => $input->tuple['committed_binary_sha256'],
                'target_binary_sha256' => $input->tuple['target_binary_sha256'],'target_projection_sha256' => $input->tuple['target_projection_sha256'],
                'target_progress' => $input->tuple['target_progress']];
            $command['confirmation_sha256'] = $input->hash();
            $command['confirmed'] = $input->tuple;
            $prepared->save(['command_input_json' => json_encode($command, JSON_THROW_ON_ERROR),'result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }

    private function explicitTargetDocument(Document $document, DocumentOwnerReference $owner): void
    {
        if (DocumentAuthorizationService::reference($document) != $owner || $document->content_kind !== 'rich_text'
            || !(bool) $document->collaboration_enabled || $document->status !== 'active') {
            throw BusinessException::forbidden('DOCUMENT_TARGET_BINDING_INVALID', '点击目标与当前协作文档不一致');
        }
    }

    private function targetFileInput(TenantContext $current, int $documentId, ExplicitTargetPreparationInput $input): CollaborationReferenceInput
    {
        return new CollaborationReferenceInput(
            $current->tenantId,
            $documentId,
            hash('sha256', $documentId . ':' . $input->operationKey()),
            array_values(array_unique(array_column($input->projection->fileReferences, 'file_key'))),
            $input->hash(),
        );
    }

    public function explicitTargetReferencesForUpdate(TenantContext $context, CollaborationReferenceInput $input, bool $consume): array
    {
        $this->requireCollaborationTransaction($context->tenantId, false);
        $proof = $this->targetTransfers[$input] ?? null;
        if ($proof === null || $proof['root'] !== $this->execution->rootIdentity() || $proof['pdo'] !== Db::connect()->getPdo()
            || $proof['consume'] !== $consume || (int) $proof['document']->id !== $input->documentId || $input->tenantId !== $context->tenantId) {
            throw BusinessException::forbidden('DOCUMENT_TARGET_REFERENCE_PROOF_INVALID', '点击引用仅可由本次真实目标事务转移');
        }
        $document = $proof['document'];
        return ['current_file_keys' => DocumentFileReference::where('document_id', $document->id)->where('usage', 'current')->column('file_key'),
            'historical_file_keys' => DocumentFileReference::where('document_id', $document->id)->where('usage', 'revision')->column('file_key'),
            'reference_digest' => $this->referenceDigest($document),'reservations' => $proof['reservations'],'cancel' => $proof['cancel'] ?? false];
    }

    public function beginExplicitTargetConsumption(TenantContext $context, int $documentId, int $expectedStateSeq, string $reason, string $requestId): void
    {
        $this->requireCollaborationTransaction($context->tenantId, false);
        self::requestKey($requestId);
        $this->authorization->run($context, function (TenantContext $current) use ($documentId, $expectedStateSeq, $reason, $requestId): void {
            $document = $this->authorization->document($current, $documentId, 'version.create', true);
            if (!(bool) $document->collaboration_enabled) {
                return;
            }
            $target = DocumentOperation::where('document_id', $documentId)->where('operation_id', 'target:' . hash('sha256', $requestId))->lock(true)->find();
            if ($target === null || $target->action !== 'collaboration.target') {
                throw BusinessException::conflict('DOCUMENT_TARGET_NOT_CONFIRMED', '业务准备需要原点击目标');
            }
            $this->assertBusinessActor($current, $target);
            $command = self::json($target->command_input_json);
            $proof = self::json($target->result_json);
            if (!isset($command['confirmed']) || ($command['canceled'] ?? false) || ($proof['state_seq'] ?? null) !== $expectedStateSeq
                || ($command['document_auth_revision'] ?? null) !== (int) $document->auth_revision
                || RichTextProjection::canonical($command['owner'] ?? null) !== RichTextProjection::canonical(self::ownerArray(DocumentAuthorizationService::reference($document)))) {
                throw BusinessException::conflict('DOCUMENT_TARGET_NOT_CONFIRMED', '业务准备不能改变点击目标或授权');
            }
            $hash = self::inputHash([$expectedStateSeq,$reason]);
            $op = DocumentOperation::where('document_id', $documentId)->where('operation_id', $requestId)->lock(true)->find();
            if ($op !== null) {
                $this->assertBusinessActor($current, $op);
                if ($op->action !== 'version.create' || $op->input_sha256 !== $hash) {
                    throw BusinessException::conflict('DOCUMENT_IDEMPOTENCY_CONFLICT', '业务准备请求不能改变');
                }
                return;
            }
            if ($target->phase !== 'prepared') {
                throw BusinessException::conflict('DOCUMENT_TARGET_ALREADY_CONSUMED', '点击目标已被正式版本消费');
            }
            DocumentOperation::create(['tenant_id' => $current->tenantId,'document_id' => $documentId,'operation_id' => $requestId,
                'action' => 'version.create','input_sha256' => $hash,'phase' => 'prepared','result_json' => '{}',
                'initiator_json' => json_encode(self::businessActor($current), JSON_THROW_ON_ERROR),
                'command_input_json' => json_encode([$expectedStateSeq,$reason], JSON_THROW_ON_ERROR),'created_at' => self::now()]);
        });
    }

    public function cancelExplicitTarget(TenantContext $context, int $documentId, string $requestId): array
    {
        self::requestKey($requestId);
        return $this->authorization->run($context, fn(TenantContext $current): array => Db::transaction(function () use ($current, $documentId, $requestId): array {
            $document = $this->authorization->document($current, $documentId, 'version.create', true);
            $key = 'target:' . hash('sha256', $requestId);
            $target = DocumentOperation::where('document_id', $documentId)->where('operation_id', $key)->lock(true)->find();
            $result = ['document_id' => $documentId,'request_id' => $requestId,'phase' => 'canceled'];
            if ($target === null) {
                $owner = DocumentAuthorizationService::reference($document);
                $this->explicitTargetDocument($document, $owner);
                if (DocumentOperation::where('document_id', $documentId)->where('operation_id', $requestId)->lock(true)->find() !== null) {
                    throw BusinessException::conflict('DOCUMENT_TARGET_BUSINESS_STARTED', '正式保存已开始或已完成，须查询原业务操作');
                }
                // No preparation exists, so only the real cancellation input is retained; there is no target snapshot or reference reservation.
                $command = ['document_id' => $documentId,'request_id' => $requestId,'owner' => self::ownerArray($owner),
                    'document_auth_revision' => (int) $document->auth_revision,'canceled' => true];
                DocumentOperation::create(['tenant_id' => $current->tenantId,'document_id' => $documentId,'operation_id' => $key,
                    'action' => 'collaboration.target','input_sha256' => self::inputHash($command),
                    'result_json' => json_encode($result, JSON_THROW_ON_ERROR),'phase' => 'completed',
                    'initiator_json' => json_encode(self::businessActor($current), JSON_THROW_ON_ERROR),
                    'command_input_json' => json_encode($command, JSON_THROW_ON_ERROR),'created_at' => self::now()]);
                return $result;
            }
            if ($target->action !== 'collaboration.target') {
                throw BusinessException::notFound('DOCUMENT_TARGET_NOT_FOUND', '原点击目标不存在');
            }
            $this->assertBusinessActor($current, $target);
            $command = self::json($target->command_input_json);
            if (RichTextProjection::canonical($command['owner'] ?? null) !== RichTextProjection::canonical(self::ownerArray(DocumentAuthorizationService::reference($document)))) {
                throw BusinessException::conflict('DOCUMENT_TARGET_BINDING_CHANGED', '点击目标业务对象归属已改变');
            }
            if (($command['canceled'] ?? false) === true) {
                return $result;
            }
            if ($target->phase === 'completed' || DocumentOperation::where('document_id', $documentId)->where('operation_id', $requestId)->lock(true)->find() !== null) {
                throw BusinessException::conflict('DOCUMENT_TARGET_BUSINESS_STARTED', '正式保存已开始或已完成，须查询原业务操作');
            }
            $frozen = new ExplicitTargetPreparationInput($command['frozen']);
            $fileInput = $this->targetFileInput($current, $documentId, $frozen);
            $this->targetTransfers[$fileInput] = ['root' => $this->execution->rootIdentity(),'pdo' => Db::connect()->getPdo(),
                'consume' => false,'cancel' => true,'document' => $document,'reservations' => []];
            $this->references->cancelExplicitTargetReferences($current, $fileInput);
            $command['canceled'] = true;
            $target->save(['phase' => 'completed','command_input_json' => json_encode($command, JSON_THROW_ON_ERROR)]);
            return $result;
        }));
    }

    private function freezeExplicitTarget(TenantContext $context, int $id, int $expected, string $reason, string $requestId): array
    {
        $this->outsideTransaction();
        $observed = Document::where('id', $id)->find();
        if ($observed === null) {
            throw BusinessException::notFound('DOCUMENT_NOT_FOUND', '文档不存在');
        }
        return $this->businessTransaction(
            $context,
            DocumentAuthorizationService::reference($observed),
            'version.create',
            function (TenantContext $current) use ($id, $expected, $reason, $requestId): array {
                $document = $this->authorization->document($current, $id, 'version.create', true);
                $input = [$expected,$reason];
                self::requestKey($requestId);
                $op = DocumentOperation::where('document_id', $id)->where('operation_id', $requestId)->lock(true)->find();
                if ($op !== null && ($op->action !== 'version.create' || $op->input_sha256 !== self::inputHash($input))) {
                    throw BusinessException::conflict('DOCUMENT_IDEMPOTENCY_CONFLICT', '同一正式请求不能改变输入');
                }
                if ($op !== null && $op->phase === 'completed') {
                    $originalTarget = DocumentOperation::where('document_id', $id)->where('operation_id', 'target:' . hash('sha256', $requestId))->lock(true)->find();
                    if ($originalTarget === null) {
                        throw BusinessException::conflict('DOCUMENT_TARGET_NOT_CONFIRMED', '点击目标操作事实不存在');
                    }
                    $this->assertBusinessActor($current, $originalTarget);
                    return self::json($op->result_json);
                }
                $target = DocumentOperation::where('document_id', $id)->where('operation_id', 'target:' . hash('sha256', $requestId))->lock(true)->find();
                if ($target === null || $target->action !== 'collaboration.target' || $target->phase !== 'prepared') {
                    throw BusinessException::conflict('DOCUMENT_TARGET_NOT_CONFIRMED', '正式版本必须消费原点击目标');
                }
                $this->assertBusinessActor($current, $target);
                if ($op !== null) {
                    $this->assertBusinessActor($current, $op);
                }
                $command = self::json($target->command_input_json);
                $proof = self::json($target->result_json);
                if (!isset($command['confirmed']) || ($proof['state_seq'] ?? null) !== $expected
                    || ($command['document_auth_revision'] ?? null) !== (int) $document->auth_revision
                    || RichTextProjection::canonical($command['owner'] ?? null) !== RichTextProjection::canonical(self::ownerArray(DocumentAuthorizationService::reference($document)))) {
                    throw BusinessException::conflict('DOCUMENT_TARGET_NOT_CONFIRMED', '点击目标提交或代际不一致');
                }
                $frozen = new ExplicitTargetPreparationInput($command['frozen']);
                $this->explicitTargetDocument($document, DocumentAuthorizationService::reference($document));
                $number = (int) DocumentRevision::where('document_id', $id)->max('revision_no') + 1;
                $digest = hash('sha256', json_encode(['rich_text',$expected,$frozen->projection->json,
                    hash('sha256', $frozen->binary),null,null], JSON_THROW_ON_ERROR));
                $revision = DocumentRevision::create(['tenant_id' => $current->tenantId,'document_id' => $id,'revision_no' => $number,
                    'source_state_seq' => $expected,'content_kind' => 'rich_text','yjs_snapshot' => $frozen->binary,
                    'content_snapshot_json' => $frozen->projection->json,'created_at' => self::now(),'created_by_member_id' => $current->memberId,
                    'reason' => $reason,'snapshot_sha256' => $digest]);
                foreach ($frozen->projection->fileReferences as $ref) {
                    $this->revisionReference($current, $document, $ref['file_key']);
                }
                $fileInput = $this->targetFileInput($current, $id, $frozen);
                $this->targetTransfers[$fileInput] = ['root' => $this->execution->rootIdentity(),'pdo' => Db::connect()->getPdo(),
                    'consume' => true,'document' => $document,'reservations' => []];
                $this->references->consumeExplicitTargetReferences($current, $fileInput);
                $command['revision_id'] = (int) $revision->id;
                $target->save(['phase' => 'completed','command_input_json' => json_encode($command, JSON_THROW_ON_ERROR)]);
                return $this->receipt(
                    $current,
                    $document,
                    'version.create',
                    $requestId,
                    $input,
                    array_merge($this->summary($document), ['document_id' => $id,'revision_id' => (int) $revision->id,'revision_no' => $number,'source_state_seq' => $expected,
                        'digest' => $digest,'operation_id' => $requestId,'phase' => 'completed','lease_epoch' => (int) $document->lease_epoch,
                        'state_seq' => $expected,'binary_sha256' => $frozen->tuple['target_binary_sha256'],
                        'store_id' => $proof['store_id'],'target_progress' => $proof['target_progress']]),
                );
            },
        );
    }

    /** Confirm only bytes observed under the real owner/Document/state locks; never rewrite the draft. */
    private function baselineReceipt(Document $document, DocumentState $state): ?string
    {
        if ($state->yjs_state_binary === null) {
            return null;
        }
        $projection = RichTextProjection::fromStructured(self::json($state->projected_json));
        if (!hash_equals((string) $state->state_sha256, hash('sha256', $state->yjs_state_binary))) {
            throw BusinessException::conflict('RECOVERY_REQUIRED', '已持久正文的真实字节摘要不一致');
        }
        if (is_string($state->last_store_id)) {
            $old = DocumentOperation::where('document_id', $document->id)->where('operation_id', $state->last_store_id)->lock(true)->find();
            $receipt = $old === null ? [] : self::json($old->result_json);
            if ($old !== null && in_array($old->action, ['collaboration.store','collaboration.baseline'], true)
                && ($receipt['state_seq'] ?? null) === (int) $state->state_seq && ($receipt['lease_epoch'] ?? null) === (int) $document->lease_epoch
                && ($receipt['binary_sha256'] ?? null) === $state->state_sha256 && isset($receipt['target_progress'])) {
                return $old->operation_id;
            }
        }
        $storeId = 'baseline:' . hash('sha256', json_encode([(int) $document->id,(int) $document->lease_epoch,
            (int) $state->state_seq,$state->state_sha256], JSON_THROW_ON_ERROR));
        $facts = ['document_id' => (int) $document->id,'store_id' => $storeId,'lease_epoch' => (int) $document->lease_epoch,
            'state_seq' => (int) $state->state_seq,'binary_sha256' => $state->state_sha256,
            'projection_sha256' => hash('sha256', $projection->json),'persisted_at' => $state->persisted_at,'target_progress' => 0];
        $old = DocumentOperation::where('document_id', $document->id)->where('operation_id', $storeId)->lock(true)->find();
        if ($old !== null) {
            if ($old->action !== 'collaboration.baseline' || RichTextProjection::canonical(self::json($old->result_json)) !== RichTextProjection::canonical($facts)) {
                throw BusinessException::conflict('STORE_DUPLICATE_MISMATCH', '基线事实不能改变');
            }
            return $storeId;
        }
        DocumentOperation::create(['tenant_id' => $document->tenant_id,'document_id' => $document->id,
            'operation_id' => $storeId,'action' => 'collaboration.baseline','input_sha256' => self::inputHash($facts),
            'result_json' => json_encode($facts, JSON_THROW_ON_ERROR),
            'command_input_json' => json_encode(['references' => $projection->fileReferences], JSON_THROW_ON_ERROR),'created_at' => self::now()]);
        return $storeId;
    }

    public function collaborationReceipt(int $documentId, DocumentOwnerReference $owner, string $storeId, string $captureHash): ?DurableSaveReceipt
    {
        $tenantId = $this->execution->current()?->tenantId() ?? 0;
        $this->requireCollaborationTransaction($tenantId, true);
        $this->lockedCollaborationDocument($tenantId, $documentId, $owner);
        $operation = DocumentOperation::where('document_id', $documentId)->where('operation_id', $storeId)->lock(true)->find();
        $this->acceptedWrites->requireProvider()->assertServiceDocument($documentId, $owner);
        if ($operation === null) {
            return null;
        }
        if ($operation->action !== 'collaboration.store' || !hash_equals($operation->input_sha256, $captureHash)) {
            throw BusinessException::conflict('STORE_DUPLICATE_MISMATCH', '保存编号对应不同的完整输入');
        }
        return new DurableSaveReceipt(self::json($operation->result_json));
    }

    public function commitAccepted(AcceptedStoreInput $input): DurableSaveReceipt
    {
        $in = $input->tuple;
        $this->requireCollaborationTransaction($in['tenant_id'], true);
        $document = $this->lockedCollaborationDocument($in['tenant_id'], $in['document_id'], $input->owner);
        $state = DocumentState::where('document_id', $in['document_id'])->lock(true)->find();
        $operation = DocumentOperation::where('document_id', $in['document_id'])->where('operation_id', $in['store_id'])->lock(true)->find();
        $provider = $this->acceptedWrites->requireProvider();
        if ($operation !== null) {
            $provider->assertServiceDocument($in['document_id'], $input->owner);
            if ($operation->action !== 'collaboration.store' || !hash_equals($input->hash(), $operation->input_sha256)) {
                throw BusinessException::conflict('STORE_DUPLICATE_MISMATCH', '重复保存不能改变完整输入');
            }
            return new DurableSaveReceipt(self::json($operation->result_json));
        }
        $plan = $provider->lockAcceptedCapture($input);
        if ($state === null || (int) $state->state_seq !== (int) $document->state_seq
            || (int) $state->state_seq !== $in['base_state_seq'] || !hash_equals($state->state_sha256, $in['baseline_sha256'])) {
            throw BusinessException::conflict('REVISION_CONFLICT', '保存基线已改变');
        }
        $next = $in['base_state_seq'] + 1;
        $persistedAt = self::now();
        $document->save(['state_seq' => $next,'lease_epoch' => $in['lease_epoch'],'update_time' => time()]);
        $state->save(['yjs_state_binary' => $input->binary,'state_seq' => $next,'state_sha256' => $in['binary_sha256'],
            'projected_json' => $input->projection->json,'projection_schema_version' => RichTextProjection::SCHEMA,
            'last_store_id' => $in['store_id'],'persisted_at' => $persistedAt]);
        DocumentFileReference::where('document_id', $in['document_id'])->where('usage', 'current')->delete();
        foreach ($input->projection->fileReferences as $ref) {
            DocumentFileReference::create(['tenant_id' => $in['tenant_id'],'document_id' => $in['document_id'],
                'file_key' => $ref['file_key'],'variant_key' => $ref['variant_key'],'usage' => 'current',
                'derived_state_seq' => $next,'updated_at' => self::now()]);
        }
        $transfer = new AcceptedReferenceTransfer($in['tenant_id'], $in['document_id'], $input->hash());
        $scope = $this->execution->rootIdentity();
        $this->acceptedTransfers[$transfer] = ['scope' => $scope,'pdo' => Db::connect()->getPdo(),'plan' => $plan,'document' => $document];
        $this->references->commitAcceptedDocumentReferences($transfer);
        $receipt = new DurableSaveReceipt(['document_id' => $in['document_id'],'store_id' => $in['store_id'],
            'lease_epoch' => $in['lease_epoch'],'state_seq' => $next,'target_progress' => $in['target_progress'],
            'binary_sha256' => $in['binary_sha256'],'projection_sha256' => $in['projection_sha256'],
            'persisted_at' => $persistedAt]);
        DocumentOperation::create(['tenant_id' => $in['tenant_id'],'document_id' => $in['document_id'],
            'operation_id' => $in['store_id'],'action' => 'collaboration.store','input_sha256' => $input->hash(),
            'result_json' => json_encode($receipt->data, JSON_THROW_ON_ERROR),
            'command_input_json' => json_encode(['references' => $input->projection->fileReferences], JSON_THROW_ON_ERROR),'created_at' => self::now()]);
        $release = new ReservationReleaseInput($in['tenant_id'], $in['document_id'], $input->hash());
        $this->acceptedReleases[$release] = ['scope' => $scope,'pdo' => Db::connect()->getPdo(),
            'hash' => $input->hash(),'reservations' => $plan->reservations];
        $this->references->releaseSettledCollaborationReferences($release);
        $provider->markPersisted($plan, $receipt);
        $this->audit->appendTenantSystem(
            $in['tenant_id'],
            'document.collaboration.persisted',
            'document.collaboration.store',
            $in['store_id'],
            ['document_id' => $in['document_id'],'state_seq' => $next,
                'target_progress' => $in['target_progress'],'origin_member_ids' => $plan->originMembers],
        );
        return $receipt;
    }

    public function acceptedReferencesForUpdate(AcceptedReferenceTransfer $input): array
    {
        $this->requireCollaborationTransaction($input->tenantId, true);
        $proof = $this->acceptedTransfers[$input] ?? null;
        if ($proof === null || $proof['scope'] !== $this->execution->rootIdentity() || $proof['pdo'] !== Db::connect()->getPdo()
            || !hash_equals($proof['plan']->input->hash(), $input->captureHash)) {
            throw BusinessException::forbidden('DOCUMENT_ACCEPTED_REFERENCE_PROOF_INVALID', '已接纳引用证明无效');
        }
        $plan = $proof['plan'];
        $document = $proof['document'];
        $keys = array_values(array_unique(DocumentFileReference::where('document_id', $document->id)->order('file_key')->column('file_key')));
        return ['file_keys' => $keys,'reference_digest' => $this->referenceDigest($document),'reservations' => $plan->reservations];
    }

    public function settledReservationsForUpdate(ReservationReleaseInput $input): array
    {
        $current = $this->execution->current();
        if ($current?->tenantId() !== $input->tenantId || (!$current instanceof AdminExecutionContext && !$current instanceof \app\common\execution\SystemExecutionContext)
            || !Db::connect()->getPdo()->inTransaction()) {
            throw BusinessException::forbidden('DOCUMENT_RESERVATION_SETTLEMENT_INVALID', '收口需要同一可信事务');
        }
        $proof = $this->acceptedReleases[$input] ?? null;
        if ($proof === null || $proof['scope'] !== $this->execution->rootIdentity() || $proof['pdo'] !== Db::connect()->getPdo()
            || !hash_equals($proof['hash'], $input->captureHash)) {
            throw BusinessException::forbidden('DOCUMENT_RESERVATION_SETTLEMENT_INVALID', '资源占用未证明完成');
        }
        return ['reservations' => $proof['reservations']];
    }

    private function lockedCollaborationDocument(int $tenantId, int $documentId, DocumentOwnerReference $owner): Document
    {
        foreach (['peanut.document','peanut.file','peanut.collaboration',$owner->moduleKey] as $module) {
            $this->authorization->requireDeploymentModule($module);
        }
        $policy = $this->owners->requirePolicy($owner);
        $state = $policy->resolvePersistedOwner($tenantId, $owner, true);
        $document = Document::where('id', $documentId)->lock(true)->find();
        $archiveDrain = $document !== null && $document->status === 'archiving' && DocumentOperation::where('document_id', $documentId)
            ->where('action', 'archive')->whereIn('phase', ['prepared','attention'])->whereNotNull('barrier_plan_json')->lock(true)->find() !== null;
        if ($document === null || $state->tenantId !== $tenantId || $state->reference != $owner
            || DocumentAuthorizationService::reference($document) != $owner || $state->archived && !$archiveDrain
            || !in_array($document->status, ['active','archiving'], true) || $document->content_kind !== 'rich_text'
            || !(bool) $document->collaboration_enabled) {
            throw BusinessException::conflict('RECOVERY_REQUIRED', '文档归属或生命周期不允许保存协作状态');
        }
        return $document;
    }

    private function requireCollaborationTransaction(int $tenantId, bool $system): void
    {
        $current = $this->execution->current();
        $pdo = Db::connect()->getPdo();
        if ($tenantId < 1 || $current?->tenantId() !== $tenantId
            || $system && !$current instanceof \app\common\execution\SystemExecutionContext
            || !$system && !$current instanceof AdminExecutionContext
            || !$pdo instanceof \PDO || !$pdo->inTransaction()) {
            throw BusinessException::conflict('DOCUMENT_COLLABORATION_TRANSACTION_REQUIRED', '协作需要同一可信身份和原生事务');
        }
    }

    private static function ownerArray(DocumentOwnerReference $owner): array
    {
        return ['module_key' => $owner->moduleKey,'object_type' => $owner->objectType,'object_id' => $owner->objectId,'slot' => $owner->slot];
    }

    private function writeBinding(TenantContext $context, Document $document, array $resource): void
    {
        $data = ['tenant_id' => $context->tenantId,'document_id' => $document->id,'primary_file_key' => $resource['file_key'],'verified_mime_type' => $resource['verified_mime_type'],
            'source_sha256' => $resource['sha256'],'source_file_revision' => $resource['revision'],'state_seq' => $document->state_seq,'bound_at' => self::now()];
        $existing = DocumentResourceBinding::where('document_id', $document->id)->lock(true)->find();
        if ($existing === null) {
            DocumentResourceBinding::create($data);
        } else {
            $existing->save($data);
        }
    }
    private function writeReferences(TenantContext $context, Document $document, array $refs): void
    {
        DocumentFileReference::where('document_id', $document->id)->where('usage', 'current')->delete();
        foreach ($refs as $ref) {
            try {
                DocumentFileReference::create(['tenant_id' => $context->tenantId,'document_id' => $document->id,'file_key' => $ref['file_key'],
                    'variant_key' => $ref['variant_key'],'usage' => 'current','derived_state_seq' => $document->state_seq,'updated_at' => self::now()]);
            } catch (\think\db\exception\PDOException $error) {
                $info = $error->getData()['PDO Error Info'] ?? [];
                // Only the declared File-target FK becomes a stable missing-reference rejection.
                // Owner/member constraints and unknown database failures retain their original cause.
                if (($info['SQLSTATE'] ?? null) === '23000' && (int) ($info['Driver Error Code'] ?? 0) === 1452
                    && str_contains((string) ($info['Driver Error Message'] ?? ''), '`fk_document_file_ref_object`')) {
                    throw BusinessException::notFound('DOCUMENT_FILE_REFERENCE_NOT_FOUND', '引用资源不存在');
                }
                throw $error;
            }
        }
        $this->syncReferences($context, $document);
    }
    private function revisionReference(TenantContext $context, Document $document, string $key): void
    {
        if (DocumentFileReference::where('document_id', $document->id)->where('file_key', $key)->where('usage', 'revision')->find() === null) {
            DocumentFileReference::create(['tenant_id' => $context->tenantId,'document_id' => $document->id,'file_key' => $key,'variant_key' => null,'usage' => 'revision','derived_state_seq' => $document->state_seq,'updated_at' => self::now()]);
        }
    }
    private function syncReferences(TenantContext $context, Document $document): void
    {
        $keys = array_values(array_unique(DocumentFileReference::where('document_id', $document->id)->order('file_key')->column('file_key')));
        $this->references->replaceDocumentReferences($context, (int) $document->id, $keys);
    }
    private function referenceDigest(Document $document): string
    {
        $rows = DocumentFileReference::where('document_id', $document->id)->field(['file_key','variant_key','usage','derived_state_seq'])->order(['file_key' => 'asc','usage' => 'asc'])->select()->toArray();
        return hash('sha256', json_encode(RichTextProjection::canonical($rows), JSON_THROW_ON_ERROR));
    }
    private function appendRevision(TenantContext $context, Document $document, string $reason, ?array $snapshot): array
    {
        $content = null;
        $binary = null;
        if ($document->content_kind === 'rich_text') {
            $state = DocumentState::where('document_id', $document->id)->lock(true)->find();
            if ($state === null || (int) $state->state_seq !== (int) $document->state_seq) {
                throw BusinessException::conflict('DOCUMENT_STATE_MISSING', '文档持久水位不一致');
            }
            $projection = RichTextProjection::fromStructured(self::json($state->projected_json));
            $content = $projection->json;
            $binary = $state->yjs_state_binary;
            foreach ($projection->fileReferences as $ref) {
                $this->revisionReference($context, $document, $ref['file_key']);
            }
            $this->syncReferences($context, $document);
        }
        $number = (int) DocumentRevision::where('document_id', $document->id)->max('revision_no') + 1;
        $digest = hash('sha256', json_encode([(string) $document->content_kind,(int) $document->state_seq,$content,$binary === null ? null : hash('sha256', $binary),$snapshot['file_key'] ?? null,$snapshot['sha256'] ?? null], JSON_THROW_ON_ERROR));
        $revision = DocumentRevision::create(['tenant_id' => $context->tenantId,'document_id' => $document->id,'revision_no' => $number,'source_state_seq' => $document->state_seq,
            'content_kind' => $document->content_kind,'yjs_snapshot' => $binary,'content_snapshot_json' => $content,'immutable_file_key' => $snapshot['file_key'] ?? null,'immutable_file_sha256' => $snapshot['sha256'] ?? null,
            'immutable_file_mime_type' => $snapshot['verified_mime_type'] ?? null,
            'created_at' => self::now(),'created_by_member_id' => $context->memberId,'reason' => $reason,'snapshot_sha256' => $digest]);
        return ['document_id' => (int) $document->id,'revision_id' => (int) $revision->id,'revision_no' => $number,'source_state_seq' => (int) $document->state_seq,'digest' => $digest];
    }
    private function replay(Document $document, string $action, string $requestId, array $input): ?array
    {
        self::requestKey($requestId);
        $row = DocumentOperation::where('document_id', $document->id)->where('operation_id', $requestId)->lock(true)->find();
        if ($row === null) {
            return null;
        }
        if ($row->action !== $action || !hash_equals((string) $row->input_sha256, self::inputHash($input))) {
            throw BusinessException::conflict('DOCUMENT_IDEMPOTENCY_CONFLICT', '同一请求标识不能改变动作或内容');
        }
        return self::json($row->result_json);
    }
    private function receipt(TenantContext $context, Document $document, string $action, string $requestId, array $input, array $result): array
    {
        self::requestKey($requestId);
        $prepared = $action === 'version.create' && (bool) $document->collaboration_enabled
            ? DocumentOperation::where('document_id', $document->id)->where('operation_id', $requestId)->lock(true)->find() : null;
        if ($prepared !== null) {
            $this->assertBusinessActor($context, $prepared);
            if ($prepared->action !== $action || $prepared->phase !== 'prepared' || $prepared->input_sha256 !== self::inputHash($input)) {
                throw BusinessException::conflict('DOCUMENT_IDEMPOTENCY_CONFLICT', '原业务准备不能改变');
            }
            $prepared->save(['phase' => 'completed','result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
        } else {
            DocumentOperation::create(['tenant_id' => $context->tenantId,'document_id' => $document->id,'operation_id' => $requestId,'action' => $action,'input_sha256' => self::inputHash($input),
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR),'created_at' => self::now()]);
        }
        $this->audit->appendTenantMember($context, 'document.' . $action, 'document.' . $action, 'document', (string) $document->id, null, null, 1, $result['digest'] ?? null, ['state_seq' => (int) $document->state_seq]);
        return $result;
    }
    private function binding(Document $document): array
    {
        $binding = DocumentResourceBinding::where('document_id', $document->id)->find();
        if ($binding === null || (int) $binding->state_seq !== (int) $document->state_seq) {
            throw BusinessException::conflict('DOCUMENT_BINDING_MISSING', '文档主资源绑定不可用');
        }
        return $binding->toArray();
    }
    private function revision(Document $document, int $id): DocumentRevision
    {
        $revision = DocumentRevision::where('document_id', $document->id)->where('id', $id)->find();
        if ($revision === null) {
            throw BusinessException::notFound('DOCUMENT_REVISION_NOT_FOUND', '文档版本不存在');
        }return $revision;
    }
    private function solo(Document $document, ?string $kind = null): void
    {
        if ((bool) $document->collaboration_enabled) {
            throw BusinessException::conflict('DOCUMENT_COLLABORATION_BARRIER_REQUIRED', '协作已启用，必须使用真实保存屏障');
        }
        if ($kind !== null && $document->content_kind !== $kind) {
            throw BusinessException::invalid('DOCUMENT_KIND_MISMATCH', '文档类型不允许此操作');
        }
    }
    private function cas(Document $document, int $expected): void
    {
        if ($expected < 0 || (int) $document->state_seq !== $expected) {
            throw BusinessException::conflict('DOCUMENT_STATE_SEQ_CONFLICT', '文档已被其他请求更新');
        }
    }
    private function summary(Document $document): array
    {
        return ['document_id' => (int) $document->id,'content_kind' => $document->content_kind,'schema_version' => $document->schema_version,'status' => $document->status,'state_seq' => (int) $document->state_seq,'auth_revision' => (int) $document->auth_revision];
    }
    private static function requestKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $key) !== 1) {
            throw BusinessException::invalid('DOCUMENT_REQUEST_ID_INVALID', '文档请求标识无效');
        }
    }
    private static function inputHash(array $input): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($input), JSON_THROW_ON_ERROR));
    }
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
    private static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }return json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
    }
}
