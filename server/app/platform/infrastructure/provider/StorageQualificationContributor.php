<?php

declare(strict_types=1);

namespace app\platform\infrastructure\provider;

use app\platform\contract\provider\ProviderQualificationContributor;
use app\platform\value\provider\ProviderQualificationSubject;
use PeanutAdmin\Modules\File\Contract\StorageQualificationQueries;

final readonly class StorageQualificationContributor implements ProviderQualificationContributor
{
    public function __construct(private string $digestKey, private StorageQualificationQueries $storage)
    {
        if (strlen($digestKey) < 32) {
            throw new \InvalidArgumentException('PROVIDER_QUALIFICATION_DIGEST_KEY_INVALID');
        }
    }

    public function subjects(): array
    {
        return array_map(static fn(array $row): ProviderQualificationSubject => new ProviderQualificationSubject(
            'storage.' . $row['driver'],
            'storage',
            'instance',
            null,
            $row['account_key'],
            $row['configured'],
            false,
            $row['credential_rotated_at'],
            $row['config_digest'],
        ), $this->storage->subjects($this->digestKey));
    }
}
