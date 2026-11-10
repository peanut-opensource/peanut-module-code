<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use Custom\Modules\AiAssistant\Definition\AiDefinitions as D;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentSourceGrants;

final readonly class AiWorkspace
{
    public function __construct(private AiAccess $access, private DocumentQueries $documents) {}
    public function overview(TenantContext $c): array
    {
        $this->access->authorize($c, 'view');
        $permissions = [];
        foreach (D::OPERATIONS as $op) {
            $permissions[$op] = $this->access->permission($c, 'custom.ai-assistant.' . $op);
        }
        foreach (['task_read' => 'peanut.task.jobs.read','task_manage' => 'peanut.task.jobs.manage'] as $op => $key) {
            $permissions[$op] = $this->access->permission($c, $key);
        }
        $sourceGrantsAvailable = $this->documents instanceof DocumentSourceGrants;
        return ['permissions' => $permissions,'current_member_id' => $c->memberId,'current_tenant_id' => $c->tenantId,'capabilities' => D::CAPABILITIES,'result_types' => D::RESULT_TYPES,'protocols' => D::PROTOCOLS,'text_generation' => ['operation' => 'generateText','input' => 'member_text','requires_confirm_send' => true],'execution' => ['scope' => 'fixed_source_worker','state' => $sourceGrantsAvailable ? 'available' : 'blocked','code' => $sourceGrantsAvailable ? null : 'SOURCE_AUTHORIZATION_BRIDGE_REQUIRED','provider_requests_sent' => false,'reason' => $sourceGrantsAvailable ? '固定来源授权合同已接入；实际发送仍须有效配置、来源授权及明确发送确认，概览不发起提供方请求' : '当前Document实现未提供固定来源授权合同；配置和准备可办理，未向提供方发送'],'fields' => D::FIELDS];
    }
    public function listing(TenantContext $c, string $kind, int $page, int $limit, string $search, string $status, string $capability): array
    {
        $actual = $kind === 'usage' ? 'task' : $kind;
        $q = $this->access->query($c, $actual);
        if ($search !== '') {
            $q->whereLike('title', '%' . addcslashes($search, '%_\\') . '%');
        }
        if ($status !== '') {
            $q->where('status', $status);
        }
        if ($capability !== '') {
            $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details,'$.capability')) = ?", [$capability]);
        }
        $count = (clone $q)->count();
        return ['list' => array_map(AiAccess::present(...), $q->order('id', 'desc')->page($page, $limit)->select()->all()),'count' => $count];
    }
    public function detail(TenantContext $c, string $kind, int $id): array
    {
        $r = $this->access->find($c, $kind, $id);
        $h = $this->access->history($c, $kind, $id, null, 50);
        return AiAccess::present($r) + ['history' => $h['list'],'history_next_before_id' => $h['next_before_id']];
    }
    /** List only usable text routes for this consumer without exposing configuration or credentials. */
    public function textRoutes(TenantContext $c, string $consumer, int $page): array
    {
        $enabled = fn(string $kind) => $this->access->query($c, $kind, 'use')->where('status', 'enabled');
        $text = "JSON_UNQUOTE(JSON_EXTRACT(details, '$.capability')) = 'text'";
        $protocols = array_keys(D::PROTOCOL_CAPABILITY, 'text', true);
        $placeholders = implode(',', array_fill(0, count($protocols), '?'));
        $connections = $enabled('connection')->field('id')->whereNotNull('secret_payload')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.protocol')) IN ($placeholders)", $protocols)->buildSql();
        $models = $enabled('model')->field('id')->whereRaw($text)
            ->whereRaw("JSON_EXTRACT(details, '$.connection_id') IN " . $connections)
            ->whereRaw("(JSON_EXTRACT(details, '$.valid_until') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(details, '$.valid_until')) > ?)", [gmdate('Y-m-d\\TH:i:s\\Z')])
            ->buildSql();
        $templates = $enabled('template')->field('id')->whereRaw($text)->buildSql();
        $routes = $enabled('route')->whereRaw($text)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.consumer_module')) = ?", [$consumer])
            ->whereRaw("JSON_EXTRACT(details, '$.model_id') IN " . $models)
            ->whereRaw("JSON_EXTRACT(details, '$.template_id') IN " . $templates);
        $count = (clone $routes)->count();
        $list = [];
        foreach ($routes->field('id,title,version')->order('id', 'desc')->page($page, 50)->select() as $row) {
            $list[] = ['id' => (int) $row->id, 'title' => $row->title, 'version' => (int) $row->version,
                'capability' => 'text', 'consumer_module' => $consumer];
        }
        return ['list' => $list, 'count' => $count, 'page_size' => 50];
    }
    public function lookups(TenantContext $c, string $kind, int $page, string $search, string $operation): array
    {
        $q = $this->access->query($c, $kind, $operation)->where('status', 'enabled');
        if ($search !== '') {
            $q->whereLike('title', '%' . addcslashes($search, '%_\\') . '%');
        }$count = (clone $q)->count();
        $list = [];
        foreach ($q->order('id', 'desc')->page($page, 50)->select() as $row) {
            $d = AiAccess::data($row);
            $list[] = ['id' => (int) $row->id,'title' => $row->title,'version' => (int) $row->version,'capability' => $d['capability'] ?? null,'provider_model_id' => $d['provider_model_id'] ?? null,'consumer_module' => $d['consumer_module'] ?? null];
        }
        return ['list' => $list,'count' => $count];
    }
}
