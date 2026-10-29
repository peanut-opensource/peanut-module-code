<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\ApiProblem;
use PeanutAdmin\Modules\Workflow\Application\WorkflowDefinitionService;
use PeanutAdmin\Modules\Workflow\Application\WorkflowQueries;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;
use PeanutAdmin\Modules\Workflow\Http\WorkflowHttpContext;
use PeanutAdmin\Kernel\Api\ApiException;
use think\response\Json;

/** Full-only contribution, excluded together with design routes in runtime artifacts.
 * @property-read WorkflowDefinitionService $definitions
 * @property-read WorkflowQueries $queries
 * @property-read WorkflowHttpContext $workflowContext
 */
final class WorkflowDefinitionController extends BaseAdminController
{
    protected string $definitionsClass = WorkflowDefinitionService::class;
    protected string $queriesClass = WorkflowQueries::class;
    protected string $workflowContextClass = WorkflowHttpContext::class;

    public function index(): Json
    {
        return $this->respond('read', function ($c): array {
            $result = $this->queries->definitions($c, $this->integer($this->request->get('page', 1)), $this->integer($this->request->get('page_size', 20)));
            return ['data' => ['items' => $result['items']], 'meta' => ['page' => $result['page'], 'page_size' => $result['page_size'], 'total' => $result['total']]];
        }, true);
    }
    public function detail(string $moduleKey, string $workflowKey): Json
    {
        return $this->respond('read', fn($c) => \PeanutAdmin\Modules\Workflow\Http\WorkflowJsonProjection::definition($this->queries->definition($c, $moduleKey, $workflowKey)));
    }
    public function scenario(string $moduleKey, string $workflowKey): Json
    {
        return $this->respond('read', fn($c) => \PeanutAdmin\Modules\Workflow\Http\WorkflowJsonProjection::scenario($this->definitions->scenarioMetadata($c, $moduleKey, $workflowKey)));
    }
    public function saveDraft(string $moduleKey, string $workflowKey): Json
    {
        return $this->respond('write', fn($c) => $this->definitions->saveDraft($c, $moduleKey, $workflowKey, $this->request->put(), $this->key()));
    }
    public function validateGraph(): Json
    {
        return $this->respond('write', function ($c): array {
            $input = $this->request->post();
            \PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph::keys($input, ['graph']);
            if (!is_array($input['graph'])) {
                throw WorkflowException::definitionInvalid('Graph object required.');
            }
            return $this->definitions->validate($c, $input['graph']);
        });
    }
    public function preview(): Json
    {
        return $this->respond('write', function ($c): array {
            $input = $this->request->post();
            \PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph::keys($input, ['graph', 'basis']);
            if (!is_array($input['graph']) || !is_array($input['basis'])) {
                throw WorkflowException::definitionInvalid('Graph and basis objects required.');
            }
            return $this->definitions->preview($c, $input['graph'], $input['basis']);
        });
    }
    public function publish(string $moduleKey, string $workflowKey): Json
    {
        return $this->change($moduleKey, $workflowKey, 'publish');
    }
    public function activate(string $moduleKey, string $workflowKey): Json
    {
        return $this->change($moduleKey, $workflowKey, 'activate');
    }
    public function retire(string $moduleKey, string $workflowKey): Json
    {
        return $this->change($moduleKey, $workflowKey, 'retire');
    }

    private function change(string $module, string $workflow, string $method): Json
    {
        return $this->respond('publish', fn($c) => $this->definitions->$method($c, $module, $workflow, $this->request->post(), $this->key()));
    }
    private function key(): string
    {
        return (string) $this->request->header('Idempotency-Key', '');
    }
    private function integer(mixed $v): int
    {
        $value = filter_var($v, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            throw WorkflowException::definitionInvalid();
        }
        return $value;
    }
    private function respond(string $operation, callable $execute, bool $envelope = false): Json
    {
        try {
            $context = $this->workflowContext->operation('peanut.workflow.definition.' . $operation, 'peanut.workflow.definition', $operation);
            $result = $execute($context);
            $output = $envelope ? $result : ['data' => $result, 'meta' => []];
            $output['meta']['request_id'] = $this->executionContext()->requestId();
            return json($output);
        } catch (\PeanutAdmin\Kernel\Module\ModuleException $e) {
            throw new ApiProblem($e->errorCode, in_array($e->errorCode, ['MODULE_NOT_INSTALLED', 'MODULE_INSTALLATION_FAILED'], true) ? 503 : 403, 'Workflow module is unavailable.');
        } catch (\PeanutAdmin\DataPermission\Exception\DataAuthorizationException $e) {
            throw new ApiProblem($e->errorCode, 403, $e->getMessage());
        } catch (WorkflowException $e) {
            throw new ApiProblem($e->errorCode, $e->httpStatus, $e->getMessage(), $e->httpStatus === 422 ? ['issues' => [$e->validationIssue()]] : []);
        } catch (ApiException|\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException $e) {
            throw new ApiProblem($e->errorCode, $e->httpStatus, $e->getMessage());
        } catch (\Throwable) {
            throw new ApiProblem('INTERNAL_ERROR', 500, 'The workflow definition request could not be completed.');
        }
    }
}
