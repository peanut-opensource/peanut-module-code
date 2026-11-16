<?php

declare(strict_types=1);

namespace app\platform\infrastructure\provider;

use app\platform\contract\provider\ProviderQualificationContributor;
use app\platform\value\provider\ProviderQualificationSubject;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingQualificationQueries;

abstract class AbstractTenantBindingQualificationContributor implements ProviderQualificationContributor
{
    public function __construct(
        private readonly string $digestKey,
        private readonly AdminDirectoryQuery $tenants,
        private readonly ExternalBindingQualificationQueries $qualifications,
    ) {
        if (strlen($digestKey) < 32) {
            throw new \InvalidArgumentException('PROVIDER_QUALIFICATION_DIGEST_KEY_INVALID');
        }
    }

    /**
     * @return list<array{provider_key:string,binding_provider:string,category:string,callback_required:bool}>
     */
    abstract protected function definitions(): array;

    public function subjects(): array
    {
        $tenants = $this->tenants->activeTenantIdsForQualification();
        $definitions = $this->definitions();
        $qualified = [];
        foreach ($this->qualifications->forTenants($tenants, array_column($definitions, 'provider_key'), $this->digestKey) as $result) {
            $qualified[$result->tenantId][$result->providerKey] = $result;
        }
        $subjects = [];
        foreach ($tenants as $tenantValue) {
            $tenantId = (int) $tenantValue;
            foreach ($definitions as $definition) {
                $providerKey = $definition['provider_key'];
                $result = $qualified[$tenantId][$providerKey]
                    ?? throw new \LogicException('PROVIDER_QUALIFICATION_RESULT_MISSING');
                $subjects[] = new ProviderQualificationSubject(
                    $providerKey,
                    $definition['category'],
                    'tenant',
                    $tenantId,
                    $providerKey,
                    $result->configured,
                    $definition['callback_required'],
                    null,
                    $result->configDigest,
                );
            }
        }
        return $subjects;
    }
}
