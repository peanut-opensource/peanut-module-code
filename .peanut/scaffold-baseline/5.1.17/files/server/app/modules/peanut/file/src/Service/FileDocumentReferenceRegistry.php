<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\File\Contract\FileDocumentReferencePolicy;
use think\App;

final readonly class FileDocumentReferenceRegistry
{
    public function __construct(private App $app, private ?string $policyClass) {}

    public function requirePolicy(): FileDocumentReferencePolicy
    {
        if ($this->policyClass === null) {
            throw BusinessException::forbidden('FILE_DOCUMENT_POLICY_UNAVAILABLE', '文档资源授权尚未可用');
        }
        return $this->app->make($this->policyClass);
    }
}
