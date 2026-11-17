<?php

declare(strict_types=1);

namespace Custom\Modules\AiAccess\Controller;

use app\adminapi\controller\BaseAdminController;
use Custom\Modules\AiAccess\Service\Catalog;

final class CatalogController extends BaseAdminController
{
    public function catalog()
    {
        return $this->data(app()->make(Catalog::class)->listing($this->tenantAdminContext()));
    }

    public function save()
    {
        return $this->data(app()->make(Catalog::class)->save($this->tenantAdminContext(), $this->request->post()));
    }
}
