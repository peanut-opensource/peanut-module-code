<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Contract\FileReferenceCommands;
use PeanutAdmin\Modules\File\Contract\CollaborationReferenceInput;
use PeanutAdmin\Modules\File\Contract\AcceptedReferenceTransfer;
use PeanutAdmin\Modules\File\Contract\ReservationReleaseInput;
use PeanutAdmin\Modules\File\Model\Storage\FileReference;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use think\facade\Db;

/** Owner and File changes share the caller's ThinkPHP transaction. */
final readonly class FileReferenceService implements FileReferenceCommands
{
    public function __construct(private FileDocumentReferenceRegistry $documents, private FileAuthorizationService $authorization, private AuditContractHost $audit, private CurrentExecutionContext $execution, private StorageService $storage) {}

    public function replaceDocumentReferences(TenantContext $context, int $documentId, array $fileKeys): void
    {
        $fileKeys = $this->validatedKeys($context, $documentId, $fileKeys);
        $policy = $this->documents->requirePolicy();
        Db::transaction(function () use ($context, $documentId, $fileKeys, $policy): void {
            // The owner locks and authorizes its persisted document in this same transaction.
            $owner = $policy->referencesForUpdate($context, $documentId);
            $expected = array_values(array_unique($owner['file_keys']));
            sort($expected);
            if ($fileKeys !== $expected || $owner['authorization_revision'] === '' || $owner['reference_digest'] === '') {
                throw BusinessException::forbidden('FILE_REFERENCE_OWNER_MISMATCH', '引用与文档当前内容不一致');
            }
            foreach ($fileKeys as $key) {
                $object = $this->storage->lockedReferenceObject($context, $key);
                $this->authorization->authorizeDocumentReference($context, $object, $documentId);
            }
            FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document')
                ->where('owner_id', (string) $documentId)->delete();
            foreach ($fileKeys as $key) {
                FileReference::create(['tenant_id' => $context->tenantId, 'file_key' => $key,
                    'owner_module_key' => 'peanut.document', 'owner_type' => 'document', 'owner_id' => (string) $documentId,
                    'reference_digest' => $owner['reference_digest'], 'updated_at' => gmdate('Y-m-d H:i:s')]);
            }
            $this->audit->appendTenantMember(
                $context,
                'file.references.replaced',
                'file.references.replace',
                'document',
                (string) $documentId,
                null,
                null,
                count($fileKeys),
                hash('sha256', implode("\0", $fileKeys)),
            );
        });
    }

    public function assertDocumentReferencesReadable(TenantContext $context, int $documentId, array $fileKeys): void
    {
        if (!(($pdo = Db::connect()->getPdo()) instanceof \PDO) || !$pdo->inTransaction()) {
            throw BusinessException::conflict('FILE_REFERENCE_TRANSACTION_REQUIRED', '历史引用复核必须在业务事务内');
        }
        $fileKeys = $this->validatedKeys($context, $documentId, $fileKeys);
        $this->documents->requirePolicy();
        // The Document caller already holds owner -> Document -> revision locks.
        foreach ($fileKeys as $key) {
            $object = $this->storage->lockedReferenceObject($context, $key);
            $this->authorization->authorizeDocumentReference($context, $object, $documentId);
        }
    }

    private function validatedKeys(TenantContext $context, int $documentId, array $fileKeys): array
    {
        if (count($fileKeys) > 1000) {
            throw BusinessException::invalid('FILE_REFERENCE_KEYS_INVALID', '文件引用数量超出上限');
        }
        $actor = $this->execution->tenantAdmin();
        if ($actor->tenantId !== $context->tenantId || $actor->memberId !== $context->memberId || $actor->accountId !== $context->accountId
            || $actor->clientKey !== $context->clientKey || $actor->authorizationRevision !== $context->authorizationRevision
            || !hash_equals($actor->sessionKey, $context->sessionKey)) {
            throw BusinessException::forbidden('FILE_REFERENCE_CONTEXT_MISMATCH', '引用与当前可信会话不一致');
        }
        return $this->keys($documentId, $fileKeys);
    }

    public function reserveCollaborationReferences(TenantContext $actor, CollaborationReferenceInput $input): void
    {
        $this->requireTransaction($input->tenantId);
        $keys = $this->validatedKeys($actor, $input->documentId, $input->fileKeys);
        if ($input->tenantId !== $actor->tenantId) {
            throw BusinessException::forbidden('FILE_ADMISSION_TENANT_MISMATCH', '接纳引用与租户不一致');
        }
        // This callback obtains/reuses owner -> Document locks BEFORE the first File object lock.
        $owner = $this->documents->requirePolicy()->collaborationReferencesForUpdate($actor, $input);
        if (($owner['authorization_revision'] ?? '') === '' || !isset($owner['current_file_keys'])) {
            throw BusinessException::forbidden('FILE_ADMISSION_POLICY_INVALID', '接纳引用证明无效');
        }
        foreach ($keys as $key) {
            $object = $this->storage->lockedReferenceObject($actor, $key);
            if (in_array($key, $owner['current_file_keys'], true)
                && \PeanutAdmin\Modules\File\Value\Storage\StoragePurpose::allowsDocumentPreviewInheritance($object['purpose'])) {
                $this->authorization->authorizeDocumentReference($actor, $object, $input->documentId);
            } else {
                // New, historical-only and sensitive references cannot borrow Document preview.
                $this->authorization->authorize($actor, $object, 'preview');
            }
        }
        $existing = FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document-collaboration')
            ->where('owner_id', $input->reservationKey)->lock(true)->select()->toArray();
        if ($existing !== []) {
            $old = array_column($existing, 'file_key');
            sort($old);
            if ($old !== $keys || count(array_filter($existing, fn(array $row): bool => !hash_equals($input->grantDigest, $row['reference_digest']))) > 0) {
                throw BusinessException::conflict('FILE_ADMISSION_DUPLICATE_MISMATCH', '接纳占用标识不能改变引用');
            }
            return;
        }
        foreach ($keys as $key) {
            FileReference::create(['tenant_id' => $input->tenantId,'file_key' => $key,'owner_module_key' => 'peanut.document',
                'owner_type' => 'document-collaboration','owner_id' => $input->reservationKey,'reference_digest' => $input->grantDigest,
                'updated_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    public function commitAcceptedDocumentReferences(AcceptedReferenceTransfer $input): void
    {
        $this->requireTransaction($input->tenantId);
        $proof = $this->documents->requirePolicy()->acceptedReferencesForUpdate($input);
        $keys = $this->keys($input->documentId, $proof['file_keys']);
        $old = FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document')
            ->where('owner_id', (string) $input->documentId)->column('file_key');
        $reserved = [];
        foreach ($proof['reservations'] as $reservation) {
            $reserved = [...$reserved, ...$reservation['file_keys']];
        }
        $all = $this->keys($input->documentId, array_values(array_unique([...$keys, ...$old, ...$reserved])));
        foreach ($all as $key) {
            $this->storage->lockedAcceptedReferenceObject($input->tenantId, $key);
        }
        $this->verifyReservations($proof['reservations']);
        if (array_diff($keys, [...$old, ...$reserved]) !== []) {
            throw BusinessException::forbidden('FILE_REFERENCE_UNRESERVED', '批次包含没有真实引用占用的新增资源');
        }
        FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document')
            ->where('owner_id', (string) $input->documentId)->delete();
        foreach ($keys as $key) {
            FileReference::create(['tenant_id' => $input->tenantId,'file_key' => $key,'owner_module_key' => 'peanut.document',
                'owner_type' => 'document','owner_id' => (string) $input->documentId,'reference_digest' => $proof['reference_digest'],
                'updated_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    public function reserveExplicitTargetReferences(TenantContext $actor, CollaborationReferenceInput $input): void
    {
        $this->requireTransaction($input->tenantId);
        $keys = $this->validatedKeys($actor, $input->documentId, $input->fileKeys);
        $proof = $this->documents->requirePolicy()->explicitTargetReferencesForUpdate($actor, $input, false);
        $this->verifyReservations($proof['reservations']);
        $reserved = array_merge([], ...array_map(static fn(array $row): array => $row['file_keys'], $proof['reservations']));
        foreach ($keys as $key) {
            if (in_array($key, $reserved, true)) {
                // Original before-broadcast File authorization remains protected by its exact reservation.
                $this->storage->lockedAcceptedReferenceObject($input->tenantId, $key);
            } else {
                $object = $this->storage->lockedReferenceObject($actor, $key);
                if (!in_array($key, $proof['current_file_keys'], true)) {
                    throw BusinessException::forbidden('FILE_TARGET_REFERENCE_UNPROVEN', '点击目标资源缺少真实当前引用或原授权占用');
                }
                $this->authorization->authorizeDocumentReference($actor, $object, $input->documentId);
            }
        }
        $existing = FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document-target')
            ->where('owner_id', $input->reservationKey)->lock(true)->select()->toArray();
        if ($existing !== []) {
            $old = array_column($existing, 'file_key');
            sort($old);
            if ($old !== $keys || count(array_filter($existing, fn(array $row): bool => $row['reference_digest'] !== $input->grantDigest)) > 0) {
                throw BusinessException::conflict('FILE_TARGET_IDEMPOTENCY_CONFLICT', '点击目标引用不能改变');
            }
            return;
        }
        foreach ($keys as $key) {
            FileReference::create(['tenant_id' => $input->tenantId,'file_key' => $key,'owner_module_key' => 'peanut.document',
                'owner_type' => 'document-target','owner_id' => $input->reservationKey,'reference_digest' => $input->grantDigest,
                'updated_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    public function consumeExplicitTargetReferences(TenantContext $actor, CollaborationReferenceInput $input): void
    {
        $this->requireTransaction($input->tenantId);
        $keys = $this->validatedKeys($actor, $input->documentId, $input->fileKeys);
        $proof = $this->documents->requirePolicy()->explicitTargetReferencesForUpdate($actor, $input, true);
        $rows = FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document-target')
            ->where('owner_id', $input->reservationKey)->lock(true)->select()->toArray();
        $actual = array_column($rows, 'file_key');
        sort($actual);
        if ($actual !== $keys || count(array_filter($rows, fn(array $row): bool => $row['reference_digest'] !== $input->grantDigest)) > 0
            || array_diff($keys, $proof['historical_file_keys']) !== []) {
            throw BusinessException::conflict('FILE_TARGET_REFERENCE_MISSING', '正式版本资源占用不完整');
        }
        foreach ($keys as $key) {
            $this->storage->lockedAcceptedReferenceObject($input->tenantId, $key);
            $existing = FileReference::where('file_key', $key)->where('owner_module_key', 'peanut.document')
                ->where('owner_type', 'document')->where('owner_id', (string) $input->documentId)->lock(true)->find();
            if ($existing === null) {
                FileReference::create(['tenant_id' => $input->tenantId,'file_key' => $key,'owner_module_key' => 'peanut.document',
                    'owner_type' => 'document','owner_id' => (string) $input->documentId,'reference_digest' => $proof['reference_digest'],
                    'updated_at' => gmdate('Y-m-d H:i:s')]);
            }
        }
        FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document-target')
            ->where('owner_id', $input->reservationKey)->delete();
        FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document')
            ->where('owner_id', (string) $input->documentId)->update(['reference_digest' => $proof['reference_digest']]);
    }

    public function cancelExplicitTargetReferences(TenantContext $actor, CollaborationReferenceInput $input): void
    {
        $this->requireTransaction($input->tenantId);
        $keys = $this->validatedKeys($actor, $input->documentId, $input->fileKeys);
        $proof = $this->documents->requirePolicy()->explicitTargetReferencesForUpdate($actor, $input, false);
        if (($proof['cancel'] ?? false) !== true) {
            throw BusinessException::forbidden('FILE_TARGET_CANCEL_UNPROVEN', '未证明本次明确放弃点击目标');
        }
        foreach ($keys as $key) {
            $this->storage->lockedAcceptedReferenceObject($input->tenantId, $key);
        }
        $rows = FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document-target')
            ->where('owner_id', $input->reservationKey)->lock(true)->select()->toArray();
        $actual = array_column($rows, 'file_key');
        sort($actual);
        if ($actual !== $keys || count(array_filter($rows, fn(array $row): bool => $row['reference_digest'] !== $input->grantDigest)) > 0) {
            throw BusinessException::conflict('FILE_TARGET_REFERENCE_MISSING', '原点击占用不能被另一目标替换');
        }
        FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document-target')
            ->where('owner_id', $input->reservationKey)->where('reference_digest', $input->grantDigest)->delete();
    }

    public function releaseSettledCollaborationReferences(ReservationReleaseInput $input): void
    {
        $this->requireTransaction($input->tenantId);
        $proof = $this->documents->requirePolicy()->settledReservationsForUpdate($input);
        $keys = [];
        foreach ($proof['reservations'] as $reservation) {
            $keys = [...$keys, ...$reservation['file_keys']];
        }
        foreach ($this->keys($input->documentId, array_values(array_unique($keys))) as $key) {
            $this->storage->lockedAcceptedReferenceObject($input->tenantId, $key);
        }
        $this->verifyReservations($proof['reservations']);
        foreach ($proof['reservations'] as $reservation) {
            FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document-collaboration')
                ->where('owner_id', $reservation['reservation_key'])->where('reference_digest', $reservation['digest'])->delete();
        }
    }

    private function verifyReservations(array $reservations): void
    {
        foreach ($reservations as $reservation) {
            $rows = FileReference::where('owner_module_key', 'peanut.document')->where('owner_type', 'document-collaboration')
                ->where('owner_id', $reservation['reservation_key'])->lock(true)->select()->toArray();
            $keys = array_column($rows, 'file_key');
            sort($keys);
            $expected = $reservation['file_keys'];
            sort($expected);
            if ($keys !== $expected || count(array_filter($rows, fn(array $row): bool => !hash_equals($reservation['digest'], $row['reference_digest']))) > 0) {
                throw BusinessException::conflict('FILE_ADMISSION_RESERVATION_MISSING', '已接纳资源占用不完整');
            }
        }
    }

    private function requireTransaction(int $tenantId): void
    {
        $pdo = Db::connect()->getPdo();
        if ($tenantId < 1 || $this->execution->tenantId() !== $tenantId || !$pdo instanceof \PDO || !$pdo->inTransaction()) {
            throw BusinessException::conflict('FILE_REFERENCE_TRANSACTION_REQUIRED', '资源接纳必须处于同一可信租户事务');
        }
    }

    private function keys(int $documentId, array $fileKeys): array
    {
        if ($documentId < 1 || count($fileKeys) > 3000 || $fileKeys !== array_values($fileKeys)) {
            throw BusinessException::invalid('FILE_REFERENCE_SET_INVALID', '资源引用无效');
        }
        foreach ($fileKeys as $key) {
            if (!is_string($key) || preg_match('/^file_[0-9a-f]{32}$/D', $key) !== 1) {
                throw BusinessException::invalid('FILE_REFERENCE_SET_INVALID', '仅允许稳定资源引用');
            }
        }
        $fileKeys = array_values(array_unique($fileKeys));
        sort($fileKeys);
        return $fileKeys;
    }
}
