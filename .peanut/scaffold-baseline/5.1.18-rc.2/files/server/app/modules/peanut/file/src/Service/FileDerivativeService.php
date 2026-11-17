<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\File\Contract\FileDerivatives;
use PeanutAdmin\Modules\File\Model\Storage\FileDerivative;
use PeanutAdmin\Modules\File\Model\Storage\FileImageAsset;
use PeanutAdmin\Modules\File\Model\Storage\FileObject;
use PeanutAdmin\FileMedia\Media\ImageMetadata;
use PeanutAdmin\FileMedia\Media\ImageVariantDefinition;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use think\facade\Db;

final readonly class FileDerivativeService implements FileDerivatives
{
    public function __construct(private CurrentExecutionContext $executionContext, private FileAuthorizationService $authorization, private StorageService $storage) {}

    public function bind(
        TenantContext $context,
        string $sourceFileKey,
        string $variantKey,
        string $derivativeFileKey,
        int $width,
        int $height,
        string $mediaType,
    ): void {
        if ($context->tenantId !== $this->executionContext->tenantId()) {
            throw new \DomainException('FILE_DERIVATIVE_TENANT_CONTEXT_MISMATCH');
        }
        new ImageVariantDefinition($variantKey, $width, $height, 'cover', $mediaType);
        if (hash_equals($sourceFileKey, $derivativeFileKey)) {
            throw new \InvalidArgumentException('衍生图不能引用源文件');
        }
        Db::transaction(function () use ($context, $sourceFileKey, $variantKey, $derivativeFileKey, $width, $height, $mediaType): void {
            $objects = FileObject::where([])
                ->whereIn('file_key', [$sourceFileKey, $derivativeFileKey])
                ->where('status', 'ready')
                ->order('file_key')
                ->lock(true)
                ->select()
                ->toArray();
            if (count($objects) !== 2) {
                throw new \InvalidArgumentException('源文件或衍生文件不存在');
            }
            $byKey = [];
            foreach ($objects as $object) {
                $byKey[(string) $object['file_key']] = $object;
                $this->authorization->authorize($context, $object, 'manage');
            }
            if (hash_equals((string) $byKey[$sourceFileKey]['sha256'], (string) $byKey[$derivativeFileKey]['sha256'])) {
                throw new \InvalidArgumentException('衍生图不能复用原件字节');
            }
            if (($byKey[$derivativeFileKey]['media_type'] ?? null) !== $mediaType) {
                throw new \InvalidArgumentException('衍生图媒体类型不一致');
            }
            if (FileImageAsset::where('file_key', $sourceFileKey)->find() === null) {
                throw new \InvalidArgumentException('源文件不是已登记图像');
            }
            new ImageMetadata($width, $height, $mediaType);
            $this->storage->verifyImageDerivative($context, $derivativeFileKey, $width, $height, $mediaType);
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
            FileDerivative::create([
                'tenant_id' => $context->tenantId,
                'source_file_key' => $sourceFileKey,
                'variant_key' => $variantKey,
                'derivative_file_key' => $derivativeFileKey,
                'width' => $width,
                'height' => $height,
                'media_type' => $mediaType,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }
}
