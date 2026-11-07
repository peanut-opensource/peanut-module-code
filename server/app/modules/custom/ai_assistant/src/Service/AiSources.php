<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use Custom\Modules\AiAssistant\Definition\AiDefinitions as D;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots;
use think\facade\Db;

final readonly class AiSources
{
    public function __construct(private DocumentQueries $documents, private FileStorage $storage, private FileDocumentSnapshots $files) {}
    /** Called outside a business transaction and only from the actual Admin boundary. */
    public function prepare(TenantContext $c, int $documentId, int $revisionId, string $capability): array
    {
        $detail = $this->documents->detail($c, $documentId);
        $o = $detail['owner'];
        $owner = new DocumentOwnerReference($o['module_key'], $o['object_type'], $o['object_id'], $o['slot']);
        $version = $this->documents->versionSnapshot($c, $documentId, $revisionId);
        if ($capability === 'transcription' && ($version->contentKind !== 'file_resource' || $version->fileKey === null || !preg_match('#^(audio|video)/#', $version->verifiedMimeType ?? ''))) {
            throw BusinessException::invalid('AI_AUDIO_SOURCE_REQUIRED', '转写须选择实际已固定的音视频Document版本');
        }
        if ($capability !== 'transcription' && $version->contentKind !== 'rich_text') {
            throw BusinessException::invalid('AI_TEXT_SOURCE_REQUIRED', '本能力须选择真实富文本Document固定版本；未配置OCR，不从文件伪造文字');
        }
        $text = $version->structuredContent === null ? '' : self::plainText($version->structuredContent['content']);
        if (mb_strlen($text) > 32000) {
            throw BusinessException::invalid('AI_SOURCE_TOO_LARGE', '固定源文字超出本批允许范围');
        }
        $private = ['document_id' => $documentId,'revision_id' => $revisionId,'digest' => $version->digest,'content_kind' => $version->contentKind,'structured_content' => $version->structuredContent,'text' => $text,'file_key' => $version->fileKey,'sha256' => $version->fileSha256,'verified_mime_type' => $version->verifiedMimeType,'owner' => $o];
        return ['owner' => $owner,'snapshot' => $version,'private' => $private,'summary' => array_diff_key($private, array_flip(['structured_content','text','owner']))];
    }
    public function verify(TenantContext $c, array $prepared): void
    {
        $this->grants()->verifyTaskSourceEligibility($c, $prepared['snapshot'], $prepared['owner']);
    }
    public function readable(TenantContext $c, array $source): void
    {
        Db::transaction(function () use ($c, $source): void {
            $detail = $this->documents->detail($c, $source['document_id']);
            if (AiAccess::hash($detail['owner']) !== AiAccess::hash($source['owner'])) {
                throw BusinessException::conflict('AI_SOURCE_OWNER_CHANGED', '来源归属已改变');
            }
            if ($source['content_kind'] === 'file_resource') {
                $this->files->assertDocumentSnapshotReadable($c, $source['document_id'], $source['file_key'], $source['sha256'], $source['verified_mime_type']);
            }
        });
    }
    public function copyAudio(TenantContext $c, array $source, string $path): array
    {
        $this->readable($c, $source);
        $opened = $this->storage->openForTenant($c->tenantId, $source['file_key']);
        try {
            $size = filesize($opened['path']);
            if (!is_int($size) || $size < 1 || $size > D::INPUT_FILE_LIMIT || hash_file('sha256', $opened['path']) !== $source['sha256']) {
                throw BusinessException::conflict('AI_SOURCE_BYTES_INVALID', '固定音视频字节不匹配或超出16MiB');
            }if (!copy($opened['path'], $path) || !chmod($path, 0600)) {
                throw new \RuntimeException('AI_SOURCE_PREPARATION_FAILED');
            }return ['sha256' => $source['sha256'],'size_bytes' => $size];
        } finally {
            if ($opened['temporary'] && is_file($opened['path'])) {
                unlink($opened['path']);
            }
        }
    }
    /** Prepare a fresh public proof outside the caller's submission/retry transaction. */
    public function prepareAuthorization(TenantContext $c, array $source): array
    {
        $detail = $this->documents->detail($c, $source['document_id']);
        if (AiAccess::hash($detail['owner']) !== AiAccess::hash($source['owner'])) {
            throw BusinessException::conflict('AI_SOURCE_OWNER_CHANGED', '来源归属已改变');
        }
        $o = $detail['owner'];
        $snapshot = $this->documents->versionSnapshot($c, $source['document_id'], $source['revision_id']);
        $this->assertFixedSource($source, $snapshot);
        return ['snapshot' => $snapshot, 'owner' => new DocumentOwnerReference($o['module_key'], $o['object_type'], $o['object_id'], $o['slot'])];
    }

    public function issueAuthorization(TenantContext $c, array $prepared, string $jobKey, int $expiresAt, string $requestId): array
    {
        return $this->grants()->issueTaskSource($c, $prepared['snapshot'], $prepared['owner'], self::taskBinding($jobKey), $expiresAt, $requestId);
    }

    public function revokeAuthorization(TenantContext $c, array $receipt): void
    {
        $this->grants()->revokeTaskSource($c, $receipt['grant_key']);
    }

    /** Redeem through the actual native worker and return only the revalidated fixed source. */
    public function authorizeExternalSend(\PeanutAdmin\Modules\Task\Contract\JobExecution $execution, ?array $receipt, array $source, string $audioPath): array
    {
        if (!is_array($receipt) || !is_string($receipt['grant_key'] ?? null)) {
            throw BusinessException::conflict('AI_SOURCE_GRANT_REQUIRED', '该任务没有固定来源授权，请由原提交成员重新授权后重试');
        }
        $snapshot = $this->grants()->redeemTaskSource($execution, $receipt['grant_key'], self::taskBinding($execution->jobKey));
        $this->assertFixedSource($source, $snapshot);
        if ($snapshot->contentKind === 'file_resource') {
            $check = static function () use ($snapshot, $audioPath): void {
                $size = is_file($audioPath) ? filesize($audioPath) : false;
                if (!is_int($size) || $size < 1 || $size > D::INPUT_FILE_LIMIT || hash_file('sha256', $audioPath) !== $snapshot->fileSha256) {
                    throw BusinessException::conflict('AI_SOURCE_BYTES_INVALID', '准备后的音视频字节已改变或过期');
                }
            };
            $check();
            $execution->addAuthorizationCheck($check);
        }
        $source['structured_content'] = $snapshot->structuredContent;
        $source['text'] = $snapshot->structuredContent === null ? '' : self::plainText($snapshot->structuredContent['content']);
        $execution->assertLeaseOwned();
        return $source;
    }

    private function assertFixedSource(array $source, \PeanutAdmin\Modules\Document\Contract\VersionSnapshot $snapshot): void
    {
        $expected = ['document_id' => $snapshot->documentId, 'revision_id' => $snapshot->revisionId, 'digest' => $snapshot->digest,
            'content_kind' => $snapshot->contentKind, 'structured_content' => $snapshot->structuredContent,
            'text' => $snapshot->structuredContent === null ? '' : self::plainText($snapshot->structuredContent['content']),
            'file_key' => $snapshot->fileKey, 'sha256' => $snapshot->fileSha256, 'verified_mime_type' => $snapshot->verifiedMimeType];
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $source) || AiAccess::hash($source[$key]) !== AiAccess::hash($value)) {
                throw BusinessException::conflict('AI_SOURCE_FIXED_VERSION_CHANGED', '任务来源与真实固定文档版本不一致');
            }
        }
    }

    private function grants(): \PeanutAdmin\Modules\Document\Contract\DocumentSourceGrants
    {
        if (!$this->documents instanceof \PeanutAdmin\Modules\Document\Contract\DocumentSourceGrants) {
            throw BusinessException::conflict('SOURCE_AUTHORIZATION_BRIDGE_REQUIRED', '当前文档模块尚未提供后台固定来源授权');
        }
        return $this->documents;
    }

    private static function taskBinding(string $jobKey): \PeanutAdmin\Modules\Task\Contract\SourceTaskBinding
    {
        return new \PeanutAdmin\Modules\Task\Contract\SourceTaskBinding($jobKey, 'custom.ai-assistant', 'custom.ai-assistant.task', 'use', 'custom.ai-assistant.generate');
    }
    private static function plainText(array $node): string
    {
        if (($node['type'] ?? '') === 'text') {
            return $node['text'];
        }
        $parts = [];
        foreach ($node['content'] ?? [] as $child) {
            $parts[] = self::plainText($child);
        }
        if (($node['type'] ?? '') === 'hardBreak') {
            return "\n";
        }
        $separator = in_array($node['type'] ?? '', ['doc','blockquote','bulletList','orderedList','listItem','table'], true) ? "\n" : (($node['type'] ?? '') === 'tableRow' ? "\t" : '');
        return implode($separator, $parts);
    }
}
