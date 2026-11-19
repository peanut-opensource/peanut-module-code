<?php

declare(strict_types=1);

namespace app\common\services;

use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** 内容与装修等产品记录的公开资源引用边界。 */
final readonly class ProductAssetReferenceService
{
    public function __construct(
        private FileReferences $files,
        private string $applicationOrigin,
    ) {}

    /** Persist stable owned file_key only; external hyperlinks are a separate content field. */
    public function forStorage(
        string $value,
        ?string $applicationOrigin = null,
        AuthenticatedMemberContext|TenantContext|TenantSystemContext|null $context = null,
    ): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($context === null) {
            throw new \DomainException('FILE_REFERENCE_TRUSTED_CONTEXT_REQUIRED');
        }
        return $this->files->setTenantFileUrl($context, $value);
    }

    public function forRead(string $value): string
    {
        $value = trim($value);
        return $this->files->getFileUrl($value);
    }

}
