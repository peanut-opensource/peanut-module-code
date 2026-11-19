<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\File\Contract\FileStorage;

/** @property-read FileStorage $storage 当前 App 中声明式解析的控制器依赖。 */
final class StorageController extends BaseApiController
{
    protected string $storageClass = FileStorage::class;

    public function delivery()
    {
        $tenantId = $this->positiveInteger($this->request->get('tenant_id'));
        $fileKey = $this->request->get('file_key');
        $token = $this->request->get('token');
        if (!is_string($fileKey) || preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1
            || !is_string($token) || $token === '' || strlen($token) > 2048
        ) {
            throw new \InvalidArgumentException('文件链接参数无效');
        }

        $file = $this->storage->authorizedDownload($tenantId, $fileKey, $token);

        try {
            $contents = file_get_contents($file['path']);
            if (!is_string($contents)) {
                throw BusinessException::notFound('STORAGE_DELIVERY_NOT_FOUND', '文件不存在或不可用');
            }
        } finally {
            if ($file['temporary'] && is_file($file['path'])) {
                unlink($file['path']);
            }
        }

        $filename = rawurlencode($file['filename']);
        return response($contents, 200, [
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => $file['disposition'] . "; filename*=UTF-8''" . $filename,
            'Content-Length' => (string) strlen($contents),
            'Content-Type' => $file['media_type'],
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function positiveInteger(mixed $value): int
    {
        $candidate = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        if (preg_match('/^[1-9][0-9]*$/D', $candidate) !== 1
            || filter_var($candidate, FILTER_VALIDATE_INT) === false
        ) {
            throw new \InvalidArgumentException('文件链接参数无效');
        }
        return (int) $candidate;
    }
}
