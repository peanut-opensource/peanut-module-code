<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use app\api\controller\BaseApiController;
use PeanutAdmin\Modules\OAuth\Service\OfficialAccountCallbackService;
use app\common\exception\BusinessException;
use app\common\http\RequestTrace;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\App;

class OfficialAccountCallbackController extends BaseApiController
{
    public function __construct(
        App $app,
        private readonly OfficialAccountCallbackService $application,
    ) {
        parent::__construct($app);
    }

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
