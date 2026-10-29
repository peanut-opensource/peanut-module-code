<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\File\Contract\FileUploads;
use PeanutAdmin\Modules\File\Contract\Dto\UploadFile;
use app\common\enum\FileEnum;
use think\file\UploadedFile;
use app\common\exception\BusinessException;

/** @property-read FileUploads $uploads 当前 App 中声明式解析的控制器依赖。 */
class UploadController extends BaseAdminController
{
    protected string $uploadsClass = FileUploads::class;

    public function image()
    {
        return $this->upload('image');
    }

    public function video()
    {
        return $this->upload('video');
    }

    public function file()
    {
        return $this->upload('file');
    }

    /** @param string $method image|video|file */
    protected function upload(string $method)
    {
        $cidValue = $this->request->post('cid', 0);
        if (!is_int($cidValue) && !(is_string($cidValue) && preg_match('/^-?\d+$/D', $cidValue) === 1)) {
            throw new \InvalidArgumentException('目标分类无效');
        }
        $uploaded = $this->request->file('file');
        if (!$uploaded instanceof UploadedFile) {
            throw BusinessException::invalid('UPLOAD_FILE_REQUIRED', '未接收到上传文件');
        }
        $result = $this->uploads->{$method}(
            $this->tenantAdminContext(),
            new UploadFile(
                (string) $uploaded->getPathname(),
                (string) $uploaded->getOriginalName(),
                (int) $uploaded->getSize(),
                (string) ($uploaded->getMime() ?: 'application/octet-stream'),
                (string) $uploaded->getOriginalExtension(),
            ),
            (int) $cidValue,
            $this->adminId,
            FileEnum::SOURCE_ADMIN,
        );
        return $this->success('上传成功', $result);
    }
}
