<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Service;

use app\common\exception\BusinessException;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Modules\Document\Contract\AcceptedStoreInput;
use PeanutAdmin\Modules\Document\Contract\DurableSaveReceipt;
use PeanutAdmin\Modules\Document\Model\Document;
use PeanutAdmin\Modules\Document\Model\DocumentState;
use PeanutAdmin\Modules\Document\Model\DocumentOperation;
use PeanutAdmin\Modules\Document\Model\DocumentFileReference;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use think\facade\Db;

/** Same-connection staged commit verification, not authority or an outer-commit ACK. */
final readonly class DocumentAcceptedCommitQuery
{
    public function __construct(private ExecutionContextStore $execution) {}

    /** Persisted collaboration basis on the caller's tenant transaction, never an ORM object. */
    public function collaborationBaseline(int $documentId, bool $lock = false): array
    {
        $this->assertCollaborationTransaction($documentId);
        $document = Document::where('id', $documentId)->lock($lock)->find();
        $state = DocumentState::where('document_id', $documentId)->lock($lock)->find();
        return ['document' => $document?->getData(), 'state' => $state?->getData()];
    }

    public function collaborationStoreReceipt(int $documentId, string $storeId, bool $lock = false): ?array
    {
        $this->assertCollaborationTransaction($documentId);
        return DocumentOperation::where('document_id', $documentId)->where('operation_id', $storeId)->lock($lock)->find()?->getData();
    }

    public function hasPendingCollaborationBusiness(int $documentId, ?string $exceptOperation = null, bool $lock = false): bool
    {
        $this->assertCollaborationTransaction($documentId);
        $query = DocumentOperation::where('document_id', $documentId)->where('action', '<>', 'collaboration.target')->where('phase', '<>', 'completed');
        if ($exceptOperation !== null) {
            $query->where('operation_id', '<>', $exceptOperation);
        }
        return $query->lock($lock)->find() !== null;
    }

    /** Original Doc -> State -> operation order before the consumer acquires its room. */
    public function lockCollaborationBusinessRows(int $documentId, string $operationId): void
    {
        $this->collaborationBaseline($documentId, true);
        $this->collaborationStoreReceipt($documentId, $operationId, true);
    }

    /** Bounded instance custody check; deliberately takes no Doc lock and enters no tenant Scope. */
    public function hasPendingRuntimeBusiness(int $tenantId, int $documentId): bool
    {
        $scope = $this->execution->current();
        if (!$scope instanceof \app\common\execution\InstanceExecutionContext
            || $scope->operation() !== 'collaboration.native-lifecycle' || $tenantId < 1 || $documentId < 1
            || !Db::connect()->getPdo()->inTransaction()) {
            self::denied();
        }
        return DocumentOperation::withoutTenantScope()->where('tenant_id', $tenantId)->where('document_id', $documentId)
            ->where('action', '<>', 'collaboration.target')->where('phase', '<>', 'completed')->find() !== null;
    }

    private function assertCollaborationTransaction(int $documentId): void
    {
        $scope = $this->execution->current();
        if (!($scope instanceof \app\common\execution\AdminExecutionContext || $scope instanceof SystemExecutionContext)
            || ($scope->tenantId() ?? 0) < 1 || $documentId < 1 || !Db::connect()->getPdo()->inTransaction()) {
            self::denied();
        }
    }

    public function assertBusinessPreparation(\PeanutAdmin\Kernel\Auth\TenantContext $current, int $id, \PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference $owner, string $action, string $operationId, string $inputSha256): void
    {
        $scope = $this->execution->current();
        if (!$scope instanceof \app\common\execution\AdminExecutionContext || $scope->tenant !== $current || !Db::connect()->getPdo()->inTransaction()) {
            self::denied();
        }
        $document = Document::where('id', $id)->find();
        $op = DocumentOperation::where('document_id', $id)->where('operation_id', $operationId)->find();
        if ($document === null || $op === null || DocumentAuthorizationService::reference($document) != $owner
            || !(bool) $document->collaboration_enabled || $document->content_kind !== 'rich_text'
            || $op->action !== $action || $op->input_sha256 !== $inputSha256 || !in_array($op->phase, ['prepared','attention'], true)) {
            self::denied();
        }
    }

    public function assertStagedBusinessCommit(\PeanutAdmin\Modules\Document\Contract\BusinessBarrierPlan $plan, array $result): void
    {
        $scope = $this->execution->current();
        if (!$scope instanceof \app\common\execution\AdminExecutionContext || $scope->tenantId() !== $plan->data['binding']['tenant_id']
            || !Db::connect()->getPdo()->inTransaction()) {
            self::denied();
        }
        $id = $plan->data['binding']['document_id'];
        $doc = Document::where('id', $id)->find();
        $state = DocumentState::where('document_id', $id)->find();
        $op = DocumentOperation::where('document_id', $id)->where('operation_id', $plan->data['operation_id'])->find();
        if ($doc === null || $state === null || $op === null || $op->phase !== 'completed'
            || $op->action !== $plan->data['action'] || $op->input_sha256 !== $plan->data['input_sha256']
            || RichTextProjection::canonical(self::json($op->barrier_plan_json)) !== RichTextProjection::canonical($plan->data)
            || RichTextProjection::canonical(self::json($op->result_json)) !== RichTextProjection::canonical($result)
            || DocumentAuthorizationService::reference($doc) != $plan->owner
            || (int) $doc->state_seq !== ($result['state_seq'] ?? null) || (int) $state->state_seq !== (int) $doc->state_seq
            || (int) $doc->auth_revision !== ($result['auth_revision'] ?? null) || (int) $doc->lease_epoch !== ($result['lease_epoch'] ?? null)
            || $doc->status !== ($result['status'] ?? null) || !is_string($state->yjs_state_binary)
            || hash('sha256', $state->yjs_state_binary) !== ($result['binary_sha256'] ?? null)
            || $state->state_sha256 !== $result['binary_sha256']) {
            self::denied();
        }
    }

    public function assertStagedAcceptedCommit(AcceptedStoreInput $input, DurableSaveReceipt $receipt): void
    {
        $in = $input->tuple;
        $scope = $this->execution->current();
        $pdo = Db::connect()->getPdo();
        if (!$scope instanceof SystemExecutionContext || $scope->tenantId() !== $in['tenant_id']
            || !$pdo instanceof \PDO || !$pdo->inTransaction()) {
            self::denied();
        }
        // The caller already locked owner, Doc/state/operation, Room and File. These ordinary
        // reads see this connection's own staged writes without reversing that lock order.
        $operation = DocumentOperation::where('document_id', $in['document_id'])->where('operation_id', $in['store_id'])->find();
        $document = Document::where('id', $in['document_id'])->find();
        $state = DocumentState::where('document_id', $in['document_id'])->find();
        if ($operation === null || $document === null || $state === null
            || $operation->action !== 'collaboration.store' || !hash_equals($input->hash(), $operation->input_sha256)
            || DocumentAuthorizationService::reference($document) != $input->owner || $document->content_kind !== 'rich_text'
            || !(bool) $document->collaboration_enabled || !in_array($document->status, ['active','archiving'], true)
            || (int) $document->state_seq !== $in['base_state_seq'] + 1 || (int) $state->state_seq !== (int) $document->state_seq
            || (int) $document->lease_epoch !== $in['lease_epoch'] || $state->last_store_id !== $in['store_id']
            || !is_string($state->yjs_state_binary) || !hash_equals(hash('sha256', $state->yjs_state_binary), $in['binary_sha256'])
            || !hash_equals($state->state_sha256, $in['binary_sha256']) || $state->projection_schema_version !== RichTextProjection::SCHEMA) {
            self::denied();
        }
        $result = self::json($operation->result_json);
        $projection = RichTextProjection::fromStructured(self::json($state->projected_json));
        $expected = ['document_id' => $in['document_id'],'store_id' => $in['store_id'],'lease_epoch' => $in['lease_epoch'],
            'state_seq' => $in['base_state_seq'] + 1,'target_progress' => $in['target_progress'],
            'binary_sha256' => $in['binary_sha256'],'projection_sha256' => $in['projection_sha256'],
            'persisted_at' => $result['persisted_at'] ?? null];
        if (RichTextProjection::canonical($result) !== RichTextProjection::canonical($receipt->data)
            || RichTextProjection::canonical($result) !== RichTextProjection::canonical($expected)
            || !is_string($result['persisted_at']) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $result['persisted_at']) !== 1
            || $projection->json !== $input->projection->json || !hash_equals(hash('sha256', $projection->json), $in['projection_sha256'])
            || !hash_equals(AcceptedStoreInput::referencesDigest($projection->fileReferences), $in['reference_digest'])
            || (new \DateTimeImmutable($state->persisted_at, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')
                !== (new \DateTimeImmutable($result['persisted_at'], new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')) {
            self::denied();
        }
        $actual = [];
        foreach (DocumentFileReference::where('document_id', $in['document_id'])->where('usage', 'current')->order('file_key')->select() as $row) {
            if ((int) $row->derived_state_seq !== $in['base_state_seq'] + 1) {
                self::denied();
            }
            $actual[] = ['file_key' => $row->file_key,'variant_key' => $row->variant_key];
        }
        if ($actual !== $projection->fileReferences) {
            self::denied();
        }
    }

    private static function json(mixed $value): array
    {
        try {
            $value = is_array($value) ? $value : json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::denied();
        }
        if (!is_array($value)) {
            self::denied();
        }
        return $value;
    }

    private static function denied(): never
    {
        throw BusinessException::forbidden('DOCUMENT_STAGED_ACCEPTED_COMMIT_INVALID', '文档持久提交记录与接纳捕获不一致');
    }
}
