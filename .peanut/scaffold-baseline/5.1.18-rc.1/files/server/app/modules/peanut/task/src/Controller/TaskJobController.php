<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\ApiProblem;
use PeanutAdmin\Modules\Task\Contract\JobRecord;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use PeanutAdmin\Modules\Task\Service\TaskAdminApplicationService;
use think\response\Json;

/** @property-read TaskAdminApplicationService $tasks 当前 App 中声明式解析的控制器依赖。 */
final class TaskJobController extends BaseAdminController
{
    protected string $tasksClass = TaskAdminApplicationService::class;

    public function index(): Json
    {
        try {
            $status = trim((string) $this->request->get('status', 'queued'));
            $page = $this->positiveInteger($this->request->get('page', 1));
            $pageSize = $this->positiveInteger($this->request->get('page_size', 20));
            $result = $this->tasks->jobs(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $status,
                $page,
                $pageSize,
            );
            return json([
                'data' => ['items' => array_map(
                    static fn(JobRecord $job): array => $job->toPublicArray(),
                    $result['items'],
                )],
                'meta' => [
                    'request_id' => $this->executionContext()->requestId(),
                    'page' => $result['page'],
                    'page_size' => $result['page_size'],
                    'total' => $result['total'],
                ],
            ]);
        } catch (TaskJobException $exception) {
            throw $this->problem($exception);
        }
    }

    public function cancel(string $jobKey): Json
    {
        return $this->mutate($jobKey, 'cancel');
    }

    public function retry(string $jobKey): Json
    {
        return $this->mutate($jobKey, 'retry');
    }

    private function mutate(string $jobKey, string $action): Json
    {
        try {
            $revision = $this->positiveInteger($this->request->post('revision'));
            $job = $action === 'cancel'
                ? $this->tasks->cancelJob($this->tenantAdminContext(), $this->tenantAdminActor(), $jobKey, $revision)
                : $this->tasks->retryJob($this->tenantAdminContext(), $this->tenantAdminActor(), $jobKey, $revision);
            return json([
                'data' => $job->toPublicArray(),
                'meta' => ['request_id' => $this->executionContext()->requestId()],
            ]);
        } catch (TaskJobException $exception) {
            throw $this->problem($exception);
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            || (int) $value < 1
        ) {
            throw TaskJobException::invalid();
        }
        return (int) $value;
    }

    private function problem(TaskJobException $exception): ApiProblem
    {
        return new ApiProblem($exception->problemCode, $exception->status, 'Task job request was rejected.');
    }
}
