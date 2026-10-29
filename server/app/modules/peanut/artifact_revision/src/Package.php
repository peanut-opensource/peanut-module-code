<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ArtifactRevision;

final class Package
{
    public const MODULE_KEY = 'peanut.artifact-revision';
    public const VERSION = '4.0.0-dev';

    public const CREATE_OPERATION = 'artifact-revision.create';
    public const FINALIZE_OPERATION = 'artifact-revision.finalize';

    private function __construct() {}
}
