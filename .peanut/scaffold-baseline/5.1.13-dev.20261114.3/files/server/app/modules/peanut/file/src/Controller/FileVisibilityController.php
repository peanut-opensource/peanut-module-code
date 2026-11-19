<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\File\Contract\FileVisibility;

/** @property-read FileVisibility $visibility */
final class FileVisibilityController extends BaseAdminController
{
    protected string $visibilityClass = FileVisibility::class;

    public function preview()
    {
        $input = $this->request->post();
        if (array_keys($input) !== ['file_keys'] || !is_array($input['file_keys']) || !array_is_list($input['file_keys'])) {
            throw BusinessException::invalid('FILE_VISIBILITY_INPUT_INVALID', '资源列表无效');
        }
        return $this->data($this->visibility->preview($input['file_keys']));
    }

    public function submit()
    {
        $input = $this->request->post();
        if (count($input) !== 3 || array_diff(array_keys($input), ['confirmation','signature','impact_confirmed']) !== []
            || !is_array($input['confirmation'] ?? null) || !is_string($input['signature'] ?? null)
            || !is_bool($input['impact_confirmed'] ?? null)) {
            throw BusinessException::invalid('FILE_VISIBILITY_INPUT_INVALID', '批次确认无效');
        }
        return $this->data($this->visibility->submit(
            $input['confirmation'],
            $input['signature'],
            (string) $this->request->header('Idempotency-Key', ''),
            $input['impact_confirmed'],
        ));
    }

    public function status(string $batchKey)
    {
        return $this->data($this->visibility->status($batchKey));
    }

    public function recent()
    {
        return $this->data(['items' => $this->visibility->recent()]);
    }

    public function resume(string $batchKey)
    {
        if ($this->request->post() !== []) {
            throw BusinessException::invalid('FILE_VISIBILITY_INPUT_INVALID', '恢复使用原批次，不接受替换计划');
        }
        return $this->data($this->visibility->resume($batchKey));
    }
}
