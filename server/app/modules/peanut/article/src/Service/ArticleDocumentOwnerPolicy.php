<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerPolicy;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerState;

/** Article owns publication and lifecycle; internal Document permissions remain independent. */
final readonly class ArticleDocumentOwnerPolicy implements DocumentOwnerPolicy, \PeanutAdmin\Modules\Document\Contract\DocumentPublicationOwnerPolicy
{
    public function __construct(private AdminAuthorizationQuery $authorization, private \app\common\execution\CurrentExecutionContext $execution) {}

    public function publishedBinding(int $tenantId, DocumentOwnerReference $owner): array
    {
        $current = $this->execution->current();
        if ($current instanceof \app\common\execution\AdminExecutionContext) {
            $context = $this->execution->tenantAdmin();
            if (!$this->authorization->decide($context, $this->authorization->principal($context), 'peanut.article.update-status')->allowed) {
                throw BusinessException::forbidden('ARTICLE_PUBLICATION_DENIED', '无权发布资讯');
            }
        } elseif (!$current instanceof \app\common\execution\ConsumerExecutionContext) {
            throw BusinessException::forbidden('ARTICLE_PUBLICATION_CONTEXT_REQUIRED', '资讯公开入口无效');
        }
        $state = $this->resolvePersistedOwner($tenantId, $owner, true);
        $article = Article::where('id', (int) $owner->objectId)->where('is_show', 1)->lock(true)->find();
        if ($state->archived || $article === null) {
            throw BusinessException::notFound('ARTICLE_NOT_FOUND', '文章不存在或已下架');
        }
        if ($article->body_document_id === null || $article->body_revision_id === null || $article->body_source_state_seq === null
            || !is_string($article->body_snapshot_sha256) || preg_match('/^[a-f0-9]{64}$/D', $article->body_snapshot_sha256) !== 1) {
            throw BusinessException::conflict('ARTICLE_PUBLIC_BODY_NOT_SAVED', '请先正式保存正文固定版本');
        }
        return ['document_id' => (int) $article->body_document_id, 'revision_id' => (int) $article->body_revision_id,
            'source_state_seq' => (int) $article->body_source_state_seq, 'snapshot_sha256' => $article->body_snapshot_sha256];
    }
    public function resolveOwner(TenantContext $context, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState
    {
        return $this->resolvePersistedOwner($context->tenantId, $owner, $forUpdate);
    }
    public function resolvePersistedOwner(int $tenantId, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState
    {
        if ($tenantId < 1 || $this->execution->tenantId() !== $tenantId) {
            throw BusinessException::forbidden('ARTICLE_DOCUMENT_TENANT_MISMATCH', '资讯归属与当前租户不一致');
        }
        if ($owner->moduleKey !== 'peanut.article' || $owner->objectType !== 'article' || $owner->slot !== 'body'
            || preg_match('/^[1-9][0-9]*$/D', $owner->objectId) !== 1 || strlen($owner->objectId) > 18) {
            throw BusinessException::notFound('ARTICLE_DOCUMENT_OWNER_INVALID', '不支持的资讯文档归属或槽位');
        }
        $query = Article::withTrashed()->where('id', (int) $owner->objectId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $article = $query->find();
        if ($article === null) {
            throw BusinessException::notFound('ARTICLE_NOT_FOUND', '资讯不存在');
        }
        return new DocumentOwnerState($tenantId, $owner, $article->trashed(), hash('sha256', json_encode([
            $article->id,$article->cid,$article->title,$article->desc,$article->abstract,$article->image,
            $article->author,$article->content,$article->click_virtual,$article->sort,
            $article->update_time,$article->delete_time,$article->is_show,
            $article->body_document_id,$article->body_revision_id,$article->body_source_state_seq,$article->body_snapshot_sha256,
        ], JSON_THROW_ON_ERROR)));
    }
    public function authorizationPermissionKeys(string $action): array
    {
        $permission = match ($action) {
            'view' => 'peanut.article.detail', 'archive.read' => 'peanut.article.recycle.detail',
            'create','edit','version.create' => 'peanut.article.edit', 'archive' => 'peanut.article.delete',
            'restore','restore.prepare' => 'peanut.article.restore', 'archive.prepare' => 'peanut.article.delete','purge' => 'peanut.article.force-delete', default => throw BusinessException::forbidden('ARTICLE_DOCUMENT_ACTION_DENIED', '不支持的资讯文档操作'),
        };
        return [$permission];
    }
    public function authorize(TenantContext $context, string $action, DocumentOwnerState $owner): void
    {
        $permission = $this->authorizationPermissionKeys($action)[0];
        if (!$this->authorization->decide($context, $this->authorization->principal($context), $permission)->allowed) {
            throw BusinessException::forbidden('ARTICLE_DOCUMENT_DENIED', '无权操作资讯文档');
        }
        if (in_array($action, ['view','create','edit','version.create'], true) && $owner->archived
            || in_array($action, ['archive','archive.read','purge'], true) && !$owner->archived
            || in_array($action, ['restore','archive.prepare'], true) && $owner->archived
            || $action === 'restore.prepare' && !$owner->archived) {
            throw BusinessException::forbidden('ARTICLE_DOCUMENT_STATE_DENIED', '资讯生命周期不允许此文档操作');
        }
    }
    public function stateRevision(DocumentOwnerState $owner): string
    {
        return $owner->revision;
    }
}
