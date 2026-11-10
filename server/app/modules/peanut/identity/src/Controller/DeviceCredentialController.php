<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Identity\Contract\DeviceCredentialEnrollment;
use PeanutAdmin\Modules\Identity\Contract\DeviceCredentialRevocations;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialRevocationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialInput;
use think\response\Json;

final class DeviceCredentialController extends BaseAdminController
{
    public function enroll(DeviceCredentialEnrollment $commands, DeviceCredentialInput $validator): Json
    {
        $actor = $this->tenantAdminContext();
        try {
            if ($this->request->get() !== []) {
                throw BusinessException::invalid('IDENTITY_DEVICE_INPUT_INVALID', '设备凭据登记不接受查询参数。');
            }
            $result = $commands->enroll($actor, $validator->parse($this->request->getContent()));
        } catch (BusinessException $exception) {
            return $this->rejected($actor, $exception);
        }
        return json($result, $result['outcome'] === 'accepted' ? 201 : 202);
    }

    public function revoke(DeviceCredentialRevocations $commands, DeviceCredentialRevocationInput $validator, string $key = ''): Json
    {
        $actor = $this->tenantAdminContext();
        try {
            if ($this->request->get() !== []) {
                throw BusinessException::invalid('IDENTITY_DEVICE_INPUT_INVALID', '设备凭据撤销不接受查询参数。');
            }
            $result = $commands->revoke($actor, $key, $validator->parse($this->request->getContent()));
        } catch (BusinessException $exception) {
            return $this->rejected($actor, $exception);
        }
        return json($result, $result['outcome'] === 'accepted' ? 200 : 202);
    }

    private function rejected(TenantContext $actor, BusinessException $exception): Json
    {
        return json([
            'outcome' => 'rejected', 'correlation_id' => $actor->requestId,
            'resource_key' => null, 'resource_revision' => null, 'data' => null,
            'problem' => [
                'code' => $exception->errorCode, 'message_zh' => $exception->getMessage(),
                'retryable' => false, 'recovery_action' => 'resolve_problem',
            ],
        ], $exception->httpStatus);
    }
}
