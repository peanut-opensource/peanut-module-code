<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Document\Contract\DocumentRevisionComments;

/** Authenticated contextual entry: actor identity always comes from the native session. */
class DocumentRevisionCommentController extends BaseAdminController
{
    protected string $commandsClass = DocumentRevisionComments::class;
    protected string $queriesClass = DocumentRevisionComments::class;

    public function threads()
    {
        $input = $this->query(['document_id', 'revision_id', 'limit', 'before_thread_id']);
        return $this->data($this->queries->threads(
            $this->tenantAdminContext(),
            self::id($input, 'document_id'),
            self::id($input, 'revision_id'),
            isset($input['limit']) ? self::id($input, 'limit') : 20,
            isset($input['before_thread_id']) ? self::id($input, 'before_thread_id') : null,
        ));
    }
    public function thread()
    {
        $input = $this->query(['document_id', 'revision_id', 'thread_id', 'limit', 'after_message_id']);
        return $this->data($this->queries->thread(
            $this->tenantAdminContext(),
            self::id($input, 'document_id'),
            self::id($input, 'revision_id'),
            self::id($input, 'thread_id'),
            isset($input['limit']) ? self::id($input, 'limit') : 50,
            isset($input['after_message_id']) ? self::id($input, 'after_message_id') : null,
        ));
    }
    public function createThread()
    {
        return $this->submit('createThread');
    }
    public function reply()
    {
        return $this->submit('reply');
    }
    public function resolve()
    {
        return $this->submit('resolve');
    }
    public function reopen()
    {
        return $this->submit('reopen');
    }

    private function submit(string $method)
    {
        $input = $this->request->post();
        $documentId = self::id($input, 'document_id');
        $revisionId = self::id($input, 'revision_id');
        unset($input['document_id'], $input['revision_id']);
        // The public service validates every remaining key, including rejecting tenant/actor overrides.
        return $this->data($this->commands->$method($this->tenantAdminContext(), $documentId, $revisionId, $input));
    }

    private function query(array $fields): array
    {
        $input = $this->request->get();
        if (array_diff(array_keys($input), $fields) !== []) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论查询包含未知字段');
        }
        return $input;
    }

    private static function id(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1)) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论标识无效');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw BusinessException::invalid('DOCUMENT_COMMENT_INPUT_INVALID', '评论标识无效');
        }
        return $id;
    }
}
