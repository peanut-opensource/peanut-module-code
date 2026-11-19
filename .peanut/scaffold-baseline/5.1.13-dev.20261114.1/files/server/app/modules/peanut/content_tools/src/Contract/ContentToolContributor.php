<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ContentTools\Contract;

/** Implemented by compiled, selected Module providers; never supplied by a request. */
interface ContentToolContributor
{
    /** @return list<SurfaceAdapterRegistration> */
    public function contentToolRegistrations(): array;
}
