<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\RichText;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\RichText\Service\RichTextDocumentService;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use think\App;
use think\facade\Config;
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
        return [
            RichTextDocumentService::class => fn(App $app): RichTextDocumentService => new RichTextDocumentService(
                $app->make(CurrentExecutionContext::class),
                trim((string) Config::get('peanut.rich_text.collaboration_url', '')),
                trim((string) Config::get('peanut.rich_text.collaboration_secret', '')),
            ),
        ];
    }

    public function contentToolRegistrations(): array
    {
        return [new SurfaceAdapterRegistration(
            'rich-text.tiptap', 'peanut.rich-text', '1.0.0', ['rich_text'], [], ['view', 'edit'],
        )];
    }
}
