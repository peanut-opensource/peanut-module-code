<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\ApiProblem;
use PeanutAdmin\Modules\Workflow\Application\WorkflowCommands;
use PeanutAdmin\Modules\Workflow\Application\WorkflowQueries;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;
use PeanutAdmin\Modules\Workflow\Http\WorkflowHttpContext;
use PeanutAdmin\Kernel\Api\ApiException;
use think\response\Json;

/** @property-read WorkflowCommands $commands
 * @property-read WorkflowQueries $queries
 * @property-read WorkflowHttpContext $workflowContext
 * @property-read \PeanutAdmin\Modules\Workflow\Application\WorkflowNotificationDelivery $notifications
 */
final class WorkflowController extends BaseAdminController
{
    protected string $commandsClass = WorkflowCommands::class;
    protected string $queriesClass = WorkflowQueries::class;
    protected string $workflowContextClass = WorkflowHttpContext::class;
    protected string $notificationsClass = \PeanutAdmin\Modules\Workflow\Application\WorkflowNotificationDelivery::class;

    public function summary(string $moduleKey, string $workflowKey): Json
    {
        return $this->respond(fn() => $this->queries->activeDefinitionSummary($this->context('instance.start', 'start'), $moduleKey, $workflowKey, (string) $this->request->get('subject_type', ''), (string) $this->request->get('subject_key', '')));
    }

    public function start(): Json
    {
        return $this->respond(fn() => $this->commands->start($this->context('instance.start', 'start'), $this->request->post(), $this->idempotency()));
    }
    public function instance(string $instanceKey): Json
    {
        return $this->respond(fn() => $this->queries->instance($this->context('instance.read', 'read'), $instanceKey));
    }
    public function events(string $instanceKey): Json
    {
        return $this->respond(fn() => ['items' => array_map(static function (array $event): array {
            $event['metadata'] = (object) $event['metadata'];
            return $event;
        }, $this->queries->events($this->context('instance.read', 'read'), $instanceKey, $this->integer($this->request->get('after_sequence', 0), true), $this->integer($this->request->get('page_size', 20))))]);
    }
    public function materials(string $instanceKey): Json
    {
        return $this->respond(fn() => $this->queries->materials($this->context('instance.read', 'read'), $instanceKey));
    }
    public function inbox(): Json
    {
        return $this->respond(fn() => $this->queries->inbox($this->context('instance.read', 'read'), (string) $this->request->get('filter', 'assigned'), $this->integer($this->request->get('page', 1)), $this->integer($this->request->get('page_size', 20))), true);
    }
    public function claim(string $workItemKey): Json
    {
        return $this->task($workItemKey, 'claimTask', 'claim');
    }
    public function release(string $workItemKey): Json
    {
        return $this->task($workItemKey, 'releaseTask', 'release');
    }
    public function decide(string $workItemKey): Json
    {
        return $this->task($workItemKey, 'decide', 'decide');
    }
    public function complete(string $workItemKey): Json
    {
        return $this->task($workItemKey, 'completeTask', 'complete');
    }
    public function returnTask(string $workItemKey): Json
    {
        return $this->task($workItemKey, 'returnTask', 'return');
    }
    public function resubmit(string $workItemKey): Json
    {
        return $this->respond(fn() => $this->commands->resubmit($this->context('instance.resubmit', 'resubmit'), $workItemKey, $this->request->post(), $this->idempotency()));
    }
    public function withdraw(string $instanceKey): Json
    {
        return $this->instanceAction($instanceKey, 'withdraw', 'instance.withdraw');
    }
    public function terminate(string $instanceKey): Json
    {
        return $this->respond(function () use ($instanceKey): array {
            $request = $this->request->post();
            $taskContext = array_key_exists('expected_job_revision', $request) ? $this->workflowContext->operation('peanut.task.jobs.manage', 'peanut.task-job', 'manage') : null;
            return $this->commands->terminate($this->context('instance.terminate', 'terminate'), $instanceKey, $request, $this->idempotency(), $taskContext);
        });
    }
    public function retry(string $instanceKey): Json
    {
        return $this->instanceAction($instanceKey, 'retryBlocked', 'diagnostics.retry');
    }
    public function reassign(string $instanceKey): Json
    {
        return $this->instanceAction($instanceKey, 'reassignPending', 'diagnostics.reassign');
    }
    public function reconcile(string $instanceKey): Json
    {
        return $this->serviceRecovery($instanceKey, false);
    }
    public function recover(string $instanceKey): Json
    {
        return $this->serviceRecovery($instanceKey, true);
    }
    public function diagnostics(string $instanceKey): Json
    {
        return $this->respond(fn() => $this->commands->taskDiagnostics($this->context('diagnostics.read', 'read'), $this->workflowContext->operation('peanut.task.jobs.read', 'peanut.task-job', 'read'), $instanceKey, $this->workflowContext->optionalOperation('peanut.task.jobs.manage', 'peanut.task-job', 'manage')));
    }
    public function notificationDiagnostics(string $instanceKey): Json
    {
        return $this->respond(fn() => $this->notifications->diagnostics($this->context('diagnostics.read', 'read'), $this->workflowContext->operation('peanut.task.jobs.read', 'peanut.task-job', 'read'), $instanceKey, $this->integer($this->request->get('page', 1)), $this->integer($this->request->get('page_size', 20)), $this->workflowContext->optionalOperation('peanut.task.jobs.manage', 'peanut.task-job', 'manage')), true);
    }
    public function recoverNotification(string $instanceKey): Json
    {
        return $this->respond(fn() => $this->notifications->recover($this->context('diagnostics.retry', 'retry'),
            $this->workflowContext->operation('peanut.task.jobs.manage', 'peanut.task-job', 'manage'), $instanceKey, $this->request->post(), $this->idempotency()));
    }

    private function serviceRecovery(string $instanceKey, bool $recover): Json
    {
        return $this->respond(function () use ($instanceKey, $recover): array {
            $context = $this->context($recover ? 'diagnostics.retry' : 'diagnostics.read', $recover ? 'retry' : 'read');
            $taskContext = $this->workflowContext->operation('peanut.task.jobs.manage', 'peanut.task-job', 'manage');
            return $recover
                ? $this->commands->recoverServiceTask($context, $taskContext, $instanceKey, $this->request->post(), $this->idempotency())
                : $this->commands->reconcileTask($context, $taskContext, $instanceKey, $this->request->post(), $this->idempotency());
        });
    }

    private function task(string $key, string $method, string $operation): Json
    {
        return $this->respond(function () use ($key, $method, $operation): array {
            $context = $this->context('task.' . $operation, $operation);
            $result = $this->commands->$method($context, $key, $this->request->post(), $this->idempotency());
            $this->notifications->dispatchCommitted($context, $result['instance_key'], $result['event_cursor']);
            return $result;
        });
    }
    private function instanceAction(string $key, string $method, string $permission): Json
    {
        return $this->respond(fn() => $this->commands->$method($this->context($permission, $method), $key, $this->request->post(), $this->idempotency()));
    }
    private function context(string $permission, string $operation): \PeanutAdmin\Kernel\Context\AuthorizedOperationContext
    {
        return $this->workflowContext->operation('peanut.workflow.' . $permission, 'peanut.workflow.instance', $operation);
    }
    private function idempotency(): string
    {
        return (string) $this->request->header('Idempotency-Key', '');
    }
    private function integer(mixed $v, bool $zero = false): int
    {
        if (!is_int($v) && !(is_string($v) && preg_match('/^\d+$/D', $v) === 1)) {
            throw WorkflowException::definitionInvalid('Invalid integer.');
        }
        $value = filter_var($v, FILTER_VALIDATE_INT);
        if ($value === false || $value < ($zero ? 0 : 1)) {
            throw WorkflowException::definitionInvalid('Invalid integer.');
        }
        return $value;
    }
    /** Read after the failed command's transaction has rolled back; never expose lock-time rows. */
    private function conflictState(?string $instanceKey): ?array
    {
        if ($instanceKey === null) {
            return null;
        }
        try {
            $read = $this->workflowContext->operation('peanut.workflow.instance.read', 'peanut.workflow.instance', 'read');
            return $this->queries->instance($read, $instanceKey);
        } catch (\PeanutAdmin\DataPermission\Exception\DataAuthorizationException $e) {
            if ($e->errorCode === 'AUTHZ_PERMISSION_DENIED') {
                return null;
            }
            throw new ApiProblem($e->errorCode, 403, $e->getMessage());
        } catch (\app\common\exception\BusinessException $e) {
            if (in_array($e->errorCode, ['ARTICLE_MATERIAL_PERMISSION_DENIED', 'ARTICLE_MATERIAL_READ_DENIED', 'ARTICLE_NOT_FOUND', 'ARTICLE_MATERIAL_AUTHORIZATION_STALE'], true)) {
                return null;
            }
            throw new ApiProblem('INTERNAL_ERROR', 500, 'The current workflow state could not be read.');
        } catch (\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException $e) {
            if (in_array($e->problemCode, ['TENANT_SESSION_ACCESS_DENIED', 'TENANT_SESSION_NOT_FOUND'], true)) {
                return null;
            }
            throw new ApiProblem('INTERNAL_ERROR', 500, 'The current workflow state could not be read.');
        } catch (WorkflowException $e) {
            if (in_array($e->errorCode, ['WORKFLOW_ASSIGNMENT_DENIED', 'WORKFLOW_SUBJECT_NOT_FOUND'], true)) {
                return null;
            }
            throw new ApiProblem($e->errorCode, $e->httpStatus, $e->getMessage());
        } catch (\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException $e) {
            if (in_array($e->errorCode, ['RESOURCE_NOT_FOUND', 'WORKFLOW_BUSINESS_ACCESS_DENIED'], true)) {
                return null;
            }
            throw new ApiProblem($e->errorCode, $e->httpStatus, $e->getMessage());
        } catch (ApiException $e) {
            throw new ApiProblem($e->errorCode, $e->httpStatus, $e->getMessage());
        } catch (\Throwable) {
            throw new ApiProblem('INTERNAL_ERROR', 500, 'The current workflow state could not be read.');
        }
    }
    private function respond(callable $operation, bool $page = false): Json
    {
        try {
            $result = $operation();
            $meta = ['request_id' => $this->executionContext()->requestId()];
            if ($page) {
                $meta += ['page' => $result['page'], 'page_size' => $result['page_size'], 'total' => $result['total']];
                $result = ['items' => $result['items']];
            }
            return json(['data' => $result, 'meta' => $meta]);
        } catch (\PeanutAdmin\Kernel\Module\ModuleException $e) {
            throw new ApiProblem($e->errorCode, in_array($e->errorCode, ['MODULE_NOT_INSTALLED', 'MODULE_INSTALLATION_FAILED'], true) ? 503 : 403, 'Workflow module is unavailable.');
        } catch (\PeanutAdmin\Modules\Task\Job\Application\TaskJobException $e) {
            throw new ApiProblem($e->problemCode, $e->status, $e->getMessage());
        } catch (\PeanutAdmin\DataPermission\Exception\DataAuthorizationException $e) {
            throw new ApiProblem($e->errorCode, 403, $e->getMessage());
        } catch (WorkflowException $e) {
            $details = $e->errorCode === 'WORKFLOW_INSTANCE_CONFLICT'
                ? ['current_state' => $this->conflictState($e->conflictInstanceKey)]
                : [];
            throw new ApiProblem($e->errorCode, $e->httpStatus, $e->getMessage(), $details);
        } catch (ApiException|\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException $e) {
            throw new ApiProblem($e->errorCode, $e->httpStatus, $e->getMessage());
        } catch (\Throwable) {
            throw new ApiProblem('INTERNAL_ERROR', 500, 'The workflow request could not be completed.');
        }
    }
}
