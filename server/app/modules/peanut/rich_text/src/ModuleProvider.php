<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\RichText;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Modules\ContentTools\Contract\ContentToolContributor;
use PeanutAdmin\Modules\ContentTools\Contract\SurfaceAdapterRegistration;

final class ModuleProvider implements ModuleProviderContract, ContentToolContributor
{
    public function moduleKey(): string
    {
        return 'peanut.rich-text';
    }

    public function bindings(): array
    {
        return [];
    }

    public function contentToolRegistrations(): array
    {
        return [new SurfaceAdapterRegistration(
            'rich-text.tiptap', 'peanut.rich-text', '1.0.0', ['rich_text'], [], ['view', 'edit'],
        )];
    }
}
