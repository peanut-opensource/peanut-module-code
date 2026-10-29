<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Service;

use app\common\exception\BusinessException;
use app\common\services\ProductAssetReferenceService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Article\Contract\ArticleBodySaveInput;
use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Model\ArticleBodySave;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerState;

/** Article owns the business Save/binding; Document remains the sole mutable/immutable body owner. */
final readonly class ArticleBodyService
{
    public function __construct(
        private DocumentCommands $commands,
        private DocumentQueries $queries,
        private ProductAssetReferenceService $assets,
        private \think\App $app,
    ) {}

    private static function owner(int $id): DocumentOwnerReference
    {
        return new DocumentOwnerReference('peanut.article', 'article', (string) $id, 'body');
    }

    public function ensure(TenantContext $context, int $id, string $requestId): array
    {
        return $this->commands->runOwnerVersionSave($context, self::owner($id), function (TenantContext $current) use ($id, $requestId): array {
            $article = Article::where('id', $id)->lock(true)->find();
            if ($article === null) {
                throw BusinessException::notFound('ARTICLE_NOT_FOUND', '资讯不存在');
            }
            if ($article->body_document_id === null && (int) $article->is_show !== 0) {
                throw BusinessException::conflict('ARTICLE_PUBLIC_FIXED_VERSION_UNAVAILABLE', '接入正文前请先按原权限下架资讯');
            }
            if ($article->body_document_id === null && trim((string) $article->content) !== '') {
                throw BusinessException::conflict('ARTICLE_DOCUMENT_MIGRATION_REQUIRED', '已有 HTML 正文须显式迁移，不可创建空正文替代');
            }
            $result = $this->commands->create($current, self::owner($id), 'rich_text', $requestId);
            if ($article->body_document_id !== null && (int) $article->body_document_id !== $result['document_id']) {
                throw BusinessException::conflict('ARTICLE_DOCUMENT_BINDING_CHANGED', '资讯正文绑定已改变');
            }
            if ($article->body_document_id === null) {
                $article->save(['body_document_id' => $result['document_id']]);
            }
            return $result;
        });
    }

    public function editor(TenantContext $context, int $id): array
    {
        // The existing current-authorization transaction locks this owner before the callback.
        // Metadata and its CAS revision therefore describe the same Article row; no view grant is needed.
        return $this->commands->runOwnerVersionSave($context, self::owner($id), function (TenantContext $current, DocumentOwnerState $state) use ($id): array {
            $article = Article::where('id', $id)->lock(true)->find();
            if ($article === null || $article->body_document_id === null) {
                throw BusinessException::conflict('ARTICLE_DOCUMENT_REQUIRED', '请先接入既有资讯正文');
            }
            return $this->queries->ownerEditor($current, (int) $article->body_document_id, self::owner($id)) + [
                'article_id' => $id,'owner_revision' => $state->revision,'saved_binding' => self::binding($article),
                'article_metadata' => ['cid' => (int) $article->cid,'title' => (string) $article->title,
                    'desc' => (string) $article->desc,'abstract' => (string) $article->abstract,'image' => (string) $article->image,
                    'image_url' => $this->assets->forRead((string) $article->image),
                    'author' => (string) $article->author,'click_virtual' => (int) $article->click_virtual,
                    'sort' => (int) $article->sort,'is_show' => (int) $article->is_show],
            ];
        });
    }

    public function transport(TenantContext $context, int $id): array
    {
        $editor = $this->editor($context, $id);
        $guard = new \PeanutAdmin\Kernel\Module\ModuleGuard($this->app->make(\PeanutAdmin\Kernel\Module\ModuleRuntimeRepository::class));
        $guard->assertDeployment('peanut.collaboration');
        $guard->assertTenant($context->tenantId, 'peanut.collaboration', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        // Lazy optional public provider: a solo APP never constructs an unconfigured collaboration client.
        $transport = $this->app->make(\PeanutAdmin\Modules\Collaboration\Contract\CollaborationTransports::class)->ownerTransport($context, $editor['document_id'], self::owner($id));
        $fresh = $this->editor($context, $id);
        if ($fresh['document_id'] !== $transport['document_id'] || $fresh['authorization_revision'] !== $transport['authorization_revision']) {
            throw BusinessException::conflict('ARTICLE_DOCUMENT_CONTEXT_CHANGED', '协作传输准备期间正文授权已改变');
        }
        return $transport + ['article_id' => $id,'owner_revision' => $fresh['owner_revision'],'capabilities' => $fresh['capabilities']];
    }

    public function save(TenantContext $context, ArticleBodySaveInput $input, callable $validateCategory, callable $metadata): array
    {
        $owner = self::owner($input->articleId);
        $prepared = $this->commands->runOwnerVersionSave($context, $owner, function (TenantContext $current, DocumentOwnerState $state) use ($input): ?array {
            $request = ArticleBodySave::where('article_id', $input->articleId)->where('request_id', $input->requestId)->lock(true)->find();
            if ($request !== null) {
                self::assertRequest($current, $request, $input);
                if (in_array($request->phase, ['completed', 'conflicted'], true)) {
                    return self::json($request->result_json);
                }
            }
            $article = Article::where('id', $input->articleId)->lock(true)->find();
            if ($article === null || (int) $article->body_document_id !== $input->documentId) {
                throw BusinessException::conflict('ARTICLE_DOCUMENT_BINDING_CHANGED', '资讯正文绑定已改变');
            }
            if ($request === null) {
                $request = ArticleBodySave::create(['tenant_id' => $current->tenantId,'article_id' => $input->articleId,
                    'document_id' => $input->documentId,'request_id' => $input->requestId,'input_sha256' => $input->hash(),
                    'actor_account_id' => $current->accountId,'actor_member_id' => $current->memberId,'phase' => 'prepared','result_json' => null,'created_at' => gmdate('Y-m-d H:i:s')]);
            }
            if (!hash_equals($input->ownerRevision, $state->revision)) {
                return self::ownerConflict($request, $input);
            }
            $this->commands->beginExplicitTargetConsumption($current, $input->documentId, $input->expectedStateSeq,
                'Article Save ' . $input->hash(), $input->requestId);
            return null;
        });
        if ($prepared !== null) {
            return self::saveResult($prepared);
        }
        // Reject a mismatched owner before any freeze/control RPC, using the public edit-purpose resolver.
        $this->queries->ownerEditor($context, $input->documentId, $owner);
        // No owner/DB lock survives this boundary. Same operation id and full input hash are reused after unknown outcomes.
        $key = $input->requestId;
        $version = $this->commands->freeze($context, $input->documentId, $input->expectedStateSeq, 'Article Save ' . $input->hash(), $key);
        if (($version['document_id'] ?? null) !== $input->documentId || !is_int($version['revision_id'] ?? null)
            || isset($version['phase']) && $version['phase'] !== 'completed') {
            throw BusinessException::conflict('ARTICLE_BODY_SAVE_UNCONFIRMED', '正文固定版本尚未确认，继续原保存请求');
        }
        $snapshot = $this->queries->ownerSaveSnapshot($context, $input->documentId, $version['revision_id'], $owner);
        if ($snapshot->contentKind !== 'rich_text' || $snapshot->sourceStateSeq !== ($version['source_state_seq'] ?? null)) {
            throw BusinessException::conflict('ARTICLE_BODY_SAVE_UNCONFIRMED', '正文固定版本回执不一致');
        }
        $result = $this->commands->runOwnerVersionSave($context, $owner, function (TenantContext $current, DocumentOwnerState $state) use ($input, $owner, $snapshot, $validateCategory, $metadata): array {
            $request = ArticleBodySave::where('article_id', $input->articleId)->where('request_id', $input->requestId)->lock(true)->find();
            if ($request === null) {
                throw BusinessException::conflict('ARTICLE_BODY_SAVE_UNCONFIRMED', '保存请求不可用');
            }
            self::assertRequest($current, $request, $input);
            if (in_array($request->phase, ['completed', 'conflicted'], true)) {
                return self::json($request->result_json);
            }
            if (!hash_equals($input->ownerRevision, $state->revision)) {
                return self::ownerConflict($request, $input);
            }
            $article = Article::where('id', $input->articleId)->lock(true)->find();
            if ($article === null || (int) $article->body_document_id !== $input->documentId) {
                throw BusinessException::conflict('ARTICLE_DOCUMENT_BINDING_CHANGED', '资讯正文绑定已改变');
            }
            $validateCategory($input->metadata['cid']);
            $this->queries->verifyPreparedVersion($current, $snapshot, $owner);
            $binding = $snapshot->binding();
            $data = $metadata($current, $input->metadata);
            unset($data['content']);
            $article->save($data + ['body_revision_id' => $binding['revision_id'],'body_source_state_seq' => $binding['source_state_seq'],'body_snapshot_sha256' => $binding['snapshot_sha256']]);
            $result = ['article_id' => $input->articleId,'request_id' => $input->requestId,'phase' => 'completed','binding' => $binding,'published' => false];
            $request->save(['phase' => 'completed','result_json' => $result,'completed_at' => gmdate('Y-m-d H:i:s')]);
            return $result;
        });
        // Throw only after the native owner transaction commits its terminal receipt.
        return self::saveResult($result);
    }

    public function operation(TenantContext $context, int $id, string $requestId): array
    {
        if (preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $requestId) !== 1) {
            throw BusinessException::invalid('ARTICLE_BODY_SAVE_INPUT_INVALID', '保存请求标识无效');
        }
        return $this->commands->runOwnerVersionSave($context, self::owner($id), function (TenantContext $current) use ($id, $requestId): array {
            $request = ArticleBodySave::where('article_id', $id)->where('request_id', $requestId)->lock(true)->find();
            if ($request === null) {
                throw BusinessException::notFound('ARTICLE_BODY_SAVE_NOT_FOUND', '保存请求不存在');
            }
            if ((int) $request->actor_account_id !== $current->accountId || (int) $request->actor_member_id !== $current->memberId) {
                throw BusinessException::forbidden('ARTICLE_BODY_SAVE_ACTOR_DENIED', '只能继续原人员保存请求');
            }
            return ['article_id' => $id,'request_id' => $requestId,'document_id' => (int) $request->document_id,'phase' => $request->phase,
                'result' => $request->phase === 'completed' ? self::json($request->result_json) : null,
                'error_code' => $request->phase === 'conflicted' ? self::json($request->result_json)['error_code'] : null,
                'continuation' => $request->phase === 'prepared' ? 'same_request_same_payload' : null];
        });
    }

    private static function ownerConflict(ArticleBodySave $request, ArticleBodySaveInput $input): array
    {
        $result = ['article_id' => $input->articleId,'request_id' => $input->requestId,'document_id' => $input->documentId,
            'phase' => 'conflicted','error_code' => 'ARTICLE_BODY_SAVE_OWNER_CHANGED'];
        $request->save(['phase' => 'conflicted','result_json' => $result,'completed_at' => gmdate('Y-m-d H:i:s')]);
        return $result;
    }

    private static function saveResult(array $result): array
    {
        if (($result['phase'] ?? null) === 'conflicted') {
            throw BusinessException::conflict('ARTICLE_BODY_SAVE_OWNER_CHANGED', '保存期间资讯元数据或生命周期已改变');
        }
        return $result;
    }
    private static function assertRequest(TenantContext $current, ArticleBodySave $request, ArticleBodySaveInput $input): void
    {
        if ((int) $request->actor_account_id !== $current->accountId || (int) $request->actor_member_id !== $current->memberId) {
            throw BusinessException::forbidden('ARTICLE_BODY_SAVE_ACTOR_DENIED', '保存请求属于原人员');
        }
        if ((int) $request->document_id !== $input->documentId || !hash_equals($request->input_sha256, $input->hash())) {
            throw BusinessException::conflict('ARTICLE_BODY_SAVE_REQUEST_CONFLICT', '同一保存标识不可改变输入');
        }
    }
    private static function json(mixed $value): array
    {
        return is_array($value) ? $value : json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
    }
    private static function binding(Article $article): ?array
    {
        return $article->body_revision_id === null ? null : ['document_id' => (int) $article->body_document_id,'revision_id' => (int) $article->body_revision_id,
            'source_state_seq' => (int) $article->body_source_state_seq,'snapshot_sha256' => $article->body_snapshot_sha256];
    }
}
