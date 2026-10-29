<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\ApiProblem;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;
use PeanutAdmin\Modules\ImportExport\Service\ImportExportAdminApplicationService;
use think\Response;
use think\response\Json;

/** @property-read ImportExportAdminApplicationService $operations 当前 App 中声明式解析的控制器依赖。 */
final class ImportExportOperationController extends BaseAdminController
{
    protected string $operationsClass = ImportExportAdminApplicationService::class;

    public function index(): Json
    {
        try {
            $status = trim((string) $this->request->get('status', 'queued'));
            $page = $this->positiveInteger($this->request->get('page', 1));
            $pageSize = $this->positiveInteger($this->request->get('page_size', 20));
            $result = $this->operations->operations(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $status,
                $page,
                $pageSize,
            );
            return json([
                'data' => ['items' => array_map(
                    static fn(AsyncExportOperation $operation): array => $operation->toPublicArray(),
                    $result['items'],
                )],
                'meta' => [
                    'request_id' => $this->executionContext()->requestId(),
                    'page' => $result['page'],
                    'page_size' => $result['page_size'],
                    'total' => $result['total'],
                ],
            ]);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    public function submitImport(): Json
    {
        try {
            $mapping = $this->request->post('mapping', []);
            if (!is_array($mapping) || array_is_list($mapping)) {
                throw ImportExportException::invalid();
            }
            $operation = $this->operations->submitImport(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                trim((string) $this->request->post('provider_key', '')),
                trim((string) $this->request->post('file_key', '')),
                $mapping,
                $this->idempotencyKey(),
            );
            return $this->operationResponse($operation, 201);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    public function submitExport(): Json
    {
        try {
            $operation = $this->operations->submitExport(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                trim((string) $this->request->post('provider_key', '')),
                $this->idempotencyKey(),
            );
            return $this->operationResponse($operation, 201);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    public function cancel(string $operationKey): Json
    {
        try {
            $operation = $this->operations->cancel(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $operationKey,
                $this->positiveInteger($this->request->post('revision')),
            );
            return $this->operationResponse($operation);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    public function download(string $fileKey): Response
    {
        try {
            $file = $this->operations->download(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $fileKey,
            );
            return redirect($file['url'])->header(['Cache-Control' => 'no-store']);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    private function operationResponse(AsyncExportOperation $operation, int $status = 200): Json
    {
        return json([
            'data' => $operation->toPublicArray(),
            'meta' => ['request_id' => $this->executionContext()->requestId()],
        ], $status);
    }

    private function idempotencyKey(): string
    {
        $value = trim((string) $this->request->header('Idempotency-Key', ''));
        if (strlen($value) < 8 || strlen($value) > 160 || preg_match('/^[\x21-\x7e]+$/D', $value) !== 1) {
            throw ImportExportException::invalid();
        }
        return $value;
    }

    private function positiveInteger(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            || (int) $value < 1
        ) {
            throw ImportExportException::invalid();
        }
        return (int) $value;
    }

    private function problem(ImportExportException $exception): ApiProblem
    {
        $status = match ($exception->problemCode) {
            'IMPORT_EXPORT_PERMISSION_DENIED' => 403,
            'IMPORT_EXPORT_NOT_FOUND', 'IMPORT_EXPORT_FILE_UNAVAILABLE' => 404,
            'IMPORT_EXPORT_IDEMPOTENCY_CONFLICT', 'IMPORT_EXPORT_STATE_CONFLICT' => 409,
            'IMPORT_EXPORT_PROVIDER_UNAVAILABLE' => 503,
            default => 422,
        };
        return new ApiProblem($exception->problemCode, $status, 'Import/export request was rejected.');
    }
}
