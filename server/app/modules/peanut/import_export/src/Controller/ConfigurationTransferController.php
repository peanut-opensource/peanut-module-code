<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Controller;

use PeanutAdmin\Modules\ImportExport\Service\TenantConfigurationTransferService;
use app\adminapi\controller\BaseAdminController;
use app\common\exception\BusinessException;

/**
 * Tenant-scoped, path-free configuration package HTTP Host.
 * @property-read TenantConfigurationTransferService $transfers 当前 App 中声明式解析的控制器依赖。
 */
final class ConfigurationTransferController extends BaseAdminController
{
    protected string $transfersClass = TenantConfigurationTransferService::class;

    public function export()
    {
        return $this->data($this->transfers->export(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
        ));
    }

    public function dryRun()
    {
        [$package, $secretBindings, $conflictPolicy] = $this->requestPayload();
        return $this->data($this->transfers->dryRun(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $package,
            $secretBindings,
            $conflictPolicy,
        ));
    }

    public function apply()
    {
        [$package, $secretBindings, $conflictPolicy] = $this->requestPayload();
        return $this->data($this->transfers->apply(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $package,
            $secretBindings,
            $conflictPolicy,
        ));
    }

    /** @return array{0:array<string,mixed>|string,1:array<string,mixed>,2:string} */
    private function requestPayload(): array
    {
        $payload = $this->request->post();
        // ThinkPHP's associative JSON decoding turns both {} and [] into an empty array.
        $rawPayload = json_decode($this->request->getContent());
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['conflict_policy', 'package', 'secret_bindings']
            || !($rawPayload instanceof \stdClass)
            || !(($rawPayload->secret_bindings ?? null) instanceof \stdClass)
            || (!is_array($payload['package']) && !is_string($payload['package']))
            || !is_array($payload['secret_bindings'])
            || !is_string($payload['conflict_policy'])
        ) {
            throw BusinessException::invalid('TRANSFER_REQUEST_INVALID', '配置转移请求无效');
        }
        return [
            $payload['package'],
            $payload['secret_bindings'],
            $payload['conflict_policy'],
        ];
    }
}
