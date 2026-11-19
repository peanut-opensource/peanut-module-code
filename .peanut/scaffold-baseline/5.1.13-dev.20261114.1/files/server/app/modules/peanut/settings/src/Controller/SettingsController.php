<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\ApiProblem;
use PeanutAdmin\Modules\Settings\Application\SettingException;
use PeanutAdmin\Modules\Settings\Service\SettingsHttpApplicationService;
use think\response\Json;

/** @property-read SettingsHttpApplicationService $settings 当前 App 中声明式解析的控制器依赖。 */
final class SettingsController extends BaseAdminController
{
    protected string $settingsClass = SettingsHttpApplicationService::class;

    public function index(): Json
    {
        try {
            $items = $this->settings->list($this->tenantAdminContext())['items'];
            $etag = '"settings-' . hash('sha256', json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"';
            return $this->response(['items' => $items], $etag);
        } catch (SettingException $exception) {
            throw $this->problem($exception);
        }
    }

    public function replace(string $moduleKey, string $settingKey): Json
    {
        $body = $this->jsonBody(['value']);
        try {
            $record = $this->settings->replace(
                $this->tenantAdminContext(),
                $moduleKey,
                $settingKey,
                $body['value'],
                $this->header('If-Match'),
                $this->header('If-None-Match'),
                $this->requiredHeader('Idempotency-Key'),
            );
        } catch (SettingException $exception) {
            throw $this->problem($exception);
        }
        return $this->response($record, $record['etag'] ?? null);
    }

    public function unset(string $moduleKey, string $settingKey): Json
    {
        try {
            $record = $this->settings->unset(
                $this->tenantAdminContext(),
                $moduleKey,
                $settingKey,
                $this->header('If-Match'),
                $this->requiredHeader('Idempotency-Key'),
            );
        } catch (SettingException $exception) {
            throw $this->problem($exception);
        }
        return $this->response($record, $record['etag'] ?? null);
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private function jsonBody(array $keys): array
    {
        $body = json_decode((string) $this->request->getContent(), true);
        if (!is_array($body) || array_is_list($body) || array_keys($body) !== $keys) {
            throw new ApiProblem('SETTING_REQUEST_INVALID', 422, 'The setting request is invalid.');
        }
        return $body;
    }

    private function requiredHeader(string $name): string
    {
        return $this->header($name) ?? throw new ApiProblem('IDEMPOTENCY_KEY_REQUIRED', 428, $name . ' is required.');
    }

    private function header(string $name): ?string
    {
        $value = trim((string) $this->request->header($name, ''));
        return $value === '' ? null : $value;
    }

    private function response(array $data, mixed $etag = null): Json
    {
        $response = json(['data' => $data, 'request_id' => $this->executionContext()->requestId()]);
        if (is_string($etag) && $etag !== '') {
            $response->header(['ETag' => $etag]);
        }
        return $response;
    }

    private function problem(SettingException $exception): ApiProblem
    {
        return new ApiProblem($exception->errorCode, $exception->httpStatus, $exception->getMessage());
    }
}
