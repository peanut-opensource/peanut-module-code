<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use Custom\Modules\AiAssistant\Definition\AiDefinitions as D;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Contract\FileAccess;
use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use think\facade\Db;

final readonly class AiManualCandidates
{
    public function __construct(private AiAccess $access, private AiSources $sources, private AiConfiguration $configuration, private AiPrivateStore $private, private FileAccess $files, private FileStorage $storage, private AiTasks $tasks, private TaskJobRuntime $native, private AiTaskAuthorization $authorization) {}
    public function submit(TenantContext $c, int $id, int $version, string $type, ?string $text, ?string $fileKey, string $reason, string $request): array
    {
        $this->access->find($c, 'task', $id, 'use');
        return $this->private->exclusive($c->tenantId, $id, fn(): array => $this->submitLocked($c, $id, $version, $type, $text, $fileKey, $reason, $request));
    }
    private function submitLocked(TenantContext $c, int $id, int $version, string $type, ?string $text, ?string $fileKey, string $reason, string $request): array
    {
        $input = [$version,$type,$text,$fileKey,$reason];
        $plan = Db::transaction(function () use ($c, $id, $version, $type, $input, $request): array {
            if ($saved = $this->access->replay($c, 'task', $id, 'manualCandidate', $request, $input, 'use')) {
                return ['replay' => $saved];
            }
            $r = $this->access->find($c, 'task', $id, 'use', true);
            $this->access->cas($r, $version);
            $d = AiAccess::data($r);
            AiCandidateGuard::assertManualReplaceable($d, $request, $type);
            $job = $r->job_key ? $this->native->jobs()->detail($this->tasks->nativeContext($c, 'read'), $r->job_key) : null;
            if ($job !== null && in_array($job->status, ['queued','running'], true)) {
                throw BusinessException::conflict('AI_TASK_ACTIVE', '先等待或取消在途任务，再登记人工候选');
            }
            $this->sources->readable($c, AiAccess::decode($r->source_snapshot));
            $this->configuration->recheck($c, AiAccess::decode($r->sealed_config));
            return ['record' => $r,'data' => $d];
        });
        if (isset($plan['replay'])) {
            return $plan['replay'];
        }
        $dir = $this->private->directory($c->tenantId, $id);
        $origin = null;
        if (in_array($type, ['text','transcript'], true)) {
            if ($fileKey !== null || $text === null) {
                throw BusinessException::invalid('AI_MANUAL_TEXT_REQUIRED', '文字候选须提供实际正文，不能同时附文件');
            }
            $bytes = AiProtocol::normalizeText($text);
            $this->private->write($dir, 'candidate.bin', $bytes);
            $source = 'manual_text_entry';
        } else {
            if ($text !== null || $fileKey === null) {
                throw BusinessException::invalid('AI_MANUAL_FILE_REQUIRED', '文件候选须选择真实File');
            }
            $origin = $this->files->attachmentSnapshot($c, $fileKey);
            if ($origin['size_bytes'] > D::OUTPUT_LIMIT) {
                throw BusinessException::invalid('AI_MANUAL_FILE_TOO_LARGE', '候选超出32MiB');
            }
            $opened = $this->storage->openForTenant($c->tenantId, $fileKey);
            try {
                if (hash_file('sha256', $opened['path']) !== $origin['sha256']) {
                    throw BusinessException::conflict('AI_MANUAL_FILE_CHANGED', '人工文件字节已经变化');
                }$bytes = file_get_contents($opened['path']);
                if ($bytes === false || strlen($bytes) > D::OUTPUT_LIMIT) {
                    throw new \RuntimeException('AI_MANUAL_COPY_FAILED');
                }$this->private->write($dir, 'candidate.bin', $bytes);
            } finally {
                if ($opened['temporary'] && is_file($opened['path'])) {
                    unlink($opened['path']);
                }
            }
            $source = 'manual_upload';
        }
        $sha = hash_file('sha256', $dir . '/candidate.bin');
        return Db::transaction(function () use ($c, $id, $version, $type, $reason, $request, $input, $origin, $source, $sha): array {
            if ($saved = $this->access->replay($c, 'task', $id, 'manualCandidate', $request, $input, 'use')) {
                return $saved;
            }
            $r = $this->access->find($c, 'task', $id, 'use', true);
            $this->access->cas($r, $version);
            $d = AiAccess::data($r);
            AiCandidateGuard::assertManualReplaceable($d, $request, $type);
            $this->sources->readable($c, AiAccess::decode($r->source_snapshot));
            $this->configuration->recheck($c, AiAccess::decode($r->sealed_config));
            $old = $r->job_key;
            $d['manual_input'] = ['sha256' => $sha,'result_type' => $type,'file' => $origin,'source' => $source,'submitted_by' => $c->memberId,'reason' => $reason,'provider_verified' => false];
            $d['manual_request_id'] = $request;
            $d['stage'] = 'manual_candidate_prepared';
            $d['result_source'] = $source;
            $d['execution_block'] = null;
            $d['private_expires_at'] = time() + 86400;
            $this->access->update($r, $d, 'preparing_manual_candidate');
            $definition = app()->make(AiManualTask::class);
            $job = $this->native->publisher($definition)->publish($this->authorization->authorize($c, $id), $definition->taskType(), ['task_id' => $id], 'ai-manual:' . $c->tenantId . ':' . $id . ':' . $request);
            $r->job_key = $job->jobKey;
            $r->actor_binding = AiAccess::json(AiTasks::actor($c));
            $d['previous_native_job_key'] = $old;
            $d['native_job_key'] = $job->jobKey;
            $this->access->update($r, $d, 'queued');
            $result = AiAccess::present($r);
            $this->access->event($c, 'task', $r, 'manualCandidate', $request, $input, $result);
            return $result;
        });
    }
}
