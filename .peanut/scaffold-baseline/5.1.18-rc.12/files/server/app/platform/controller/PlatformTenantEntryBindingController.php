<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\http\ApiProblem;
use app\platform\validate\TenantEntryBindingValidate;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantEntryBindingAdminService;
use think\App;
use think\exception\ValidateException;

final class PlatformTenantEntryBindingController extends BasePlatformController
{
    public function __construct(App $app, protected readonly TenantEntryBindingAdminService $entryBindings)
    {
        parent::__construct($app);
    }
    public function lists()
    {
        if ($this->platformContext === null) {
            throw ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        $tenantId = $this->request->get('tenant_id');
        if ($tenantId !== null && $tenantId !== '') {
            $this->validate(['tenant_id' => $tenantId], TenantEntryBindingValidate::class . '.lists');
        }
        return $this->data($this->entryBindings->lists(
            $this->platformContext->core,
            $tenantId === null || $tenantId === '' ? null : (int) $tenantId,
        ));
    }

    public function enable()
    {
        return $this->mutate(
            'enable',
            fn(array $params): array =>
            $this->entryBindings->enable(
                $this->platformContext->core,
                (int) $params['tenant_id'],
                (string) $params['host'],
                (string) $params['client_key'],
                (string) $params['change_reason'],
            ),
        );
    }

    public function disable()
    {
        return $this->mutate(
            'disable',
            fn(array $params): array =>
            $this->entryBindings->disable(
                $this->platformContext->core,
                (int) $params['binding_id'],
                (string) $params['change_reason'],
            ),
        );
    }

    private function mutate(string $scene, callable $operation)
    {
        if ($this->platformContext === null) {
            throw ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        $params = $this->request->post();
        try {
            $this->validate($params, TenantEntryBindingValidate::class . '.' . $scene);
        } catch (ValidateException $exception) {
            throw new ApiProblem('TENANT_ENTRY_INPUT_INVALID', 422, (string) $exception->getError());
        }
        return $this->data($operation($params));
    }
}
