<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentRevisionComments;
use PeanutAdmin\Modules\Document\Model\Document;
use PeanutAdmin\Modules\Document\Model\DocumentCommentThread;
use PeanutAdmin\Modules\Document\Model\DocumentCommentMessage;
use PeanutAdmin\Modules\Document\Model\DocumentCommentOperation;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationReadFence;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use think\facade\Db;

/** One native authorization/owner/Document transaction. No draft writes, rebasing or synthetic actors. */
final readonly class DocumentRevisionCommentService implements DocumentRevisionComments
{
    public function __construct(
        private DocumentAuthorizationService $authorization,
        private DocumentQueries $documents,
        private DocumentOwnerRegistry $owners,
        private TenantAuthorizationReadFence $fence,
    ) {}

    public function threads(TenantContext $context, int $documentId, int $revisionId, int $limit = 20, ?int $beforeThreadId = null): array
    {
        self::page($limit, $beforeThreadId);
        return $this->withRevision($context, $documentId, $revisionId, false, function (TenantContext $actor, Document $document, array $source) use ($limit, $beforeThreadId): array {
            $query = DocumentCommentThread::where('document_id', $source['document_id'])->where('revision_id', $source['revision_id']);
            if ($beforeThreadId !== null) {
                $query->where('id', '<', $beforeThreadId);
            }
            $rows = $query->order('id', 'desc')->limit($limit + 1)->select()->all();
            $hasMore = count($rows) > $limit;
            $rows = array_slice($rows, 0, $limit);
            $items = array_map(function (DocumentCommentThread $thread) use ($document, $source): array {
                $this->assertThreadSource($thread, $document, $source);
                return self::threadData($thread);
            }, $rows);
            return ['source' => $source, 'can_write' => $this->canWrite($actor, $document),
                'items' => $items, 'next_before_thread_id' => $hasMore ? $items[array_key_last($items)]['thread_id'] : null];
        });
    }

    public function thread(TenantContext $context, int $documentId, int $revisionId, int $threadId, int $limit = 50, ?int $afterMessageId = null): array
    {
        self::page($limit, $afterMessageId);
        if ($threadId < 1) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论线程标识无效');
        }
        return $this->withRevision($context, $documentId, $revisionId, false, function (TenantContext $actor, Document $document, array $source) use ($threadId, $limit, $afterMessageId): array {
            $thread = $this->findThread($document, $source, $threadId);
            $query = DocumentCommentMessage::where('thread_id', $threadId);
            if ($afterMessageId !== null) {
                // A cursor from another thread cannot silently move this thread's history.
                if (DocumentCommentMessage::where('thread_id', $threadId)->where('id', $afterMessageId)->find() === null) {
                    throw BusinessException::invalid('DOCUMENT_COMMENT_CURSOR_INVALID', '评论历史游标不属于当前线程');
                }
                $query->where('id', '>', $afterMessageId);
            }
            $rows = $query->order('id', 'asc')->limit($limit + 1)->select()->all();
            $hasMore = count($rows) > $limit;
            $items = array_map(self::messageData(...), array_slice($rows, 0, $limit));
            return ['source' => $source, 'can_write' => $this->canWrite($actor, $document),
                'thread' => self::threadData($thread), 'messages' => $items,
                'next_after_message_id' => $hasMore ? $items[array_key_last($items)]['message_id'] : null];
        });
    }

    public function createThread(TenantContext $context, int $documentId, int $revisionId, array $input): array
    {
        return $this->command($context, $documentId, $revisionId, 'create', $input);
    }
    public function reply(TenantContext $context, int $documentId, int $revisionId, array $input): array
    {
        return $this->command($context, $documentId, $revisionId, 'reply', $input);
    }
    public function resolve(TenantContext $context, int $documentId, int $revisionId, array $input): array
    {
        return $this->command($context, $documentId, $revisionId, 'resolve', $input);
    }
    public function reopen(TenantContext $context, int $documentId, int $revisionId, array $input): array
    {
        return $this->command($context, $documentId, $revisionId, 'reopen', $input);
    }

    private function command(TenantContext $context, int $documentId, int $revisionId, string $action, array $input): array
    {
        self::input($action, $input);
        return $this->withRevision($context, $documentId, $revisionId, true, function (TenantContext $actor, Document $document, array $source) use ($action, $input): array {
            DocumentRevisionCommentText::assertBinding($source, $input);
            if ($action === 'create') {
                DocumentRevisionCommentText::assertAnchor($source, $input);
            }
            $hash = self::digest([$actor->tenantId, $actor->accountId, $actor->memberId,
                $source['document_id'], $source['revision_id'], $action, $input]);
            $replay = DocumentCommentOperation::where('document_id', $source['document_id'])
                ->where('request_id', $input['request_id'])->lock(true)->find();
            if ($replay !== null) {
                if (!hash_equals((string) $replay->input_sha256, $hash)) {
                    throw BusinessException::conflict('DOCUMENT_COMMENT_REQUEST_CONFLICT', '该请求号已绑定其他评论输入或成员');
                }
                $this->findThread($document, $source, (int) $replay->thread_id);
                return json_decode((string) $replay->result_json, true, 64, JSON_THROW_ON_ERROR);
            }
            $now = gmdate('Y-m-d H:i:s') . '.000';
            if ($action === 'create') {
                $thread = DocumentCommentThread::create([
                    'tenant_id' => $actor->tenantId, 'document_id' => $source['document_id'], 'revision_id' => $source['revision_id'],
                    'version_digest' => $source['version_digest'], 'owner_sha256' => self::ownerDigest($document),
                    'text_profile' => $source['text_profile'], 'text_sha256' => $source['text_sha256'],
                    'start_offset' => $input['start_offset'], 'end_offset' => $input['end_offset'], 'quote_text' => $input['quote'],
                    'status' => 'open', 'thread_revision' => 1,
                    'created_by_member_id' => $actor->memberId, 'created_by_account_id' => $actor->accountId,
                    'updated_by_member_id' => $actor->memberId, 'updated_by_account_id' => $actor->accountId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $thread = $this->findThread($document, $source, $input['thread_id']);
                if ((int) $thread->thread_revision !== $input['expected_thread_revision']) {
                    throw BusinessException::conflict('DOCUMENT_COMMENT_REVISION_CONFLICT', '评论线程已更新，请读取当前修订');
                }
                $expected = $action === 'reopen' ? 'resolved' : 'open';
                if ($thread->status !== $expected) {
                    throw BusinessException::conflict('DOCUMENT_COMMENT_STATE_CONFLICT', '当前评论状态不允许此操作');
                }
                $thread->save(['thread_revision' => $input['expected_thread_revision'] + 1,
                    'status' => $action === 'resolve' ? 'resolved' : 'open',
                    'updated_by_member_id' => $actor->memberId, 'updated_by_account_id' => $actor->accountId,
                    'updated_at' => $now]);
            }
            $message = DocumentCommentMessage::create([
                'tenant_id' => $actor->tenantId, 'thread_id' => (int) $thread->id,
                'thread_revision' => (int) $thread->thread_revision, 'action' => $action,
                'body' => $input['body'] ?? null, 'actor_member_id' => $actor->memberId,
                'actor_account_id' => $actor->accountId, 'created_at' => $now,
            ]);
            $result = ['document_id' => $source['document_id'], 'revision_id' => $source['revision_id'],
                'version_digest' => $source['version_digest'], 'text_profile' => $source['text_profile'], 'text_sha256' => $source['text_sha256'],
                'request_id' => $input['request_id'], 'action' => $action,
                'thread_id' => (int) $thread->id, 'thread_revision' => (int) $thread->thread_revision,
                'status' => (string) $thread->status, 'message_id' => (int) $message->id,
                'actor_member_id' => $actor->memberId, 'created_at' => $now];
            DocumentCommentOperation::create([
                'tenant_id' => $actor->tenantId, 'document_id' => $source['document_id'],
                'request_id' => $input['request_id'], 'action' => $action, 'input_sha256' => $hash,
                'thread_id' => (int) $thread->id, 'message_id' => (int) $message->id,
                'actor_member_id' => $actor->memberId, 'actor_account_id' => $actor->accountId,
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
            ]);
            return $result;
        });
    }

    private function withRevision(TenantContext $context, int $documentId, int $revisionId, bool $write, \Closure $operation): array
    {
        if ($documentId < 1 || $revisionId < 1) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论必须指定有效文档与固定版本');
        }
        // Preparation and its native proof must precede the transaction in the same validated root.
        return $this->authorization->run($context, function (TenantContext $actor) use ($documentId, $revisionId, $write, $operation): array {
            $action = $write ? 'edit' : 'view';
            $document = $this->authorization->document($actor, $documentId, $action);
            $owner = DocumentAuthorizationService::reference($document);
            $policy = $this->owners->requirePolicy($owner);
            $view = $document->status === 'archived' ? 'archive.read' : 'view';
            $permissions = array_values(array_unique([...$policy->authorizationPermissionKeys($view),
                ...($write ? $policy->authorizationPermissionKeys('edit') : [])]));
            $modules = array_values(array_unique(['peanut.document', 'peanut.rich-text', 'peanut.file', $owner->moduleKey]));
            sort($permissions, SORT_STRING);
            sort($modules, SORT_STRING);
            $authority = $this->fence->snapshot($actor, $permissions, $modules);
            $snapshot = $this->documents->versionSnapshot($actor, $documentId, $revisionId);
            $source = DocumentRevisionCommentText::source($snapshot);
            $source['owner'] = ['module_key' => $owner->moduleKey, 'object_type' => $owner->objectType,
                'object_id' => $owner->objectId, 'slot' => $owner->slot];
            return Db::transaction(function () use ($actor, $documentId, $action, $owner, $permissions, $modules, $authority, $snapshot, $source, $operation): array {
                // Native IAM locks come before owner, Document, revision and comment locks.
                $this->fence->lockAndAssert($actor, $permissions, $modules, $authority);
                $document = $this->authorization->document($actor, $documentId, $action, true);
                if (DocumentAuthorizationService::reference($document) != $owner) {
                    throw BusinessException::conflict('DOCUMENT_COMMENT_OWNER_CHANGED', '评论业务归属已改变');
                }
                $this->documents->verifyPreparedVersion($actor, $snapshot, $owner);
                $result = $operation($actor, $document, $source);
                // Replays and writes both retain current authority through the final native fence.
                $this->fence->lockAndAssert($actor, $permissions, $modules, $authority);
                $this->authorization->run($actor, function (TenantContext $current) use ($documentId, $action, $owner, $snapshot): void {
                    $document = $this->authorization->document($current, $documentId, $action, true);
                    if (DocumentAuthorizationService::reference($document) != $owner) {
                        throw BusinessException::conflict('DOCUMENT_COMMENT_OWNER_CHANGED', '评论业务归属已改变');
                    }
                    $this->documents->verifyPreparedVersion($current, $snapshot, $owner);
                });
                return $result;
            });
        });
    }

    private function findThread(Document $document, array $source, int $id): DocumentCommentThread
    {
        $thread = DocumentCommentThread::where('document_id', $source['document_id'])->where('revision_id', $source['revision_id'])
            ->where('id', $id)->lock(true)->find();
        if ($thread === null) {
            throw BusinessException::notFound('DOCUMENT_COMMENT_THREAD_NOT_FOUND', '当前固定版本没有该评论线程');
        }
        $this->assertThreadSource($thread, $document, $source);
        return $thread;
    }

    private function assertThreadSource(DocumentCommentThread $thread, Document $document, array $source): void
    {
        if (!hash_equals((string) $thread->owner_sha256, self::ownerDigest($document))) {
            throw BusinessException::conflict('DOCUMENT_COMMENT_OWNER_CHANGED', '评论业务归属已改变');
        }
        DocumentRevisionCommentText::assertAnchor($source, [
            'version_digest' => (string) $thread->version_digest, 'text_profile' => (string) $thread->text_profile,
            'text_sha256' => (string) $thread->text_sha256, 'start_offset' => (int) $thread->start_offset,
            'end_offset' => (int) $thread->end_offset, 'quote' => (string) $thread->quote_text,
        ]);
    }

    private function canWrite(TenantContext $actor, Document $document): bool
    {
        try {
            $this->authorization->document($actor, (int) $document->id, 'edit');
            return true;
        } catch (BusinessException $error) {
            // Independent view checks remain mandatory when edit scope or business state denies writing.
            if (!in_array($error->httpStatus, [403, 404, 409], true)) {
                throw $error;
            }
            return false;
        }
    }

    private static function input(string $action, array $input): void
    {
        $fields = ['request_id', 'version_digest', 'text_profile', 'text_sha256',
            ...($action === 'create' ? ['start_offset', 'end_offset', 'quote'] : ['thread_id', 'expected_thread_revision']),
            ...(in_array($action, ['create', 'reply'], true) ? ['body'] : [])];
        if (count($fields) !== count($input) || array_diff(array_keys($input), $fields) !== []) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论字段缺失或包含未知字段');
        }
        foreach (['request_id', 'version_digest', 'text_profile', 'text_sha256'] as $field) {
            if (!is_string($input[$field] ?? null)) {
                throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论标识或摘要无效');
            }
        }
        if (preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $input['request_id']) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $input['version_digest']) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $input['text_sha256']) !== 1
            || $input['text_profile'] !== RichTextProjection::TEXT_PROFILE) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论请求号、摘要或文本格式无效');
        }
        foreach ($action === 'create' ? ['start_offset', 'end_offset'] : ['thread_id', 'expected_thread_revision'] as $field) {
            if (!is_int($input[$field] ?? null) || $input[$field] < ($action === 'create' ? 0 : 1)) {
                throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论区间或线程修订无效');
            }
        }
        foreach ($action === 'create' ? ['quote', 'body'] : ($action === 'reply' ? ['body'] : []) as $field) {
            $text = $input[$field] ?? null;
            $max = $field === 'quote' ? DocumentRevisionCommentText::MAX_QUOTE_LENGTH : 4000;
            if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') < 1
                || mb_strlen($text, 'UTF-8') > $max || ($field === 'body' && preg_match('/\S/u', $text) !== 1)) {
                throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论正文或引用长度无效');
            }
        }
    }

    private static function page(int $limit, ?int $cursor): void
    {
        if ($limit < 1 || $limit > 100 || ($cursor !== null && $cursor < 1)) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论分页参数无效');
        }
    }
    private static function ownerDigest(Document $document): string
    {
        $owner = DocumentAuthorizationService::reference($document);
        return self::digest([$owner->moduleKey, $owner->objectType, $owner->objectId, $owner->slot]);
    }
    private static function digest(array $input): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    private static function threadData(DocumentCommentThread $thread): array
    {
        return ['thread_id' => (int) $thread->id, 'document_id' => (int) $thread->document_id,
            'revision_id' => (int) $thread->revision_id, 'version_digest' => (string) $thread->version_digest,
            'text_profile' => (string) $thread->text_profile, 'text_sha256' => (string) $thread->text_sha256,
            'start_offset' => (int) $thread->start_offset, 'end_offset' => (int) $thread->end_offset,
            'quote' => (string) $thread->quote_text, 'status' => (string) $thread->status,
            'thread_revision' => (int) $thread->thread_revision,
            'created_by_member_id' => (int) $thread->created_by_member_id, 'updated_by_member_id' => (int) $thread->updated_by_member_id,
            'created_at' => (string) $thread->created_at, 'updated_at' => (string) $thread->updated_at];
    }
    private static function messageData(DocumentCommentMessage $message): array
    {
        return ['message_id' => (int) $message->id, 'thread_id' => (int) $message->thread_id,
            'thread_revision' => (int) $message->thread_revision, 'action' => (string) $message->action,
            'body' => $message->body, 'actor_member_id' => (int) $message->actor_member_id, 'created_at' => (string) $message->created_at];
    }
}
