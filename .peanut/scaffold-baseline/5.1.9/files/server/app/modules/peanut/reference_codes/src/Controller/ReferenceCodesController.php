<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\ApiProblem;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeException;
use PeanutAdmin\Modules\ReferenceCodes\Service\ReferenceCodesHttpApplicationService;
use think\response\Json;

/** @property-read ReferenceCodesHttpApplicationService $referenceCodes 当前 App 中声明式解析的控制器依赖。 */
final class ReferenceCodesController extends BaseAdminController
{
    private const VERSION_FIELDS = ['label', 'metadata', 'status', 'sort_order', 'effective_at', 'expires_at'];

    protected string $referenceCodesClass = ReferenceCodesHttpApplicationService::class;

    public function sets(): Json
    {
        return $this->invoke(fn(): array => $this->referenceCodes->sets($this->tenantAdminContext()));
    }

    public function index(string $moduleKey, string $setKey): Json
    {
        return $this->invoke(fn(): array => $this->referenceCodes->list(
            $this->tenantAdminContext(),
            $moduleKey,
            $setKey,
            $this->request->get(),
        ));
    }

    public function detail(string $moduleKey, string $setKey, string $code): Json
    {
        return $this->invoke(fn(): array => $this->referenceCodes->get(
            $this->tenantAdminContext(),
            $moduleKey,
            $setKey,
            $code,
            $this->request->get('as_of'),
        ), true);
    }

    public function create(string $moduleKey, string $setKey): Json
    {
        $body = $this->jsonBody(['code', ...self::VERSION_FIELDS]);
        return $this->invoke(fn(): array => $this->referenceCodes->create(
            $this->tenantAdminContext(),
            $moduleKey,
            $setKey,
            $body,
            $this->requiredHeader('Idempotency-Key'),
            $this->header('If-None-Match'),
        ), true);
    }

    public function replace(string $moduleKey, string $setKey, string $code): Json
    {
        $body = $this->jsonBody(self::VERSION_FIELDS);
        return $this->invoke(fn(): array => $this->referenceCodes->replace(
            $this->tenantAdminContext(),
            $moduleKey,
            $setKey,
            $code,
            $body,
            $this->requiredHeader('Idempotency-Key'),
            $this->header('If-Match'),
        ), true);
    }

    public function retire(string $moduleKey, string $setKey, string $code): Json
    {
        return $this->invoke(fn(): array => $this->referenceCodes->retire(
            $this->tenantAdminContext(),
            $moduleKey,
            $setKey,
            $code,
            $this->requiredHeader('Idempotency-Key'),
            $this->header('If-Match'),
        ), true);
    }

    /** @param callable():array<string,mixed> $operation */
    private function invoke(callable $operation, bool $etag = false): Json
    {
        try {
            $data = $operation();
        } catch (ReferenceCodeException $exception) {
            throw new ApiProblem($exception->errorCode, $exception->httpStatus, $exception->getMessage());
        }
        return $this->response($data, $etag ? ($data['etag'] ?? null) : null);
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private function jsonBody(array $keys): array
    {
        $body = json_decode((string) $this->request->getContent(), true);
        if (!is_array($body) || array_is_list($body)) {
            throw new ApiProblem('REFERENCE_CODE_REQUEST_INVALID', 422, 'The reference-code request is invalid.');
        }
        $actual = array_keys($body);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new ApiProblem('REFERENCE_CODE_REQUEST_INVALID', 422, 'The reference-code request is invalid.');
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
        $response = json(['data' => $data, 'meta' => ['request_id' => $this->executionContext()->requestId()]]);
        if (is_string($etag) && $etag !== '') {
            $response->header(['ETag' => $etag]);
        }
        return $response;
    }
}
