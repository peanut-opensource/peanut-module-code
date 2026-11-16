<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

interface FileDocumentReferenceContributor
{
    /** A compiled peanut.document Provider contributes its trusted policy class. */
    public function fileDocumentReferencePolicy(): string;
}
