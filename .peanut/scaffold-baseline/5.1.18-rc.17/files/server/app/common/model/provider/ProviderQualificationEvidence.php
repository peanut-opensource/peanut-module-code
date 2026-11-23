<?php

declare(strict_types=1);

namespace app\common\model\provider;

use app\common\model\InstanceOwnedModel;

/** Deployment-owned, append-only evidence ledger read by the Platform control plane. */
final class ProviderQualificationEvidence extends InstanceOwnedModel
{
    protected $name = 'provider_qualification_evidence';
    protected $autoWriteTimestamp = false;
}
