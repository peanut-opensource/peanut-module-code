<?php

declare(strict_types=1);

namespace app\adminapi\controller\config;

use app\BaseController;
use app\common\traits\ApiResponseTrait;
use think\App;

/** Provides the unbound application shell before Identity is adopted. */
final class HostBrandController extends BaseController
{
    use ApiResponseTrait;

    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    public function brand()
    {
        $brandPath = $this->app->getRootPath() . 'config/brand.json';
        $brand = json_decode((string) file_get_contents($brandPath), true, 64, JSON_THROW_ON_ERROR);
        $website = [...(array) ($brand['website'] ?? []), ...(array) ($brand['sidebar_footer'] ?? [])];

        return $this->data([
            'website' => $website,
            'tenantName' => '',
            'demo' => ['enabled' => false, 'email' => '', 'password' => ''],
            'login_enabled' => false,
        ]);
    }
}
