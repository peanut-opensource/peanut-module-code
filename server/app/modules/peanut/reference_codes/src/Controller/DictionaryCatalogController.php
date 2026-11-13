<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\ReferenceCodes\Infrastructure\ThinkPhpDictionaryCustomizationProvider;
use think\response\Json;

/** @property-read ThinkPhpDictionaryCustomizationProvider $dictionaryCustomization */
final class DictionaryCatalogController extends BaseAdminController
{
    protected string $dictionaryCustomizationClass = ThinkPhpDictionaryCustomizationProvider::class;

    public function types(): Json
    {
        return $this->data($this->dictionaryCustomization->systemTypes($this->tenantAdminContext()));
    }

    public function detail(string $type): Json
    {
        return $this->data($this->dictionaryCustomization->systemTypeDetail($this->tenantAdminContext(), $type));
    }

    public function mode(string $type): Json
    {
        $this->dictionaryCustomization->saveMode(
            $this->tenantAdminContext(),
            $type,
            (string) $this->request->post('mode', ''),
        );
        return $this->data(true);
    }

    public function override(string $type): Json
    {
        $input = $this->request->post();
        $this->dictionaryCustomization->saveOverride(
            $this->tenantAdminContext(),
            $type,
            (string) ($input['value'] ?? ''),
            (string) ($input['action'] ?? ''),
            isset($input['name']) && is_string($input['name']) ? $input['name'] : null,
        );
        return $this->data(true);
    }

    public function clearOverride(string $type): Json
    {
        $this->dictionaryCustomization->deleteOverride(
            $this->tenantAdminContext(),
            $type,
            (string) $this->request->post('value', ''),
        );
        return $this->data(true);
    }
}
