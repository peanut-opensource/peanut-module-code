<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;

/** Authenticated contextual API; the use case always resolves the compiled owner and current business permission. */
class DocumentController extends BaseAdminController
{
    protected string $commandsClass = DocumentCommands::class;
    protected string $queriesClass = DocumentQueries::class;
    public function detail()
    {
        $in = $this->input(false, ['document_id','revision_id']);
        return $this->data($this->queries->detail($this->tenantAdminContext(), $this->id($in, 'document_id'), isset($in['revision_id']) ? $this->id($in, 'revision_id') : null));
    }
    public function capabilities()
    {
        $in = $this->input(false, ['document_id','revision_id','intent']);
        return $this->data($this->queries->capabilities($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->text($in, 'intent'), isset($in['revision_id']) ? $this->id($in, 'revision_id') : null));
    }
    public function versions()
    {
        $in = $this->input(false, ['document_id','limit','before_revision_no']);
        return $this->data($this->queries->versions(
            $this->tenantAdminContext(),
            $this->id($in, 'document_id'),
            array_key_exists('limit', $in) ? $this->id($in, 'limit') : 20,
            array_key_exists('before_revision_no', $in) ? $this->id($in, 'before_revision_no') : null,
        ));
    }
    public function operation()
    {
        $in = $this->input(false, ['document_id','operation_id']);
        return $this->data($this->queries->businessOperation($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->text($in, 'operation_id')));
    }
    public function target()
    {
        $in = $this->input(false, ['document_id','request_id']);
        return $this->data($this->queries->explicitTarget($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->text($in, 'request_id')));
    }
    public function cancelTarget()
    {
        $in = $this->input(true, ['document_id','request_id']);
        return $this->data($this->commands->cancelExplicitTarget($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->text($in, 'request_id')));
    }
    public function soloSave()
    {
        $in = $this->input(true, ['document_id','expected_state_seq','structured_content','request_id']);
        if (!is_array($in['structured_content'] ?? null)) {
            throw BusinessException::invalid('DOCUMENT_CONTENT_INVALID', '文档内容无效');
        }
        return $this->data($this->commands->saveSolo($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->sequence($in, 'expected_state_seq'), $in['structured_content'], $this->text($in, 'request_id')));
    }
    public function bindResource()
    {
        $in = $this->input(true, ['document_id','expected_state_seq','file_key','request_id']);
        return $this->data($this->commands->bindResource($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->text($in, 'file_key'), $this->sequence($in, 'expected_state_seq'), $this->text($in, 'request_id')));
    }
    public function createVersion()
    {
        $in = $this->input(true, ['document_id','expected_state_seq','reason','idempotency_key']);
        return $this->data($this->commands->freeze($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->sequence($in, 'expected_state_seq'), $this->text($in, 'reason'), $this->text($in, 'idempotency_key')));
    }
    public function restoreVersion()
    {
        $in = $this->input(true, ['document_id','revision_id','expected_state_seq','request_id']);
        return $this->data($this->commands->restoreRevision($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->id($in, 'revision_id'), $this->sequence($in, 'expected_state_seq'), $this->text($in, 'request_id')));
    }
    public function archive()
    {
        $in = $this->input(true, ['document_id','expected_auth_revision','request_id']);
        return $this->data($this->commands->archive($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->id($in, 'expected_auth_revision'), $this->text($in, 'request_id')));
    }
    public function restore()
    {
        $in = $this->input(true, ['document_id','expected_auth_revision','request_id']);
        return $this->data($this->commands->restore($this->tenantAdminContext(), $this->id($in, 'document_id'), $this->id($in, 'expected_auth_revision'), $this->text($in, 'request_id')));
    }
    private function input(bool $post, array $fields): array
    {
        $input = $post ? $this->request->post() : $this->request->get();
        if (array_diff(array_keys($input), $fields) !== []) {
            throw BusinessException::invalid('DOCUMENT_INPUT_INVALID', '文档操作字段无效');
        }
        foreach (['document_id','revision_id','expected_auth_revision'] as $key) {
            if (in_array($key, $fields, true) && ($key !== 'revision_id' || $post || array_key_exists($key, $input))) {
                $this->id($input, $key);
            }
        }
        if (in_array('expected_state_seq', $fields, true)) {
            $this->sequence($input, 'expected_state_seq');
        }
        return $input;
    }
    private function text(array $input, string $key): string
    {
        if (!is_string($input[$key] ?? null)) {
            throw BusinessException::invalid('DOCUMENT_INPUT_INVALID', '文档操作缺少有效字段');
        }return $input[$key];
    }
    private function sequence(array $input, string $key): int
    {
        $raw = $input[$key] ?? null;
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^(0|[1-9][0-9]*)$/D', $raw) === 1)) {
            throw BusinessException::invalid('DOCUMENT_INPUT_INVALID', '文档序号无效');
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($value === false) {
            throw BusinessException::invalid('DOCUMENT_INPUT_INVALID', '文档序号无效');
        }
        return $value;
    }
    private function id(array $input, string $key): int
    {
        $value = $this->sequence($input, $key);
        if ($value < 1) {
            throw BusinessException::invalid('DOCUMENT_INPUT_INVALID', '文档标识无效');
        }return $value;
    }
}
