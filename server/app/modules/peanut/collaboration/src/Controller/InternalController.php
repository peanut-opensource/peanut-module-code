<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Controller;

use app\common\http\JsonResponseFactory;
use PeanutAdmin\Modules\Collaboration\Service\CollaborationService;

/** Each action consumes the middleware's original authenticated request bytes. */
final class InternalController extends \app\BaseController
{
    protected string $collaborationClass = CollaborationService::class;

    public function authorize()
    {
        return $this->protocol($this->collaboration->authorize());
    }
    public function check()
    {
        return $this->protocol($this->collaboration->check());
    }
    public function references()
    {
        return $this->protocol($this->collaboration->references());
    }
    public function load()
    {
        return $this->protocol($this->collaboration->load());
    }
    public function store()
    {
        return $this->protocol($this->collaboration->store());
    }
    public function receipt()
    {
        return $this->protocol($this->collaboration->receipt());
    }
    public function prepareTarget()
    {
        return $this->protocol($this->collaboration->prepareTarget());
    }
    public function target()
    {
        return $this->protocol($this->collaboration->target());
    }
    public function revoke()
    {
        return $this->protocol($this->collaboration->revoke());
    }
    public function recovery()
    {
        return $this->protocol($this->collaboration->recovery());
    }

    private function protocol(array $data): \think\response\Json
    {
        // Native JSON defaults escape '/', which can double slash-heavy base64 and
        // violate the bounded recovery response despite bounded persisted bytes.
        return JsonResponseFactory::data($data)->options(['json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES]);
    }
}
