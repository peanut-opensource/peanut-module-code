<?php

declare(strict_types=1);

namespace app\platform\controller;

use think\App;

use app\platform\services\developer\DeveloperCenterCatalogService;

final class PlatformDeveloperCenterController extends BasePlatformController
{
    public function __construct(App $app, protected readonly DeveloperCenterCatalogService $developerCatalog)
    {
        parent::__construct($app);
    }
    public function catalog()
    {
        $moduleKey = trim((string) $this->request->get('module_key', ''));
        return $this->data($this->developerCatalog->snapshot($moduleKey === '' ? null : $moduleKey));
    }
}
