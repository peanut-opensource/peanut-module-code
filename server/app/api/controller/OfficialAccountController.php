<?php

declare(strict_types=1);

namespace app\api\controller;

use app\api\services\OfficialAccountApplicationService;
use app\common\exception\BusinessException;
use app\common\http\RequestTrace;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Kernel\Module\ModuleException;

/**
 * 公众号协议适配：用例负责验签及执行范围，这里只保留 HTTP 输入和响应映射。
 * @property-read OfficialAccountApplicationService $application 当前 App 中声明式解析的控制器依赖。
 */
class OfficialAccountController extends BaseApiController
{
    protected string $applicationClass = OfficialAccountApplicationService::class;

    public function verify()
    {
        $params = $this->request->get();
        try {
            $this->application->verify((string) $this->request->route('binding'), $params, $this->operationId());
        } catch (ExternalTenantResolutionException|ModuleException) {
            return response('callback rejected', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return response((string) ($params['echostr'] ?? ''), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function callback()
    {
        $params = $this->request->get();
        try {
            $result = $this->application->callback(
                (string) $this->request->route('binding'),
                $params,
                (string) $this->request->getContent(),
                $this->operationId(),
            );
        } catch (ExternalTenantResolutionException|ModuleException|BusinessException) {
            return response('callback rejected', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $contentType = $result === 'success' ? 'text/plain; charset=utf-8' : 'application/xml; charset=utf-8';
        return response($result, 200, ['Content-Type' => $contentType]);
    }

    private function operationId(): string
    {
        return RequestTrace::id($this->executionContext(), $this->request, 'wechat');
    }
}
