<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

interface FileStorage
{
    /** Writes require no outer database transaction so intent is durable before provider I/O.
     * Unknown writes retain the reserved key for observation; never repeat them with a new key.
     * Private writes return a stable key; export.xlsx additionally requires and grants original download.
     * @return array{file_key:string,object_key:string,access_type:string,url:string,original_name:string} */
    public function storePath(int $tenantId, ?int $memberId, string $purpose, string $sourcePath, string $originalName, string $mediaType): array;

    public function delete(int $tenantId, string $fileKey): void;

    public function accessUrlForTenant(int $tenantId, string $fileKey): string;

    /** Live permission precheck for synchronous exports; authorizes no existing object. */
    public function requireOriginalDownloadPermission(int $tenantId): void;

    /** @return array{path:string,temporary:bool,filename:string,media_type:string} */
    public function openForTenant(int $tenantId, string $fileKey): array;

    /** @return array{path:string,temporary:bool,filename:string,media_type:string,disposition:string} */
    public function authorizedDownload(int $tenantId, string $fileKey, string $token): array;
}
