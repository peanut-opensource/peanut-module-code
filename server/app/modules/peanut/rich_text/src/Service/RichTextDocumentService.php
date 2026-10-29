<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\RichText\Service;

use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\http\PageResult;
use app\common\support\PaginationInput;
use PeanutAdmin\Modules\RichText\Model\RichTextDocument;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use think\facade\Db;

final readonly class RichTextDocumentService
{
    private const DOCUMENT_VERSION = 'peanut.richtext/1';

    public function __construct(
        private CurrentExecutionContext $executionContext,
        private string $collaborationUrl,
        private string $collaborationSecret,
    ) {}

    public function lists(array $params): PageResult
    {
        $pagination = PaginationInput::from($params, 1, 15);
        $query = RichTextDocument::where([])->field([
            'id', 'title', 'revision', 'created_by_member_id', 'updated_by_member_id',
            'create_time', 'update_time',
        ]);
        if (trim((string) ($params['title'] ?? '')) !== '') {
            $query->whereLike('title', '%' . trim((string) $params['title']) . '%');
        }
        $page = $pagination->result($query->order(['update_time' => 'desc', 'id' => 'desc']));
        return $page->map(static fn(mixed $row): array => $row instanceof \think\Model
            ? $row->toArray()
            : (array) $row);
    }

    /** @return array<string,mixed> */
    public function detail(int $id): array
    {
        $document = RichTextDocument::where('id', $id)->findOrEmpty();
        if ($document->isEmpty()) {
            return [];
        }
        $row = $document->toArray();
        $row['document'] = $row['document_json'];
        $row['collaboration_state'] = base64_encode((string) ($row['collaboration_state'] ?? ''));
        unset($row['document_json'], $row['tenant_id'], $row['delete_time']);
        return $row;
    }

    public function add(array $params): bool
    {
        $memberId = $this->executionContext->tenantAdmin()->memberId;
        RichTextDocument::create([
            'title' => trim((string) $params['title']),
            'document_json' => $this->encodeDocument($params['document']),
            'collaboration_state' => $this->decodeCollaborationState($params['collaboration_state'] ?? ''),
            'revision' => 1,
            'created_by_member_id' => $memberId,
            'updated_by_member_id' => $memberId,
        ]);
        return true;
    }

    public function edit(array $params): bool
    {
        $id = (int) $params['id'];
        $revision = (int) $params['revision'];
        Db::transaction(function () use ($id, $revision, $params): void {
            $document = RichTextDocument::where('id', $id)->lock(true)->findOrEmpty();
            if ($document->isEmpty()) {
                throw BusinessException::notFound('RICH_TEXT_DOCUMENT_NOT_FOUND', '文档不存在');
            }
            if ((int) $document['revision'] !== $revision) {
                throw BusinessException::conflict('RICH_TEXT_DOCUMENT_REVISION_CONFLICT', '文档已被其他人更新，请重新打开后编辑');
            }
            $document->save([
                'title' => trim((string) $params['title']),
                'document_json' => $this->encodeDocument($params['document']),
                'collaboration_state' => $this->decodeCollaborationState($params['collaboration_state'] ?? ''),
                'revision' => $revision + 1,
                'updated_by_member_id' => $this->executionContext->tenantAdmin()->memberId,
            ]);
        });
        return true;
    }

    public function delete(int $id): bool
    {
        $document = RichTextDocument::where('id', $id)->findOrEmpty();
        if ($document->isEmpty()) {
            throw BusinessException::notFound('RICH_TEXT_DOCUMENT_NOT_FOUND', '文档不存在');
        }
        $document->delete();
        return true;
    }

    /** @return array{enabled:bool,url:?string,document_name:string,token:?string,expires_at:?int} */
    public function collaboration(int $id): array
    {
        if (RichTextDocument::where('id', $id)->findOrEmpty()->isEmpty()) {
            throw BusinessException::notFound('RICH_TEXT_DOCUMENT_NOT_FOUND', '文档不存在');
        }
        $tenant = $this->executionContext->tenantAdmin();
        $documentName = sprintf('tenant:%d/rich-text:%d', $tenant->tenantId, $id);
        if ($this->collaborationUrl === '' && $this->collaborationSecret === '') {
            return ['enabled' => false, 'url' => null, 'document_name' => $documentName, 'token' => null, 'expires_at' => null];
        }
        if (!$this->validCollaborationConfiguration()) {
            throw new \runtimeException('RICH_TEXT_COLLABORATION_CONFIG_INVALID');
        }
        $expiresAt = time() + 300;
        $payload = $this->base64UrlEncode((string) json_encode([
            'version' => 1,
            'document_name' => $documentName,
            'tenant_id' => $tenant->tenantId,
            'member_id' => $tenant->memberId,
            'scope' => 'read-write',
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->collaborationSecret, true));
        return [
            'enabled' => true,
            'url' => $this->collaborationUrl,
            'document_name' => $documentName,
            'token' => $payload . '.' . $signature,
            'expires_at' => $expiresAt,
        ];
    }

    private function validCollaborationConfiguration(): bool
    {
        $scheme = strtolower((string) parse_url($this->collaborationUrl, PHP_URL_SCHEME));
        return in_array($scheme, ['ws', 'wss'], true)
            && is_string(parse_url($this->collaborationUrl, PHP_URL_HOST))
            && strlen($this->collaborationSecret) >= 32;
    }

    private function encodeDocument(mixed $document): string
    {
        if (!is_array($document)) {
            throw BusinessException::invalid('RICH_TEXT_PROJECTION_INVALID', '富文本结构无效');
        }
        return RichTextProjection::fromStructured($document)->json;
    }

    private function decodeCollaborationState(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || strlen($decoded) > 8 * 1024 * 1024) {
            throw BusinessException::invalid('RICH_TEXT_COLLABORATION_STATE_INVALID', '协同状态无效');
        }
        return $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
