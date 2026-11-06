<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use Custom\Modules\AiAssistant\Definition\AiDefinitions;

/** Business invariants for one immutable candidate and its original collection request. */
final readonly class AiCandidateGuard
{
    public static function slot(string $jobKey, string $sha256): string
    {
        if ($jobKey === '' || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw BusinessException::conflict('AI_CANDIDATE_BINDING_INVALID', '候选须绑定真实任务与固定字节');
        }
        return 'result_' . substr(AiAccess::hash([$jobKey, $sha256]), 0, 40);
    }

    public static function assertManualReplaceable(array $details, string $request, string $type): void
    {
        if (isset($details['candidate']) || isset($details['fixed_result']) || isset($details['collect_request_id'])
            || isset($details['collection_claim']) || in_array($details['stage'] ?? '', ['candidate', 'collected'], true)
            || isset($details['manual_request_id']) && $details['manual_request_id'] !== $request) {
            throw BusinessException::conflict('AI_CANDIDATE_IMMUTABLE', '已有候选、领取或另一人工提交；保留原历史并另建任务');
        }
        if ((AiDefinitions::RESULT_TYPES[$details['capability'] ?? ''] ?? null) !== $type) {
            throw BusinessException::invalid('AI_INPUT_INVALID', '人工候选类型与本任务的制作能力不符');
        }
    }

    public static function claim(string $request, int $memberId, string $jobKey, array $candidate): array
    {
        $sha256 = $candidate['sha256'] ?? '';
        $type = $candidate['result_type'] ?? '';
        if ($request === '' || $memberId < 1 || !in_array($type, ['text', 'transcript', 'image', 'audio', 'video'], true)) {
            throw BusinessException::conflict('AI_COLLECTION_BINDING_INVALID', '领取须绑定原请求、实际成员和候选类型');
        }
        return ['request_id' => $request, 'member_id' => $memberId, 'job_key' => $jobKey,
            'sha256' => $sha256, 'result_type' => $type, 'owner_slot' => self::slot($jobKey, $sha256)];
    }

    public static function assertClaim(array $details, array $claim, string $currentJob): void
    {
        if (!is_array($details['collection_claim'] ?? null)
            || !hash_equals(AiAccess::hash($claim), AiAccess::hash($details['collection_claim']))
            || ($details['collect_request_id'] ?? null) !== $claim['request_id']
            || $currentJob !== $claim['job_key']
            || ($details['candidate']['sha256'] ?? null) !== $claim['sha256']
            || ($details['candidate']['result_type'] ?? null) !== $claim['result_type']) {
            throw BusinessException::conflict('AI_COLLECTION_BINDING_CHANGED', '领取成员、原请求或候选绑定已变化，不能重新覆盖');
        }
    }


    public static function textDocument(string $text): array
    {
        $lines = explode("\n", AiProtocol::normalizeText($text));
        if (count($lines) > 10000) {
            throw BusinessException::invalid('AI_RESULT_TEXT_TOO_LARGE', '候选文本段落超出限制');
        }
        return ['schemaVersion' => 'peanut.richtext/1', 'editorModel' => 'tiptap-prosemirror',
            'content' => ['type' => 'doc', 'content' => array_map(static fn(string $line): array =>
                ['type' => 'paragraph'] + ($line === '' ? [] : ['content' => [['type' => 'text', 'text' => $line]]]), $lines)],
            'annotations' => []];
    }

    public static function assertSnapshot(\PeanutAdmin\Modules\Document\Contract\VersionSnapshot $snapshot, array $candidate): void
    {
        if (!in_array($candidate['result_type'], ['text', 'transcript'], true)) {
            if ($snapshot->contentKind !== 'file_resource' || $snapshot->fileSha256 !== $candidate['sha256']) {
                throw new \RuntimeException('AI_FIXED_OUTPUT_MISMATCH');
            }
            return;
        }
        if ($snapshot->contentKind !== 'rich_text' || $snapshot->structuredContent === null || $snapshot->references !== []) {
            throw new \RuntimeException('AI_FIXED_OUTPUT_MISMATCH');
        }
        $paragraphs = $snapshot->structuredContent['content']['content'] ?? [];
        $lines = [];
        foreach ($paragraphs as $paragraph) {
            $line = '';
            foreach ($paragraph['content'] ?? [] as $node) {
                $line .= is_string($node['text'] ?? null) ? $node['text'] : '';
            }
            $lines[] = $line;
        }
        $plain = AiProtocol::normalizeText(implode("\n", $lines));
        if (!hash_equals($candidate['sha256'], hash('sha256', $plain))
            || !hash_equals(AiAccess::hash(self::textDocument($plain)), AiAccess::hash($snapshot->structuredContent))) {
            throw new \RuntimeException('AI_FIXED_OUTPUT_MISMATCH');
        }
    }
    public static function authorizeDocument(string $action, array $details, int $memberId, string $jobKey, string $slot): void
    {
        if ($slot !== self::slot($jobKey, $details['candidate']['sha256'] ?? '')) {
            throw BusinessException::forbidden('AI_DOCUMENT_SLOT_MISMATCH', '文档须属于当前候选的唯一固定位置');
        }
        if (($details['collection_state'] ?? '') === 'collected') {
            if ($action !== 'view' || !isset($details['fixed_result'])) {
                throw BusinessException::forbidden('AI_RESULT_DOCUMENT_IMMUTABLE', '已领取固定候选不开放编辑或新版本');
            }
            return;
        }
        if (!in_array($action, ['create', 'edit', 'version.create'], true)
            || !in_array($details['collection_state'] ?? '', ['preparing', 'writing', 'unknown'], true)) {
            throw BusinessException::forbidden('AI_CANDIDATE_NOT_COLLECTED', '候选尚未完成当前授权领取');
        }
        $claim = self::claim((string) ($details['collect_request_id'] ?? ''), $memberId, $jobKey, $details['candidate'] ?? []);
        self::assertClaim($details, $claim, $jobKey);
    }
}
