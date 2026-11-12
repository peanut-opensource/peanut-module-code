<?php

declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\adminapi\services\auth\TenantSessionApplicationService;
use app\BaseController;
use app\common\http\SessionRefreshCookie;
use PeanutAdmin\Modules\Identity\Http\TenantAuthResponse;
use think\App;

final class TenantSessionController extends BaseController
{
    public function __construct(
        App $app,
        private readonly TenantSessionApplicationService $sessions,
    ) {
        parent::__construct($app);
    }

    public function login()
    {
        $params = $this->request->post();
        return $this->response($this->sessions->login($this->request, $params));
    }

    public function select()
    {
        $params = $this->request->post();
        return $this->response($this->sessions->select($this->request, $params));
    }

    public function switchChallenge()
    {
        return $this->response($this->sessions->switchChallenge($this->request));
    }

    public function refresh()
    {
        return $this->response($this->sessions->refresh($this->request));
    }

    public function logout()
    {
        return $this->response($this->sessions->logout($this->request));
    }

    private function response(TenantAuthResponse $result)
    {
        $response = $result->status === 204
            ? response('', 204)
            : json($result->body ?? ['code' => 20000, 'msg' => 'success', 'data' => null], $result->status);
        $response->header($result->headers);
        return SessionRefreshCookie::apply($response, $result->cookies);
    }
}
