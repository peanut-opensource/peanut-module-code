<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\platform\services\developer\DeveloperCenterCatalogService;

/** @property-read DeveloperCenterCatalogService $developerCatalog */
final class PlatformDeveloperCenterController extends BasePlatformController
{
    protected string $developerCatalogClass = DeveloperCenterCatalogService::class;

    public function catalog()
    {
        $moduleKey = trim((string) $this->request->get('module_key', ''));
        return $this->data($this->developerCatalog->snapshot($moduleKey === '' ? null : $moduleKey));
    }
}
