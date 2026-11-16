<?php

declare(strict_types=1);

namespace app\platform\contract\provider;

use app\platform\value\provider\ProviderQualificationSubject;

interface ProviderQualificationContributor
{
    /** @return list<ProviderQualificationSubject> */
    public function subjects(): array;
}
