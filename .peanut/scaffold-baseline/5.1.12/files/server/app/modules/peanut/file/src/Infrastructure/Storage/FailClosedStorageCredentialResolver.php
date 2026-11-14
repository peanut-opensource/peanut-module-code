<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Infrastructure\Storage;

use app\common\contract\storage\StorageCredentialResolver;

final class FailClosedStorageCredentialResolver implements StorageCredentialResolver
{
    public function resolve(array $account): array
    {
        if ((string) ($account['driver'] ?? '') === 'local') {
            return [];
        }
        $ciphertext = (string) ($account['credential_ciphertext'] ?? '');
        $keyVersion = (string) ($account['credential_key_version'] ?? '');
        if ($ciphertext === '' || $keyVersion === '') {
            throw new \RuntimeException('存储凭据未配置');
        }
        return StorageCredentialCipher::decrypt($ciphertext, $keyVersion);
    }
}
