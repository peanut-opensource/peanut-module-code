<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** Only compiled Module providers contribute business policies. */
interface DocumentOwnerContributor
{
    /** @return array<string,class-string<DocumentOwnerPolicy>> objectType => policy class */
    public function documentOwnerPolicies(): array;
}
