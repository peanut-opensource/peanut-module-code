<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Resource operations; HTTP parameters are never authorization evidence. */
interface FileAccess
{
    /** Available delivery includes its actual derivative variant_key, or null for original bytes. */
    /** @return array<string,mixed> Current metadata and a short-lived authorized delivery. */
    public function resolve(TenantContext $context, string $fileKey, string $operation, ?int $documentId = null, ?string $variantKey = null, ?string $viewerAdapterId = null): array;

    /** @param list<string> $fileKeys @return list<array<string,mixed>> */
    public function resolveMany(TenantContext $context, array $fileKeys, string $operation): array;

    /** Current authorized source metadata; no delivery address, bytes, or document proof.
     * @return array{file_key:string,original_name:string,verified_mime_type:string,size_bytes:int,sha256:string}
     */
    public function attachmentSnapshot(TenantContext $context, string $fileKey): array;
}
