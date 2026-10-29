<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Composition\Storage;

use OSS\OssClient;

/** Provider SDK assembly with bounded timeouts and TLS verification owned by the SDK. */
final class AliyunStorageClientFactory
{
    public function make(array $account, array $space): OssClient
    {
        $credentials = (array) ($account['resolved_credentials'] ?? []);
        $client = new OssClient(
            (string) ($credentials['access_key'] ?? ''),
            (string) ($credentials['secret_key'] ?? ''),
            (string) ($space['endpoint'] ?? ''),
            true,
        );
        $client->setConnectTimeout(10);
        $client->setTimeout(30);
        return $client;
    }
}
