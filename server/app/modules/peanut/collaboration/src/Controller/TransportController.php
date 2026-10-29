<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Collaboration\Service\CollaborationService;

final class TransportController extends BaseAdminController
{
    protected string $collaborationClass = CollaborationService::class;

    public function resolve()
    {
        $input = $this->request->post();
        $raw = $input['document_id'] ?? null;
        if (array_diff(array_keys($input), ['document_id','mode']) !== []
            || !is_int($raw) && !(is_string($raw) && preg_match('/^[1-9][0-9]*$/D', $raw) === 1)
            || !in_array($input['mode'] ?? null, ['read','write'], true)) {
            throw BusinessException::invalid('COLLABORATION_TRANSPORT_INPUT_INVALID', '协作传输参数无效');
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw BusinessException::invalid('COLLABORATION_TRANSPORT_INPUT_INVALID', '文档编号无效');
        }
        return $this->data($this->collaboration->transport($this->tenantAdminContext(), $id, $input['mode']));
    }
}
