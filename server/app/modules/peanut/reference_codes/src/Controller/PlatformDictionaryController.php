<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Controller;

use app\platform\controller\BasePlatformController;
use PeanutAdmin\Modules\ReferenceCodes\Infrastructure\ThinkPhpDictionaryCustomizationProvider;
use think\response\Json;

/** Platform-level management of system dictionary tenant customization policies. */
final class PlatformDictionaryController extends BasePlatformController
{
    public function __construct(
        \think\App $app,
        private readonly ThinkPhpDictionaryCustomizationProvider $dictionaryCustomization,
    ) {
        parent::__construct($app);
    }

    public function types(): Json
    {
        return $this->data($this->dictionaryCustomization->platformTypes());
    }

    public function tenantMode(string $type): Json
    {
        $this->dictionaryCustomization->savePlatformTenantMode(
            $type,
            (string) $this->request->post('tenant_mode', ''),
        );
        return $this->data(true);
    }
}
