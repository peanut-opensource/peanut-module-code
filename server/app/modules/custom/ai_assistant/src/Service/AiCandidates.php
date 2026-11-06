<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\VersionSnapshot;
use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Modules\File\Contract\FileAccess;
use PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots;
use think\facade\Db;

final readonly class AiCandidates
{
    public function __construct(private AiAccess $access, private AiSources $sources, private AiConfiguration $configuration, private AiPrivateStore $private, private AiOutputVerifier $verifier, private AiTasks $tasks, private \PeanutAdmin\Modules\Task\Contract\TaskJobRuntime $native, private FileStorage $storage, private FileAccess $files, private FileDocumentSnapshots $fixedFiles, private DocumentCommands $commands, private DocumentQueries $documents) {}
    public function collect(TenantContext $c, int $id, int $version, string $request, ?string $recoveredFileKey = null): array
    {
        $this->access->find($c, 'task', $id, 'use');
        return $this->private->exclusive($c->tenantId, $id, fn(): array => $this->collectLocked($c, $id, $version, $request, $recoveredFileKey));
    }
    private function collectLocked(TenantContext $c, int $id, int $version, string $request, ?string $recoveredFileKey): array
    {
        $r = $this->access->find($c, 'task', $id, 'use');
        $d = AiAccess::data($r);
        if (($d['collect_request_id'] ?? $request) !== $request) {
            throw BusinessException::conflict('AI_COLLECTION_REQUEST_REQUIRED', '领取恢复须沿同一请求');
        }
        $this->current($c, $r);
        if (($d['collection_state'] ?? '') === 'collected') {
            AiCandidateGuard::assertClaim($d, AiCandidateGuard::claim($request, $c->memberId, (string) $r->job_key, $d['candidate']), (string) $r->job_key);
            return AiAccess::present($r);
        }
        $job = $this->native->jobs()->detail($this->tasks->nativeContext($c, 'read'), $r->job_key ?? '');
        if ($job->status !== 'succeeded' || $d['stage'] !== 'candidate') {
            throw BusinessException::conflict('AI_TECHNICAL_RESULT_REQUIRED', '须有原生任务最终成功和真实候选，排队/来源阻断不等于生成成功');
        }
        if (time() >= ($d['private_expires_at'] ?? 0)) {
            throw BusinessException::conflict('AI_PRIVATE_RESULT_EXPIRED', '私有候选已过期，不能新增交付');
        }
        $dir = $this->private->directory($c->tenantId, $id);
        $path = $dir . '/candidate.bin';
        $candidate = AiAccess::decode(file_get_contents($dir . '/candidate-meta.json'));
        if (($candidate['job_key'] ?? null) !== $r->job_key || ($candidate['request_hash'] ?? null) !== $r->request_hash) {
            throw new \RuntimeException('AI_CANDIDATE_BINDING_INVALID');
        }
        $actual = $this->verifier->verify($path, $candidate['result_type']);
        if ($actual['sha256'] !== $candidate['sha256'] || $actual['sha256'] !== $d['candidate']['sha256']
            || $candidate['result_type'] !== $d['candidate']['result_type']) {
            throw new \RuntimeException('AI_CANDIDATE_CHANGED');
        }
        $claim = AiCandidateGuard::claim($request, $c->memberId, (string) $r->job_key, $candidate);
        $owner = new DocumentOwnerReference('custom.ai-assistant', 'task', (string) $id, $claim['owner_slot']);
        $text = in_array($candidate['result_type'], ['text','transcript'], true);
        if ($text && $recoveredFileKey !== null) {
            throw BusinessException::invalid('AI_TEXT_FILE_RECOVERY_INVALID', '文本领取不能指定文件恢复来源');
        }
        if (!$text && !$this->access->permission($c, 'peanut.file.list')) {
            throw BusinessException::forbidden('AI_FILE_CLAIM_PERMISSION_DENIED', '认领File候选需要当前原生File查看权限');
        }
        $d = Db::transaction(function () use ($c, $id, $version, $request, $claim): array {
            $row = $this->access->find($c, 'task', $id, 'use', true);
            $data = AiAccess::data($row);
            $this->current($c, $row);
            if (($data['stage'] ?? '') !== 'candidate' || (string) $row->job_key !== $claim['job_key']
                || ($data['candidate']['sha256'] ?? null) !== $claim['sha256']
                || ($data['candidate']['result_type'] ?? null) !== $claim['result_type']) {
                throw BusinessException::conflict('AI_COLLECTION_BINDING_CHANGED', '待领取候选已经变化');
            }
            if (isset($data['collection_claim'])) {
                AiCandidateGuard::assertClaim($data, $claim, (string) $row->job_key);
            } else {
                if (isset($data['collect_request_id'])) {
                    throw BusinessException::conflict('AI_COLLECTION_ANCHOR_REQUIRED', '旧领取缺少可核实成员锚点，须保留原状态核对');
                }
                $this->access->cas($row, $version);
                $data['collect_request_id'] = $request;
                $data['collection_claim'] = $claim;
                $data['collection_state'] = 'preparing';
                $this->access->update($row, $data);
            }
            return $data;
        });
        $fileKey = null;
        $recovery = false;
        if (!$text) {
            $receiptIdentity = AiAccess::hash([$r->job_key,$request,$candidate['sha256']]);
            $receiptPath = $dir . '/collection-receipt.json';
            if (is_file($receiptPath)) {
                $receipt = AiAccess::decode(file_get_contents($receiptPath));
                if (($receipt['identity'] ?? null) !== $receiptIdentity) {
                    throw new \RuntimeException('AI_COLLECTION_RECEIPT_MISMATCH');
                }$fileKey = $receipt['file_key'];
            } elseif ($recoveredFileKey !== null) {
                $fileKey = $recoveredFileKey;
                $recovery = true;
            } else {
                if (in_array($d['collection_state'] ?? '', ['writing','unknown'], true)) {
                    throw BusinessException::conflict('AI_FILE_WRITE_UNKNOWN', '原File写入没有返回key，禁止盲重写；保留请求，核对当前可读同字节File后恢复');
                }
                Db::transaction(function () use ($c, $id, $claim): void {
                    $row = $this->access->find($c, 'task', $id, 'use', true);
                    $this->current($c, $row);
                    $data = AiAccess::data($row);
                    AiCandidateGuard::assertClaim($data, $claim, (string) $row->job_key);
                    if (($data['collection_state'] ?? '') !== 'preparing') {
                        throw BusinessException::conflict('AI_FILE_WRITE_UNKNOWN', '原写入须沿已知回执或同字节文件恢复');
                    }
                    $data['collection_state'] = 'writing';
                    $this->access->update($row, $data);
                });
                $extensions = ['image' => 'png','audio' => 'wav','video' => 'mp4'];
                $ext = $extensions[$candidate['result_type']];
                try {
                    $stored = $this->storage->storePath($c->tenantId, $c->memberId, 'document.resource', $path, 'ai-candidate-' . $id . '.' . $ext, $candidate['verified_mime_type']);
                    $fileKey = $stored['file_key'];
                    $this->private->write($dir, 'collection-receipt.json', AiAccess::json(['identity' => $receiptIdentity,'file_key' => $fileKey]));
                } catch (\Throwable $error) {
                    Db::transaction(function () use ($c, $id, $claim): void {
                        $row = $this->access->find($c, 'task', $id, 'use', true);
                        $data = AiAccess::data($row);
                        AiCandidateGuard::assertClaim($data, $claim, (string) $row->job_key);
                        $data['collection_state'] = 'unknown';
                        $this->access->update($row, $data);
                    });
                    throw $error;
                }
            }
            $f = $this->files->attachmentSnapshot($c, $fileKey);
            if ($f['sha256'] !== $candidate['sha256']) {
                throw new \RuntimeException('AI_RESULT_FILE_MISMATCH');
            }
        }
        $doc = $this->commands->create($c, $owner, $text ? 'rich_text' : 'file_resource', 'ai-result-create:' . hash('sha256', $request), $fileKey);
        $seq = 1;
        if ($text) {
            $content = AiCandidateGuard::textDocument(file_get_contents($path));
            $saved = $this->commands->saveSolo($c, (int) $doc['document_id'], 0, $content, 'ai-result-save:' . hash('sha256', $request));
            $seq = (int) $saved['state_seq'];
        }
        $saved = $this->commands->freeze($c, (int) $doc['document_id'], $seq, '保留AI人工候选固定版本', 'ai-result-freeze:' . hash('sha256', $request));
        $snapshot = $this->documents->ownerSaveSnapshot($c, (int) $doc['document_id'], (int) $saved['revision_id'], $owner);
        return Db::transaction(function () use ($c, $id, $request, $snapshot, $owner, $candidate, $text, $recovery, $claim): array {
            $r = $this->access->find($c, 'task', $id, 'use', true);
            $d = AiAccess::data($r);
            AiCandidateGuard::assertClaim($d, $claim, (string) $r->job_key);
            if (($d['collection_state'] ?? '') === 'collected') {
                return AiAccess::present($r);
            }
            if (($d['stage'] ?? '') !== 'candidate') {
                throw BusinessException::conflict('AI_COLLECTION_BINDING_CHANGED', '领取期间业务阶段已变化');
            }
            $this->current($c, $r);
            $this->documents->verifyPreparedVersion($c, $snapshot, $owner);
            AiCandidateGuard::assertSnapshot($snapshot, $candidate);
            $d['fixed_result'] = ['document_id' => $snapshot->documentId,'revision_id' => $snapshot->revisionId,'digest' => $snapshot->digest,'file_key' => $snapshot->fileKey,'sha256' => $snapshot->fileSha256,'verified_mime_type' => $snapshot->verifiedMimeType];
            $d['collect_request_id'] = $request;
            $d['collection_state'] = 'collected';
            $d['collected_by'] = $c->memberId;
            $d['collected_at'] = gmdate('c');
            $d['collection_source'] = $recovery ? 'authorized_same_bytes_original_write_unknown' : 'native_receipt';
            $d['stage'] = 'collected';
            $this->access->update($r, $d, 'ready_for_review');
            $result = AiAccess::present($r);
            $this->access->event($c, 'task', $r, 'collect', $request, [$candidate['sha256'],$r->job_key], $result);
            return $result;
        });
    }
    public function decide(TenantContext $c, int $id, int $version, string $decision, string $reason, string $request): array
    {
        return Db::transaction(function () use ($c, $id, $version, $decision, $reason, $request): array {
            $input = [$version,$decision,$reason];
            if ($saved = $this->access->replay($c, 'task', $id, 'decision', $request, $input, 'review')) {
                return $saved;
            }
            $r = $this->access->find($c, 'task', $id, 'review', true);
            $this->access->cas($r, $version);
            $d = AiAccess::data($r);
            if (($d['collection_state'] ?? '') !== 'collected' || !in_array($r->status, ['ready_for_review','returned'], true)) {
                throw BusinessException::conflict('AI_REVIEW_STATE_INVALID', '仅已领取候选可人工采纳或退回');
            }
            $this->current($c, $r);
            $this->fixedReadable($c, $d['fixed_result']);
            $d['decision'] = ['decision' => $decision,'reason' => $reason,'member_id' => $c->memberId,'at' => gmdate('c'),'fixed_result' => $d['fixed_result'],'source' => $d['source'],'business_approval' => false,'automatic_publication' => false];
            $this->access->update($r, $d, $decision === 'accept' ? 'accepted' : 'returned');
            $result = AiAccess::present($r);
            $this->access->event($c, 'task', $r, 'decision', $request, $input, $result);
            return $result;
        });
    }
    public function inspect(TenantContext $c, int $id): array
    {
        $r = $this->access->find($c, 'task', $id);
        $this->current($c, $r);
        $d = AiAccess::data($r);
        if (($d['collection_state'] ?? '') !== 'collected') {
            throw BusinessException::conflict('AI_COLLECTION_REQUIRED', '候选尚未完成真实授权领取');
        }
        $f = $d['fixed_result'];
        $snapshot = $this->documents->versionSnapshot($c, $f['document_id'], $f['revision_id']);
        $source = AiAccess::decode($r->source_snapshot);
        $text = $snapshot->structuredContent === null ? null : $this->text($snapshot->structuredContent['content']);
        return ['task' => AiAccess::present($r),'source_text' => $source['content_kind'] === 'rich_text' ? $source['text'] : null,'source' => $d['source'],'candidate_text' => $text,'fixed_result' => $f,'result_source' => $d['result_source'],'business_approval' => false];
    }
    public function prepareAccepted(TenantContext $c, int $id): array
    {
        $r = $this->access->find($c, 'task', $id);
        $this->current($c, $r);
        $binding = $this->acceptedBinding($r);
        $f = $binding['fixed_result'];
        $snapshot = $this->documents->versionSnapshot($c, $f['document_id'], $f['revision_id']);
        Db::transaction(fn() => $this->assertAccepted($c, $binding));
        return ['task_id' => $id,'task_version' => $binding['task_version'],'source_binding' => $binding['source_binding'],
            'decision' => $binding['decision'],'document_owner' => $binding['document_owner'],'snapshot' => $snapshot,
            'accepted_binding' => $binding,'business_approval' => false];
    }
    /** Caller must own the native business transaction; checks acceptance, not only Document readability. */
    public function assertAccepted(TenantContext $c, array $expected): void
    {
        if (!(($pdo = Db::connect()->getPdo()) instanceof \PDO) || !$pdo->inTransaction()) {
            throw BusinessException::conflict('AI_ACCEPTED_TRANSACTION_REQUIRED', '采纳绑定复核必须处于使用方业务事务内');
        }
        $id = \Custom\Modules\AiAssistant\Validation\AiInput::integer($expected['task_id'] ?? null);
        $r = $this->access->find($c, 'task', $id, 'view', true);
        $actual = $this->acceptedBinding($r);
        if (!hash_equals(AiAccess::hash($actual), AiAccess::hash($expected))) {
            throw BusinessException::conflict('AI_ACCEPTED_BINDING_CHANGED', '候选采纳、固定产物或来源已变化，旧业务绑定不得继续使用');
        }
        $this->current($c, $r);
        $this->fixedReadable($c, $actual['fixed_result']);
    }
    private function acceptedBinding(\think\Model $r): array
    {
        $d = AiAccess::data($r);
        if ($r->status !== 'accepted' || ($d['collection_state'] ?? '') !== 'collected'
            || ($d['decision']['decision'] ?? '') !== 'accept' || !is_array($d['fixed_result'] ?? null)) {
            throw BusinessException::conflict('AI_ACCEPTED_CANDIDATE_REQUIRED', '仅当前人工采纳的固定候选可被业务采用；撤回或替代后的旧采纳不可沿用');
        }
        $candidate = $d['candidate'] ?? [];
        $f = $d['fixed_result'];
        if (!is_int($candidate['size_bytes'] ?? null) || $candidate['size_bytes'] < 1
            || ($f['file_key'] !== null && ($candidate['sha256'] ?? '') !== $f['sha256'])) {
            throw BusinessException::conflict('AI_CANDIDATE_METADATA_REQUIRED', '候选缺少与固定字节一致的实际校验记录');
        }
        $source = AiAccess::decode($r->source_snapshot);
        return ['schema' => 1,'task_id' => (int) $r->id,'task_version' => (int) $r->version,
            'source_binding' => $d['source'],'source_owner' => $source['owner'],
            'capability' => $d['capability'],'consumer_module' => $d['consumer_module'],'result_source' => $d['result_source'],
            'result_type' => $candidate['result_type'],'size_bytes' => $candidate['size_bytes'],
            'media_info' => $candidate['media_info'] ?? [],'fixed_result' => $f,'decision' => $d['decision'],
            'document_owner' => ['module_key' => 'custom.ai-assistant','object_type' => 'task','object_id' => (string) $r->id,
                'slot' => AiCandidateGuard::slot((string) $r->job_key, $candidate['sha256'])],
            'business_approval' => false];
    }
    /** Revoke use of a fixed candidate without deleting bytes or resubmitting an external generation. */
    public function lifecycle(TenantContext $c, int $id, int $version, string $action, string $reason, string $request, ?int $replacementId, ?int $replacementVersion): array
    {
        if (!in_array($action, ['withdraw','rework','supersede'], true) || trim($reason) === '') {
            throw BusinessException::invalid('AI_INPUT_INVALID', '须选择实际采纳动作并填写依据');
        }
        if (($action === 'supersede') !== ($replacementId !== null && $replacementVersion !== null)
            || ($action !== 'supersede' && ($replacementId !== null || $replacementVersion !== null)) || $replacementId === $id) {
            throw BusinessException::invalid('AI_INPUT_INVALID', '显式替代须选择另一项已采纳任务及其固定版本');
        }
        return Db::transaction(function () use ($c, $id, $version, $action, $reason, $request, $replacementId, $replacementVersion): array {
            $ids = $replacementId === null ? [$id] : [$id,$replacementId];
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $taskId) {
                $this->access->find($c, 'task', $taskId, 'view', true);
            }
            $input = [$version,$action,$reason,$replacementId,$replacementVersion];
            if ($saved = $this->access->replay($c, 'task', $id, 'candidate.' . $action, $request, $input, 'review')) {
                return AiRequestStatus::candidateReceipt($c, $id, $request, AiAccess::hash($input), $saved);
            }
            $r = $this->access->find($c, 'task', $id, 'review', true);
            $this->access->cas($r, $version);
            $d = AiAccess::data($r);
            if (($d['collection_state'] ?? '') !== 'collected' || !is_array($d['fixed_result'] ?? null)
                || !in_array($r->status, $action === 'supersede' ? ['accepted','acceptance_withdrawn','rework_required'] : ($action === 'rework' ? ['accepted','acceptance_withdrawn'] : ['accepted']), true)) {
                throw BusinessException::conflict('AI_ACCEPTANCE_STATE_INVALID', '该固定候选已撤回、替代或尚未采纳，请核对当前状态');
            }
            $replacement = null;
            if ($action === 'supersede') {
                $next = $this->access->find($c, 'task', $replacementId, 'view', true);
                $this->access->cas($next, $replacementVersion);
                $replacement = $this->acceptedBinding($next);
                $originalSource = AiAccess::decode($r->source_snapshot);
                if ($replacement['source_binding']['document_id'] !== $d['source']['document_id']
                    || AiAccess::hash($replacement['source_owner']) !== AiAccess::hash($originalSource['owner'])
                    || $replacement['capability'] !== $d['capability'] || $replacement['consumer_module'] !== $d['consumer_module']) {
                    throw BusinessException::conflict('AI_REPLACEMENT_SOURCE_MISMATCH', '替代候选须属于同一来源文档、制作能力和使用模块；新版来源修订逐项保留');
                }
                $this->assertAccepted($c, $replacement);
            }
            // Withdrawal is allowed under current task view/review even when its old source/config was revoked.
            // It only removes business usability and never grants access to those revoked bytes.
            $d['acceptance_lifecycle'] = ['action' => $action,'reason' => $reason,'member_id' => $c->memberId,'at' => gmdate('c'),
                'previous_version' => $version,'source' => $d['source'],'fixed_result' => $d['fixed_result'],
                'accepted_decision' => $d['decision'],'replacement' => $replacement,'automatic_generation' => false,'business_approval' => false];
            $state = match ($action) {
                'withdraw' => 'acceptance_withdrawn','rework' => 'rework_required','supersede' => 'superseded',
            };
            $this->access->update($r, $d, $state);
            $result = AiAccess::present($r);
            $this->access->event($c, 'task', $r, 'candidate.' . $action, $request, $input, $result);
            return AiRequestStatus::candidateReceipt($c, $id, $request, AiAccess::hash($input), $result);
        });
    }
    public function resolve(TenantContext $c, int $id, string $operation): array
    {
        $r = $this->access->find($c, 'task', $id);
        $this->current($c, $r);
        $d = AiAccess::data($r);
        if (($d['collection_state'] ?? '') !== 'collected' || empty($d['fixed_result']['file_key'])) {
            throw BusinessException::conflict('AI_FILE_CANDIDATE_REQUIRED', '当前不是已领取的真实文件候选');
        }
        $f = $d['fixed_result'];
        $this->fixedReadable($c, $f);
        return $this->files->resolve($c, $f['file_key'], $operation, $operation === 'preview' ? $f['document_id'] : null);
    }
    private function current(TenantContext $c, $r): void
    {
        $this->sources->readable($c, AiAccess::decode($r->source_snapshot));
        $this->configuration->recheck($c, AiAccess::decode($r->sealed_config));
        $d = AiAccess::data($r);
        $file = $d['manual_input']['file'] ?? null;
        if (is_array($file) && ($d['collection_state'] ?? '') !== 'collected') {
            $current = $this->files->attachmentSnapshot($c, $file['file_key']);
            if ($current['sha256'] !== $file['sha256']) {
                throw BusinessException::conflict('AI_MANUAL_SOURCE_CHANGED', '人工上传来源已撤权或变化，不能新增领取/采纳');
            }
        }
    }
    private function fixedReadable(TenantContext $c, array $f): void
    {
        Db::transaction(function () use ($c, $f): void {
            $this->documents->detail($c, $f['document_id']);
            if ($f['file_key'] !== null) {
                $this->fixedFiles->assertDocumentSnapshotReadable($c, $f['document_id'], $f['file_key'], $f['sha256'], $f['verified_mime_type']);
            }
        });
    }
    private function text(array $node): string
    {
        if (($node['type'] ?? '') === 'text') {
            return $node['text'];
        }return implode(($node['type'] ?? '') === 'doc' ? "\n" : '', array_map($this->text(...), $node['content'] ?? []));
    }
}
