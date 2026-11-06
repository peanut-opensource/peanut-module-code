<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Controller;

use app\adminapi\controller\BaseAdminController;
use Custom\Modules\AiAssistant\Definition\AiDefinitions as D;
use Custom\Modules\AiAssistant\Validation\AiInput as I;
use Custom\Modules\AiAssistant\Service\{AiWorkspace,AiConfiguration,AiTasks,AiCandidates,AiAccess};

final class AiController extends BaseAdminController
{
    private function kind(mixed $v, bool $tasks = true): string
    {
        return I::choice($v, $tasks ? [...D::KINDS,'task','usage'] : D::KINDS);
    }
    public function overview()
    {
        return $this->data(app()->make(AiWorkspace::class)->overview($this->tenantAdminContext()));
    }
    public function listing()
    {
        $p = $this->request->get();
        I::only($p, ['kind','page','limit','search','status','capability']);
        return $this->data(app()->make(AiWorkspace::class)->listing($this->tenantAdminContext(), $this->kind($p['kind'] ?? null), I::integer($p['page'] ?? 1), I::integer($p['limit'] ?? 30, 1, 100), I::text($p['search'] ?? '', 200, false), I::text($p['status'] ?? '', 40, false), isset($p['capability']) && $p['capability'] !== '' ? I::choice($p['capability'], D::CAPABILITIES) : ''));
    }
    public function detail()
    {
        $p = $this->request->get();
        I::only($p, ['kind','id']);
        $kind = $this->kind($p['kind'] ?? null);
        if ($kind === 'usage') {
            $kind = 'task';
        }return $this->data(app()->make(AiWorkspace::class)->detail($this->tenantAdminContext(), $kind, I::integer($p['id'] ?? null)));
    }
    public function history()
    {
        $p = $this->request->get();
        I::only($p, ['kind','id','before_id','limit']);
        $kind = $this->kind($p['kind'] ?? null);
        if ($kind === 'usage') {
            $kind = 'task';
        }return $this->data(app()->make(AiAccess::class)->history($this->tenantAdminContext(), $kind, I::integer($p['id'] ?? null), isset($p['before_id']) ? I::integer($p['before_id']) : null, I::integer($p['limit'] ?? 50, 1, 100)));
    }
    public function textRoutes()
    {
        $p = $this->request->get();
        I::only($p, ['consumer_module','page']);
        return $this->data(app()->make(AiWorkspace::class)->textRoutes($this->tenantAdminContext(),
            I::consumerModule($p['consumer_module'] ?? null), I::integer($p['page'] ?? 1)));
    }
    public function lookups()
    {
        $p = $this->request->get();
        I::only($p, ['kind','page','search','operation']);
        return $this->data(app()->make(AiWorkspace::class)->lookups($this->tenantAdminContext(), $this->kind($p['kind'] ?? null, false), I::integer($p['page'] ?? 1), I::text($p['search'] ?? '', 200, false), I::choice($p['operation'] ?? 'use', ['use','configure'])));
    }
    public function requestStatus()
    {
        return $this->data(app()->make(\Custom\Modules\AiAssistant\Service\AiRequestStatus::class)->status($this->tenantAdminContext(), $this->request->get()));
    }
    public function saveConfiguration()
    {
        $p = $this->request->post();
        I::only($p, ['kind','id','version','title','data','request_id']);
        $id = isset($p['id']) ? I::integer($p['id']) : null;
        $version = $id !== null ? I::integer($p['version'] ?? null) : null;
        return $this->data(app()->make(AiConfiguration::class)->save($this->tenantAdminContext(), $this->kind($p['kind'] ?? null, false), $id, $version, I::text($p['title'] ?? null, 200), I::object($p['data'] ?? null), I::requestId($p['request_id'] ?? null)));
    }
    public function saveCredential()
    {
        $p = $this->request->post();
        I::only($p, ['id','version','secret','request_id']);
        $secret = I::text($p['secret'] ?? null, 8192);
        try {
            return $this->data(app()->make(AiConfiguration::class)->secret($this->tenantAdminContext(), I::integer($p['id'] ?? null), I::integer($p['version'] ?? null), $secret, I::requestId($p['request_id'] ?? null)));
        } finally {
            unset($secret);
        }
    }
    public function configurationState()
    {
        $p = $this->request->post();
        I::only($p, ['kind','id','version','action','reason','request_id']);
        return $this->data(app()->make(AiConfiguration::class)->state($this->tenantAdminContext(), $this->kind($p['kind'] ?? null, false), I::integer($p['id'] ?? null), I::integer($p['version'] ?? null), I::choice($p['action'] ?? null, ['enable','disable']), I::requestId($p['request_id'] ?? null), I::text($p['reason'] ?? null, 2000)));
    }
    public function generateText()
    {
        return $this->data(app()->make(\Custom\Modules\AiAssistant\Contract\AiTextGeneration::class)->generateText($this->request->post()));
    }
    public function createTask()
    {
        $p = $this->request->post();
        I::only($p, ['title','route_id','document_id','revision_id','instruction','consumer_module','request_id','repeat_of_task_id','confirm_possible_duplicate_cost','rework_of_task_id','rework_of_task_version']);
        $input = ['route_id' => I::integer($p['route_id'] ?? null),'document_id' => I::integer($p['document_id'] ?? null),'revision_id' => I::integer($p['revision_id'] ?? null),'instruction' => I::text($p['instruction'] ?? '', 4000, false),'consumer_module' => I::consumerModule($p['consumer_module'] ?? null),'confirm_possible_duplicate_cost' => I::boolean($p['confirm_possible_duplicate_cost'] ?? false)];
        if (isset($p['repeat_of_task_id'])) {
            $input['repeat_of_task_id'] = I::integer($p['repeat_of_task_id']);
        }
        if (isset($p['rework_of_task_id']) || isset($p['rework_of_task_version'])) {
            $input['rework_of_task_id'] = I::integer($p['rework_of_task_id'] ?? null);
            $input['rework_of_task_version'] = I::integer($p['rework_of_task_version'] ?? null);
        }
        return $this->data(app()->make(AiTasks::class)->create($this->tenantAdminContext(), I::text($p['title'] ?? null, 200), $input, I::requestId($p['request_id'] ?? null)));
    }
    public function taskStatus()
    {
        $p = $this->request->get();
        I::only($p, ['id']);
        return $this->data(app()->make(AiTasks::class)->status($this->tenantAdminContext(), I::integer($p['id'] ?? null)));
    }
    public function taskAction()
    {
        $p = $this->request->post();
        I::only($p, ['id','action','job_revision','request_id']);
        return $this->data(app()->make(AiTasks::class)->command($this->tenantAdminContext(), I::integer($p['id'] ?? null), I::choice($p['action'] ?? null, ['cancel','retry','observeUnknown','poll']), I::integer($p['job_revision'] ?? null), I::requestId($p['request_id'] ?? null)));
    }
    public function manualCandidate()
    {
        $p = $this->request->post();
        I::only($p, ['id','version','result_type','text','file_key','reason','request_id']);
        return $this->data(app()->make(\Custom\Modules\AiAssistant\Service\AiManualCandidates::class)->submit($this->tenantAdminContext(), I::integer($p['id'] ?? null), I::integer($p['version'] ?? null), I::choice($p['result_type'] ?? null, ['text','transcript','image','audio','video']), isset($p['text']) ? I::text($p['text'], 1000000) : null, isset($p['file_key']) ? I::file($p['file_key']) : null, I::text($p['reason'] ?? null, 4000), I::requestId($p['request_id'] ?? null)));
    }
    public function collectCandidate()
    {
        $p = $this->request->post();
        I::only($p, ['id','version','request_id','recovered_file_key']);
        return $this->data(app()->make(AiCandidates::class)->collect($this->tenantAdminContext(), I::integer($p['id'] ?? null), I::integer($p['version'] ?? null), I::requestId($p['request_id'] ?? null), isset($p['recovered_file_key']) ? I::file($p['recovered_file_key']) : null));
    }
    public function candidate()
    {
        $p = $this->request->get();
        I::only($p, ['id']);
        return $this->data(app()->make(AiCandidates::class)->inspect($this->tenantAdminContext(), I::integer($p['id'] ?? null)));
    }
    public function decide()
    {
        $p = $this->request->post();
        I::only($p, ['id','version','decision','reason','request_id']);
        return $this->data(app()->make(AiCandidates::class)->decide($this->tenantAdminContext(), I::integer($p['id'] ?? null), I::integer($p['version'] ?? null), I::choice($p['decision'] ?? null, ['accept','return']), I::text($p['reason'] ?? null, 4000), I::requestId($p['request_id'] ?? null)));
    }
    public function candidateLifecycle()
    {
        $p = $this->request->post();
        I::only($p, ['id','version','action','reason','request_id','replacement_task_id','replacement_task_version']);
        return $this->data(app()->make(AiCandidates::class)->lifecycle(
            $this->tenantAdminContext(),
            I::integer($p['id'] ?? null),
            I::integer($p['version'] ?? null),
            I::choice($p['action'] ?? null, ['withdraw','rework','supersede']),
            I::text($p['reason'] ?? null, 4000),
            I::requestId($p['request_id'] ?? null),
            isset($p['replacement_task_id']) ? I::integer($p['replacement_task_id']) : null,
            isset($p['replacement_task_version']) ? I::integer($p['replacement_task_version']) : null,
        ));
    }
    public function resolve()
    {
        $p = $this->request->post();
        I::only($p, ['id','operation']);
        return $this->data(app()->make(AiCandidates::class)->resolve($this->tenantAdminContext(), I::integer($p['id'] ?? null), I::choice($p['operation'] ?? null, ['preview','download_original'])));
    }
}
