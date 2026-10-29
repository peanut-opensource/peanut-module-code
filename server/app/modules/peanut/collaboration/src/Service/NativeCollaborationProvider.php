<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Service;

use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationRoom;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationConnection;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationTicket;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationGrant;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationBarrier;
use PeanutAdmin\Modules\Document\Contract\DocumentAcceptedWriteProvider;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\AcceptedStoreInput;
use PeanutAdmin\Modules\Document\Contract\AcceptedWritePlan;
use PeanutAdmin\Modules\Document\Contract\DurableSaveReceipt;
use PeanutAdmin\Modules\Document\Contract\BusinessBarrierPlan;
use PeanutAdmin\Modules\Document\Contract\DrainedBusinessBarrier;
use PeanutAdmin\Modules\Document\Contract\DocumentBusinessReceipt;
use PeanutAdmin\Modules\Document\Service\DocumentAcceptedCommitQuery;
use PeanutAdmin\Modules\File\Contract\CollaborationReferenceInput;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use think\facade\Db;

/** Native batch custody and business-control proofs. No update journal or per-frame persistence. */
final class NativeCollaborationProvider implements DocumentAcceptedWriteProvider
{
    private \WeakMap $plans;
    private \WeakMap $businessProofs;

    public function __construct(
        private readonly ServiceAuthentication $authentication,
        private readonly ExecutionContextStore $execution,
        private readonly DocumentAcceptedCommitQuery $commits,
        private readonly BusinessControlClient $control,
        private readonly RuntimeLifecycle $lifecycle,
    ) {
        $this->plans = new \WeakMap();
        $this->businessProofs = new \WeakMap();
    }

    /** This unscoped preflight establishes Scope only from a signed service and existing room identity. */
    public function roomBinding(string $roomKey): array
    {
        $request = $this->authentication->current();
        if (preg_match('/^[0-9a-f]{64}$/D', $roomKey) !== 1) {
            throw BusinessException::invalid('COLLABORATION_ROOM_KEY_INVALID', '协作房间编号无效');
        }
        $room = CollaborationRoom::withoutTenantScope()->where('room_key', $roomKey)->find();
        if ($room === null || !$this->lifecycle->ownsRoom($room) || (int) $room->protocol_version !== 2
            || $room->service_instance_id !== $request->serviceInstanceId || $room->holder_key !== $request->holderKey) {
            throw BusinessException::forbidden('COLLABORATION_SERVICE_INSTANCE_STALE', '房间协议或持有服务不一致');
        }
        $binding = self::json($room->binding_json);
        if (($binding['instance_id'] ?? null) !== $room->instance_id || ($binding['edition'] ?? null) !== $room->edition
            || ($binding['document_id'] ?? null) !== (int) $room->document_id
            || !is_int($binding['tenant_id'] ?? null) || $binding['tenant_id'] < 1 || $binding['tenant_id'] !== (int) $room->tenant_id
            || !is_array($binding['owner'] ?? null)) {
            throw BusinessException::conflict('RECOVERY_REQUIRED', '房间持久归属不完整');
        }
        return $binding;
    }

    public function lockedRoom(string $key, mixed $epoch): CollaborationRoom
    {
        $request = $this->authentication->current();
        $room = CollaborationRoom::where('room_key', $key)->lock(true)->find();
        if (!is_int($epoch) || $epoch < 1 || $room === null || !$this->lifecycle->ownsRoom($room)
            || (int) $room->protocol_version !== 2 || (int) $room->lease_epoch !== $epoch
            || $room->service_instance_id !== $request->serviceInstanceId || $room->holder_key !== $request->holderKey) {
            throw BusinessException::conflict('COLLABORATION_LEASE_STALE', '房间协议、服务或代际已改变');
        }
        return $room;
    }

    public function recoveryBinding(string $key): array
    {
        if ($this->authentication->current()->path !== '/adminapi/internal/collaboration/v1/recovery'
            || preg_match('/^[0-9a-f]{64}$/D', $key) !== 1) {
            throw BusinessException::forbidden('COLLABORATION_RECOVERY_AUDIENCE_INVALID', '恢复必须来自原签名服务入口');
        }
        $room = CollaborationRoom::withoutTenantScope()->where('room_key', $key)->find();
        if ($room === null || !$this->lifecycle->ownsRoom($room) || (int) $room->protocol_version !== 2) {
            throw BusinessException::conflict('COLLABORATION_PROTOCOL_MIGRATION_REQUIRED', '旧房间不能隐式切换原生协议');
        }
        $binding = self::json($room->binding_json);
        if (($binding['tenant_id'] ?? null) !== (int) $room->tenant_id || ($binding['document_id'] ?? null) !== (int) $room->document_id
            || ($binding['instance_id'] ?? null) !== $room->instance_id || ($binding['edition'] ?? null) !== $room->edition
            || !is_array($binding['owner'] ?? null)) {
            throw BusinessException::conflict('RECOVERY_REQUIRED', '恢复持久归属不完整');
        }
        return $binding;
    }

    public function assertServiceDocument(int $documentId, DocumentOwnerReference $owner): void
    {
        $body = $this->authentication->payload();
        $room = $this->lockedRoom($body['room_key'] ?? '', $body['lease_epoch'] ?? null);
        $binding = self::json($room->binding_json);
        if ((int) $room->document_id !== $documentId || RichTextProjection::canonical($binding['owner']) !== RichTextProjection::canonical(self::ownerArray($owner))) {
            throw BusinessException::forbidden('COLLABORATION_DOCUMENT_BINDING_INVALID', '服务与文档归属不一致');
        }
    }

    public function assertReferenceReservation(TenantContext $current, CollaborationReferenceInput $input, DocumentOwnerReference $actualOwner, string $currentRevision): void
    {
        $this->assertTransaction($current->tenantId);
        $request = $this->authentication->current();
        if (!str_ends_with($request->path, '/references') || !$this->execution->current() instanceof AdminExecutionContext) {
            throw BusinessException::forbidden('COLLABORATION_REFERENCE_AUDIENCE_INVALID', '引用占用需要当前人员和原签名请求');
        }
        $body = $this->authentication->payload();
        $room = $this->lockedRoom($body['room_key'] ?? '', $body['lease_epoch'] ?? null);
        $grant = CollaborationGrant::where('grant_key', $input->grantDigest)->where('purpose', 'references')->lock(true)->find();
        $connection = $grant === null ? null : CollaborationConnection::where('id', $grant->connection_id)->find();
        $ticket = $connection === null ? null : CollaborationTicket::where('id', $connection->ticket_id)->find();
        if ($grant === null || $connection === null || $ticket === null || (int) $grant->room_id !== (int) $room->id
            || $grant->reservation_key !== $input->reservationKey || (int) $grant->document_id !== $input->documentId
            || $grant->authorization_revision !== $currentRevision || $grant->input_sha256 !== self::hash($body)
            || self::json($grant->file_keys_json) !== $input->fileKeys
            || RichTextProjection::canonical(self::json($room->binding_json)['owner']) !== RichTextProjection::canonical(self::ownerArray($actualOwner))
            || RichTextProjection::canonical(self::json($ticket->issued_identity_json)) !== RichTextProjection::canonical([
                'tenant_id' => $current->tenantId,'account_id' => $current->accountId,'member_id' => $current->memberId,
                'session_key' => $current->sessionKey,'client_key' => $current->clientKey,'authorization_revision' => $current->authorizationRevision,
            ])) {
            throw BusinessException::forbidden('COLLABORATION_REFERENCE_PROOF_INVALID', '引用占用与当前人员、文档或原始请求不一致');
        }
    }

    public function lockAcceptedCapture(AcceptedStoreInput $input): AcceptedWritePlan
    {
        $this->assertTransaction($input->tuple['tenant_id']);
        $request = $this->authentication->current();
        if (!str_ends_with($request->path, '/store') || !hash_equals((new AcceptedStoreInput($this->authentication->payload()))->hash(), $input->hash())) {
            throw BusinessException::forbidden('COLLABORATION_CAPTURE_REQUEST_MISMATCH', '捕获不是本次原签名保存请求');
        }
        $in = $input->tuple;
        $room = $this->lockedRoom($in['room_key'], $in['lease_epoch']);
        if (!in_array($room->status, ['active','draining'], true) || $in['service_instance_id'] !== $request->serviceInstanceId
            || RichTextProjection::canonical(self::json($room->binding_json)) !== RichTextProjection::canonical([
                'instance_id' => $in['instance_id'],'edition' => $in['edition'],'tenant_id' => $in['tenant_id'],
                'document_id' => $in['document_id'],'owner' => $in['owner'],
            ]) || $in['projector_version'] !== $this->authentication->projectorVersion()) {
            throw BusinessException::forbidden('COLLABORATION_CAPTURE_BINDING_INVALID', '批次与当前房间、实例或投影协议不一致');
        }
        $members = [];
        $connections = [];
        foreach ($in['participants'] as $source) {
            $connection = CollaborationConnection::where('connection_key', $source['connection_key'])->where('room_id', $room->id)->find();
            $ticket = $connection === null ? null : CollaborationTicket::where('id', $connection->ticket_id)->find();
            $grant = $connection === null ? null : CollaborationGrant::where('connection_id', $connection->id)->where('room_id', $room->id)
                ->where('purpose', 'connection')->where('mode', 'write')->where('lease_epoch', $in['lease_epoch'])
                ->where('service_instance_id', $request->serviceInstanceId)->where('holder_key', $request->holderKey)
                ->where('authorization_revision', $source['authorization_revision'])
                ->where('issued_at_ms', '<=', $source['authorized_at_ms'])->where('expires_at_ms', '>', $source['authorized_at_ms'])
                ->order('issued_at_ms', 'desc')->lock(true)->find();
            if ($grant === null || $ticket === null || (int) $ticket->document_id !== $in['document_id']
                || (int) $grant->document_id !== $in['document_id'] || $grant->identity_sha256 !== self::hash(self::json($ticket->issued_identity_json))
                || $source['authorized_at_ms'] > (int) floor(microtime(true) * 1000) + 1000
                || $connection->revoked_at !== null && $source['authorized_at_ms'] >= (int) (new \DateTimeImmutable($connection->revoked_at, new \DateTimeZone('UTC')))->format('Uv')) {
                throw BusinessException::forbidden('COLLABORATION_CAPTURE_GRANT_INVALID', '参与者接纳时没有真实有效的写授权窗口');
            }
            $identity = self::json($ticket->issued_identity_json);
            $members[] = (int) $identity['member_id'];
            $connections[(int) $connection->id] = max($connections[(int) $connection->id] ?? 0, $source['authorized_at_ms']);
        }
        if ($in['participants'] === []) {
            $state = $this->commits->collaborationBaseline($in['document_id'])['state'];
            if ($state === null || $state['yjs_state_binary'] !== null
                || RichTextProjection::fromStructured(self::json($state['projected_json']))->json !== $input->projection->json) {
                throw BusinessException::forbidden('COLLABORATION_INITIALIZATION_INVALID', '无参与者批次仅能持久化原始受信初始投影');
            }
        }
        $reservations = [];
        $capturedKeys = array_values(array_unique(array_column($input->projection->fileReferences, 'file_key')));
        foreach (CollaborationGrant::where('room_id', $room->id)->where('lease_epoch', $in['lease_epoch'])
            ->where('holder_key', $request->holderKey)->where('purpose', 'references')->lock(true)->select() as $grant) {
            if (!isset($connections[(int) $grant->connection_id]) || (int) $grant->issued_at_ms > $connections[(int) $grant->connection_id]) {
                continue;
            }
            $keys = self::json($grant->file_keys_json);
            // A source timestamp alone does not prove an awaited reference RPC was
            // merged. Only consume the exact reserved set present in this capture;
            // absent/partially captured reservations survive until an actual epoch fence.
            if (array_diff($keys, $capturedKeys) !== []) {
                continue;
            }
            $reservations[] = ['reservation_key' => $grant->reservation_key,'digest' => $grant->grant_key,'file_keys' => $keys];
        }
        $plan = new AcceptedWritePlan($input, $reservations, array_values(array_unique($members)));
        $this->plans[$plan] = ['root' => $this->execution->rootIdentity(),'pdo' => Db::connect()->getPdo(),'request' => $request,
            'room' => $room,'connections' => $connections];
        return $plan;
    }

    public function targetReferenceReservations(TenantContext $current, int $documentId, DocumentOwnerReference $owner): array
    {
        $this->assertTransaction($current->tenantId);
        $request = $this->authentication->current();
        $body = $this->authentication->payload();
        if (!in_array($request->path, ['/adminapi/internal/collaboration/v1/target','/adminapi/internal/collaboration/v1/target/prepare'], true)
            || !$this->execution->current() instanceof AdminExecutionContext || ($body['protocol'] ?? null) !== 2
            || ($body['projector_version'] ?? null) !== $this->authentication->projectorVersion()) {
            throw BusinessException::forbidden('DOCUMENT_TARGET_SERVICE_PROOF_INVALID', '点击目标缺少本次原签名服务证明');
        }
        $connection = CollaborationConnection::where('connection_key', $body['connection_key'] ?? '')->find();
        $room = $connection === null ? null : CollaborationRoom::where('id', $connection->room_id)->lock(true)->find();
        $connection = $room === null ? null : CollaborationConnection::where('id', $connection->id)->lock(true)->find();
        $ticket = $connection === null ? null : CollaborationTicket::where('id', $connection->ticket_id)->find();
        if ($room === null || $ticket === null || !$this->lifecycle->ownsRoom($room) || (int) $room->protocol_version !== 2
            || $room->service_instance_id !== $request->serviceInstanceId || $room->holder_key !== $request->holderKey || $room->status !== 'active'
            || (int) $room->document_id !== $documentId || (int) $connection->lease_epoch !== (int) $room->lease_epoch
            || $connection->status !== 'active' || $connection->mode !== 'write'
            || strtotime($connection->lease_expires_at . ' UTC') * 1000 <= (int) floor(microtime(true) * 1000)
            || RichTextProjection::canonical(self::json($ticket->issued_identity_json)) !== RichTextProjection::canonical(\PeanutAdmin\Modules\Collaboration\Protocol\TicketIdentity::fromCurrentSession($current)->toLedger())
            || RichTextProjection::canonical(self::json($room->binding_json)['owner']) !== RichTextProjection::canonical(self::ownerArray($owner))) {
            throw BusinessException::forbidden('DOCUMENT_TARGET_ACTOR_INVALID', '点击目标与当前原始人员、文档或代际不一致');
        }
        $reservations = [];
        foreach (CollaborationGrant::where('room_id', $room->id)->where('lease_epoch', $room->lease_epoch)
            ->where('purpose', 'references')->lock(true)->select() as $grant) {
            $sourceTicket = CollaborationTicket::where('id', $grant->ticket_id)->find();
            $sourceConnection = CollaborationConnection::where('id', $grant->connection_id)->where('room_id', $room->id)->find();
            if ($sourceTicket === null || $sourceConnection === null || (int) $sourceTicket->document_id !== $documentId
                || (int) $grant->document_id !== $documentId || $grant->service_instance_id !== $room->service_instance_id
                || $grant->holder_key !== $request->holderKey
                || $grant->identity_sha256 !== self::hash(self::json($sourceTicket->issued_identity_json))) {
                throw BusinessException::forbidden('DOCUMENT_TARGET_REFERENCE_PROOF_INVALID', '原资源占用身份不一致');
            }
            $reservations[] = ['reservation_key' => $grant->reservation_key,'digest' => $grant->grant_key,'file_keys' => self::json($grant->file_keys_json)];
        }
        return $reservations;
    }

    public function markPersisted(AcceptedWritePlan $plan, DurableSaveReceipt $receipt): void
    {
        $held = $this->plans[$plan] ?? null;
        if ($held === null || $held['root'] !== $this->execution->rootIdentity() || $held['pdo'] !== Db::connect()->getPdo()
            || $held['request'] !== $this->authentication->current()) {
            throw BusinessException::forbidden('COLLABORATION_STORE_PROOF_INVALID', '持久化证明不属于本次事务');
        }
        $this->commits->assertStagedAcceptedCommit($plan->input, $receipt);
        $room = $held['room'];
        $room->save(['persisted_state_seq' => $receipt->data['state_seq'],'baseline_sha256' => $receipt->data['binary_sha256'],
            'last_store_id' => $receipt->data['store_id'],'last_store_sha256' => $plan->input->hash(),
            'last_receipt_json' => json_encode($receipt->data, JSON_THROW_ON_ERROR),'last_target_progress' => $receipt->data['target_progress'],
            'lease_expires_at' => $this->lifecycle->roomLeaseExpiresAt(),'updated_at' => gmdate('Y-m-d H:i:s')]);
        foreach ($plan->reservations as $reservation) {
            CollaborationGrant::where('room_id', $room->id)->where('grant_key', $reservation['digest'])->where('purpose', 'references')->delete();
        }
        // A real captured merged snapshot contains all earlier updates from these sources.
        // Retain the matching/current window; remove only older windows that ended before its sample.
        foreach ($held['connections'] as $connectionId => $sourceTime) {
            CollaborationGrant::where('room_id', $room->id)->where('lease_epoch', $room->lease_epoch)
                ->where('connection_id', $connectionId)->where('purpose', 'connection')->where('expires_at_ms', '<=', $sourceTime)->delete();
        }
        unset($this->plans[$plan]);
    }

    private function lockedFencedRoom(int $documentId, DocumentOwnerReference $owner, int $oldEpoch): CollaborationRoom
    {
        $this->assertTransaction($this->execution->current()?->tenantId() ?? 0);
        $document = $this->commits->collaborationBaseline($documentId)['document'];
        $room = CollaborationRoom::where('document_id', $documentId)->lock(true)->find();
        if ($room === null || $document === null || !$this->lifecycle->ownsRoom($room) || (int) $room->protocol_version !== 2
            || $oldEpoch < 1 || (int) $room->lease_epoch <= $oldEpoch || (int) $document['lease_epoch'] !== (int) $room->lease_epoch
            || RichTextProjection::canonical(self::json($room->binding_json)['owner']) !== RichTextProjection::canonical(self::ownerArray($owner))) {
            throw BusinessException::forbidden('COLLABORATION_REFERENCE_FENCE_INVALID', '旧授权尚未被真实文档和房间代际隔离');
        }
        if ($this->execution->current() instanceof AdminExecutionContext) {
            $barrier = CollaborationBarrier::where('room_id', $room->id)->where('lease_epoch', $oldEpoch)
                ->whereNotNull('business_sha256')->lock(true)->find();
            $result = $barrier === null ? [] : self::json($barrier->business_result_json);
            if ($barrier === null || ($result['lease_epoch'] ?? null) !== (int) $room->lease_epoch
                || $barrier->business_sha256 !== self::businessHash($result)) {
                throw BusinessException::forbidden('COLLABORATION_REFERENCE_FENCE_INVALID', '当前业务未实际提交代际隔离');
            }
        } else {
            $body = $this->authentication->payload();
            if ($this->authentication->current()->path !== '/adminapi/internal/collaboration/v1/recovery'
                || $room->holder_key !== $this->authentication->current()->holderKey
                || ($body['room_key'] ?? null) !== $room->room_key || ($body['lease_epoch'] ?? null) !== $oldEpoch) {
                throw BusinessException::forbidden('COLLABORATION_REFERENCE_FENCE_INVALID', '技术引用收口缺少原恢复请求');
            }
        }
        return $room;
    }

    public function fencedReferenceReservations(int $documentId, DocumentOwnerReference $owner, int $oldEpoch): array
    {
        $room = $this->lockedFencedRoom($documentId, $owner, $oldEpoch);
        $reservations = [];
        foreach (CollaborationGrant::where('room_id', $room->id)->where('lease_epoch', $oldEpoch)->where('purpose', 'references')->lock(true)->select() as $grant) {
            $reservations[] = ['reservation_key' => $grant->reservation_key,'digest' => $grant->grant_key,'file_keys' => self::json($grant->file_keys_json)];
        }
        return $reservations;
    }

    public function retireFencedReferences(int $documentId, DocumentOwnerReference $owner, int $oldEpoch): void
    {
        $room = $this->lockedFencedRoom($documentId, $owner, $oldEpoch);
        CollaborationGrant::where('room_id', $room->id)->where('lease_epoch', $oldEpoch)->delete();
    }

    public function prepareBusinessBarrier(TenantContext $current, int $documentId, DocumentOwnerReference $owner, string $action, string $operationId, string $inputSha256, int $stateSeq, int $authRevision, string $ownerRevision): BusinessBarrierPlan
    {
        $this->assertTransaction($current->tenantId);
        $this->commits->assertBusinessPreparation($current, $documentId, $owner, $action, $operationId, $inputSha256);
        $room = CollaborationRoom::where('document_id', $documentId)->lock(true)->find();
        $local = $room !== null && $action === 'restore' && $room->status === 'closed'
            && strtotime($room->lease_expires_at . ' UTC') * 1000 <= (int) floor(microtime(true) * 1000);
        if ($room === null || !$this->lifecycle->ownsRoom($room) || (int) $room->protocol_version !== 2
            || !$local && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $room->holder_key ?? '') !== 1
            || !($room->status === 'active' || $action === 'restore' && $room->status === 'closed') || (int) $room->lease_epoch < 1
            || RichTextProjection::canonical(self::json($room->binding_json)['owner']) !== RichTextProjection::canonical(self::ownerArray($owner))) {
            throw BusinessException::conflict('COLLAB_UNAVAILABLE', '当前原生房间不可准备业务屏障');
        }
        if ($local) {
            // The caller already holds Doc/state/operations before this Room lock.
            // A closed expired archived room has no live service custody to impersonate.
            ['document' => $document, 'state' => $state] = $this->commits->collaborationBaseline($documentId);
            $now = (int) floor(microtime(true) * 1000);
            if ($document === null || $document['status'] !== 'archived' || (int) $document['lease_epoch'] !== (int) $room->lease_epoch
                || (int) $document['auth_revision'] !== $authRevision || (int) $document['state_seq'] !== $stateSeq
                || $state === null || !is_string($state['yjs_state_binary'])
                || hash('sha256', $state['yjs_state_binary']) !== $state['state_sha256']
                || (int) $state['state_seq'] !== $stateSeq || (int) $room->persisted_state_seq !== $stateSeq
                || $room->baseline_sha256 !== $state['state_sha256'] || strtotime($room->lease_expires_at . ' UTC') * 1000 > $now
                || CollaborationGrant::where('room_id', $room->id)->where('lease_epoch', $room->lease_epoch)
                    ->where('purpose', 'connection')->where('mode', 'write')->where('expires_at_ms', '>', $now)->lock(true)->find() !== null
                || $this->commits->hasPendingCollaborationBusiness($documentId, $operationId)
                || CollaborationBarrier::where('room_id', $room->id)->whereNotNull('business_plan_json')->whereNull('released_at')->lock(true)->find() !== null) {
                throw BusinessException::conflict('COLLABORATION_CLOSED_BASELINE_NOT_FENCED', '归档房间尚有效或原业务未确认，不能本地恢复');
            }
        }
        $key = hash('sha256', $room->room_key . ':' . $operationId . ':' . $inputSha256);
        $plan = new BusinessBarrierPlan(['protocol' => 2,'binding' => self::json($room->binding_json),
            'operation_id' => $operationId,'action' => $action,'input_sha256' => $inputSha256,'barrier_key' => $key,
            'room_key' => $room->room_key,'service_instance_id' => $room->service_instance_id,
            'control_mode' => $local ? 'closed_persisted' : 'native','holder_key' => $local ? null : $room->holder_key,
            'lease_epoch' => (int) $room->lease_epoch,'base_state_seq' => $stateSeq,'base_auth_revision' => $authRevision,
            'owner_revision' => $ownerRevision,'deadline' => (time() + 60) * 1000]);
        CollaborationBarrier::create(['tenant_id' => $current->tenantId,'room_id' => $room->id,'connection_id' => null,
            'barrier_key' => $key,'lease_epoch' => $room->lease_epoch,
            'purpose' => $action === 'archive' ? 'archive' : 'restore','status' => 'draining','input_sha256' => $plan->hash(),
            'business_plan_json' => json_encode($plan->data, JSON_THROW_ON_ERROR),
            'drained_receipt_json' => $local ? json_encode(['phase' => 'closed_persisted'], JSON_THROW_ON_ERROR) : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', intdiv($plan->data['deadline'], 1000))]);
        $room->save(['status' => 'draining']);
        return $plan;
    }

    public function drainBusinessBarrier(BusinessBarrierPlan $plan): void
    {
        if ($plan->data['control_mode'] === 'closed_persisted') {
            // The original same-PDO preparation already fenced its real persisted baseline.
            Db::transaction(fn() => $this->lockBusinessPlan($plan));
            return;
        }
        $reply = $this->control->call('drain', $plan);
        if (!in_array($reply['phase'] ?? null, ['drained','offline'], true)) {
            throw BusinessException::conflict('COLLABORATION_CONTROL_UNKNOWN', '原生排空未确认');
        }
        Db::transaction(function () use ($plan, $reply): void {
            [, $barrier] = $this->lockBusinessPlan($plan);
            $barrier->save(['drained_receipt_json' => json_encode($reply, JSON_THROW_ON_ERROR)]);
        });
    }

    public function lockBusinessPlan(BusinessBarrierPlan $plan, bool $completed = false): array
    {
        $this->assertTransaction($plan->data['binding']['tenant_id']);
        $room = CollaborationRoom::where('room_key', $plan->data['room_key'])->lock(true)->find();
        $barrier = $room === null ? null : CollaborationBarrier::where('room_id', $room->id)->where('barrier_key', $plan->data['barrier_key'])->lock(true)->find();
        if ($room === null || $barrier === null || $barrier->business_plan_json === null
            || $barrier->input_sha256 !== $plan->hash()
            || RichTextProjection::canonical(self::json($barrier->business_plan_json)) !== RichTextProjection::canonical($plan->data)
            || RichTextProjection::canonical(self::json($room->binding_json)) !== RichTextProjection::canonical($plan->data['binding'])
            || $room->service_instance_id !== $plan->data['service_instance_id']
            || $plan->data['control_mode'] === 'native' && $room->holder_key !== $plan->data['holder_key']
            || !$completed && ((int) $room->lease_epoch !== $plan->data['lease_epoch'] || $room->status !== 'draining')
            || $completed && $barrier->business_sha256 === null) {
            throw BusinessException::conflict('COLLABORATION_BUSINESS_BINDING_INVALID', '业务屏障不是原始持久操作');
        }
        return [$room, $barrier];
    }

    public function lockDrainedBusinessBarrier(TenantContext $current, BusinessBarrierPlan $plan): DrainedBusinessBarrier
    {
        if (!$this->execution->current() instanceof AdminExecutionContext || $current->tenantId !== $plan->data['binding']['tenant_id']) {
            throw BusinessException::forbidden('DOCUMENT_BUSINESS_ACTOR_REQUIRED', '业务提交需要当前人员权限');
        }
        [$room, $barrier] = $this->lockBusinessPlan($plan);
        $reply = $barrier->drained_receipt_json === null ? null : self::json($barrier->drained_receipt_json);
        $state = $this->commits->collaborationBaseline((int) $room->document_id, true)['state'];
        if ($reply === null || ($plan->data['control_mode'] === 'closed_persisted'
                ? $reply !== ['phase' => 'closed_persisted']
                : !in_array($reply['phase'] ?? null, ['drained','offline'], true))
            || $state === null || !is_string($state['yjs_state_binary'])
            || $plan->data['control_mode'] === 'closed_persisted' && (int) $state['state_seq'] !== $plan->data['base_state_seq']
            || hash('sha256', $state['yjs_state_binary']) !== $state['state_sha256']
            || (int) $room->persisted_state_seq !== (int) $state['state_seq'] || $room->baseline_sha256 !== $state['state_sha256']) {
            throw BusinessException::conflict('COLLABORATION_BARRIER_NOT_DURABLE', '排空与实际持久状态不一致');
        }
        if ($reply['phase'] === 'drained') {
            $operation = $this->commits->collaborationStoreReceipt((int) $room->document_id, $reply['store_id'] ?? '');
            $receipt = $operation === null ? null : self::json($operation['result_json']);
            if ($receipt === null || !in_array($operation['action'], ['collaboration.store','collaboration.baseline'], true)
                || ($receipt['lease_epoch'] ?? null) !== $plan->data['lease_epoch']
                || ($receipt['target_progress'] ?? null) !== ($reply['target_progress'] ?? null)
                || (int) $state['state_seq'] !== ($reply['state_seq'] ?? null)
                || $state['state_sha256'] !== ($reply['binary_sha256'] ?? null)
                || ($receipt['state_seq'] ?? null) !== $reply['state_seq'] || ($receipt['binary_sha256'] ?? null) !== $reply['binary_sha256']) {
                throw BusinessException::conflict('COLLABORATION_BARRIER_NOT_DURABLE', '排空回执没有对应数据库提交');
            }
        }
        $barrier->save(['status' => 'committed','committed_state_seq' => $state['state_seq'],'committed_state_sha256' => $state['state_sha256']]);
        $proof = new DrainedBusinessBarrier($plan, (int) $state['state_seq'], $state['state_sha256'],
            $reply['phase'] === 'drained' ? $reply['store_id'] : null, $reply['phase'] === 'drained' ? $reply['target_progress'] : 0);
        $this->businessProofs[$proof] = ['root' => $this->execution->rootIdentity(),'pdo' => Db::connect()->getPdo()];
        return $proof;
    }

    public function consumeBusinessBarrier(DrainedBusinessBarrier $proof, DocumentBusinessReceipt $receipt): void
    {
        $held = $this->businessProofs[$proof] ?? null;
        if ($held === null || $held['root'] !== $this->execution->rootIdentity() || $held['pdo'] !== Db::connect()->getPdo()
            || $receipt->plan->hash() !== $proof->plan->hash() || ($receipt->data['lease_epoch'] ?? 0) <= $proof->plan->data['lease_epoch']) {
            throw BusinessException::forbidden('DOCUMENT_BUSINESS_PROOF_INVALID', '排空证明不属于本次事务');
        }
        $this->commits->assertStagedBusinessCommit($proof->plan, $receipt->data);
        [$room, $barrier] = $this->lockBusinessPlan($proof->plan);
        $barrier->save(['business_sha256' => self::businessHash($receipt->data),'business_result_json' => json_encode($receipt->data, JSON_THROW_ON_ERROR)]);
        $room->save(['persisted_state_seq' => $receipt->data['state_seq'],'baseline_sha256' => $receipt->data['binary_sha256'],
            'lease_epoch' => $receipt->data['lease_epoch'],'last_store_id' => null,'last_store_sha256' => null,
            'last_receipt_json' => null,'last_target_progress' => 0,'status' => $receipt->data['status'] === 'archived' ? 'closed' : 'draining']);
        CollaborationConnection::where('room_id', $room->id)->where('status', 'active')->update(['status' => 'closed']);
        unset($this->businessProofs[$proof]);
    }

    public function releaseBusinessBarrier(BusinessBarrierPlan $plan, DocumentBusinessReceipt $receipt): void
    {
        $known = CollaborationBarrier::where('barrier_key', $plan->data['barrier_key'])->find();
        if ($known !== null && $known->released_at !== null && $known->business_sha256 === self::businessHash($receipt->data)) {
            return;
        }
        if ($plan->data['control_mode'] === 'closed_persisted') {
            Db::transaction(function () use ($plan, $receipt): void {
                $this->commits->lockCollaborationBusinessRows($plan->data['binding']['document_id'], $plan->data['operation_id']);
                [$room, $barrier] = $this->lockBusinessPlan($plan, true);
                $this->commits->assertStagedBusinessCommit($plan, $receipt->data);
                if ($barrier->business_sha256 !== self::businessHash($receipt->data)
                    || (int) $room->lease_epoch !== $receipt->data['lease_epoch'] || $room->status !== 'draining'
                    || strtotime($room->lease_expires_at . ' UTC') > time()) {
                    throw BusinessException::conflict('COLLABORATION_CLOSED_BASELINE_NOT_FENCED', '原本地恢复提交不能释放其他房间状态');
                }
                // Preserve the expired service lease. The next authorized join claims its own holder.
                $barrier->save(['released_at' => gmdate('Y-m-d H:i:s')]);
                $room->save(['status' => 'active']);
            });
            return;
        }
        $reply = $this->control->call('release', $plan, $receipt);
        if (($reply['phase'] ?? null) !== 'released' || ($reply['result_epoch'] ?? null) !== $receipt->data['lease_epoch']
            || ($reply['status'] ?? null) !== $receipt->data['status']) {
            throw BusinessException::conflict('COLLABORATION_CONTROL_UNKNOWN', '原生释放未确认原业务提交');
        }
        Db::transaction(function () use ($plan, $receipt): void {
            [$room, $barrier] = $this->lockBusinessPlan($plan, true);
            if ($barrier->business_sha256 !== self::businessHash($receipt->data)) {
                throw BusinessException::conflict('DOCUMENT_IDEMPOTENCY_CONFLICT', '不能释放其他业务提交');
            }
            $barrier->save(['released_at' => gmdate('Y-m-d H:i:s')]);
            $room->save(['status' => $receipt->data['status'] === 'archived' ? 'closed' : 'active']);
        });
    }

    public static function businessHash(array $result): string
    {
        return self::hash($result);
    }

    private function assertTransaction(int $tenantId): void
    {
        if ($this->execution->current()?->tenantId() !== $tenantId || !Db::connect()->getPdo() instanceof \PDO
            || !Db::connect()->getPdo()->inTransaction()) {
            throw BusinessException::conflict('COLLABORATION_TRANSACTION_REQUIRED', '需要同一可信租户事务');
        }
    }

    public static function json(mixed $value): array
    {
        $value = is_array($value) ? $value : json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw BusinessException::conflict('RECOVERY_REQUIRED', '持久记录格式无效');
        }
        return $value;
    }

    public static function ownerArray(DocumentOwnerReference $owner): array
    {
        return ['module_key' => $owner->moduleKey,'object_type' => $owner->objectType,'object_id' => $owner->objectId,'slot' => $owner->slot];
    }

    private static function hash(array $data): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($data), JSON_THROW_ON_ERROR));
    }
}
