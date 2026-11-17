<?php

declare(strict_types=1);

namespace Custom\Modules\AiAccess\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\exception\BusinessException;
use Custom\Modules\AiAccess\Facade\Ai;
use Custom\Modules\AiAccess\Service\Catalog;
use Custom\Modules\AiAccess\Service\ProviderClient;

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

    public function discover()
    {
        $provider = $this->request->post('provider');
        $credential = $this->request->post('credential');
        return $this->data(app()->make(ProviderClient::class)->discover($this->tenantAdminContext(), $provider, $credential));
    }

    public function generate()
    {
        $input = $this->request->post();
        $prompt = $input['prompt'] ?? null;
        $key = $input['request_key'] ?? null;
        $capability = $input['capability'] ?? null;
        $target = [$input['model'] ?? null, $input['provider'] ?? null, $input['credential'] ?? null];
        if (!is_string($prompt) || !is_string($key) || !in_array($capability, ['text', 'image'], true)
            || array_filter($target, static fn ($value) => $value !== null && !is_string($value)) !== []) {
            throw BusinessException::invalid('AI_ACCESS_INPUT_INVALID', '测试参数无效');
        }
        if ($capability === 'text') {
            return $this->data(Ai::generateText($prompt, $key, ...$target));
        }
        $result = Ai::generateImage($prompt, $key, ...$target);
        $result['image'] = base64_encode($result['image']);
        return $this->data($result);
    }

    public function requestStatus()
    {
        $key = $this->request->get('request_key');
        if (!is_string($key)) {
            throw BusinessException::invalid('AI_ACCESS_REQUEST_KEY_INVALID', '请求标识无效');
        }
        return $this->data(Ai::requestStatus($key));
    }
}
