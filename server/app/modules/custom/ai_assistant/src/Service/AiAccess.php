<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\services\audit\AuditContractHost;
use Custom\Modules\AiAssistant\Model\{Configuration,GenerationTask,Event};
use PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\db\BaseQuery;
use think\Model;

final readonly class AiAccess
{
    public function __construct(private AdminAuthorizationQuery $authorization, private DataPermissionEngine $engine, private ThinkPhpQueryConstraintApplier $constraints, private CurrentExecutionContext $execution, private AuditContractHost $audit) {}
    public function authorize(TenantContext $c, string $op): void
    {
        if (!$this->authorization->decide($c, $this->authorization->principal($c), 'custom.ai-assistant.' . $op)->allowed) {
            throw BusinessException::forbidden('AI_PERMISSION_DENIED', '当前人员无权办理此操作');
        }
    }
    public function permission(TenantContext $c, string $key): bool
    {
        return $this->authorization->decide($c, $this->authorization->principal($c), $key)->allowed;
    }
    public function query(TenantContext $c, string $kind, string $op = 'view'): BaseQuery
    {
        $this->authorize($c, $op);
        $model = $kind === 'task' ? GenerationTask::class : Configuration::class;
        $q = $model::where('tenant_id', $c->tenantId);
        if ($kind !== 'task') {
            $q->where('kind', $kind);
        }
        $this->constraints->apply($q, $this->engine->queryConstraint($c, 'custom.ai-assistant.' . $kind, $op));
        return $q;
    }
    public function find(TenantContext $c, string $kind, int $id, string $op = 'view', bool $lock = false): Model
    {
        return $this->query($c, $kind, $op)->where('id', $id)->lock($lock)->find() ?? throw BusinessException::notFound('AI_NOT_FOUND', '记录不存在或超出当前范围');
    }
    public function authorizeCreation(TenantContext $c, string $kind): void
    {
        $op = $kind === 'task' ? 'use' : 'configure';
        $this->authorize($c, $op);
        if (!$this->engine->decideCreate($c, 'custom.ai-assistant.' . $kind, $op, new TypedResourceTargetCollection())->allowed) {
            throw BusinessException::forbidden('AI_CREATE_DENIED', '当前数据范围不允许创建或查询其原请求');
        }
    }
    public function create(TenantContext $c, string $kind, string $title, array $data, string $requestId, array $private = []): Model
    {
        $op = $kind === 'task' ? 'use' : 'configure';
        $this->authorize($c, $op);
        if (!$this->engine->decideCreate($c, 'custom.ai-assistant.' . $kind, $op, new TypedResourceTargetCollection())->allowed) {
            throw BusinessException::forbidden('AI_CREATE_DENIED', '当前数据范围不允许创建');
        }
        $model = $kind === 'task' ? GenerationTask::class : Configuration::class;
        $row = new $model();
        $row->save(['created_by' => $c->memberId] + $private + ($kind === 'task' ? [] : ['kind' => $kind,'secret_payload' => null,'deleted_at' => 0]) + ['title' => $title,'status' => $kind === 'task' ? 'preparing' : 'disabled','version' => 1,'created_by' => $c->memberId,'details' => self::json($data),'request_id' => $requestId,'created_at' => time(),'updated_at' => time()]);
        $this->find($c, $kind, (int) $row->id, $op);
        return $row;
    }
    public function cas(Model $r, int $version): void
    {
        if ((int) $r->version !== $version) {
            throw BusinessException::conflict('AI_VERSION_CONFLICT', '记录已变化，请刷新后办理');
        }
    }
    public function update(Model $r, array $data, ?string $status = null): void
    {
        $r->save(['details' => self::json($data),'status' => $status ?? $r->status,'version' => (int) $r->version + 1,'updated_at' => time()]);
    }
    public function event(TenantContext $c, string $kind, Model $r, string $action, string $request, array $input, array $safeResult = [], array $evidence = []): void
    {
        $system = $this->execution->current() instanceof \app\common\execution\SystemExecutionContext;
        $event = new Event();
        $event->save(['title' => $action,'status' => 'recorded','version' => 1,'created_by' => $c->memberId,'details' => self::json(['kind' => $kind,'input_hash' => self::hash($input),'result' => $safeResult,'actor' => $system ? ['type' => 'task_system','actor_key' => $this->execution->system()->actorKey,'initiating_member_id' => $c->memberId] : ['type' => 'tenant_member','member_id' => $c->memberId,'account_id' => $c->accountId]] + ($evidence === [] ? [] : ['evidence' => $evidence])),'request_id' => $request,'created_at' => time(),'updated_at' => time(),'parent_id' => (int) $r->id,'actor_type' => $system ? 'task_system' : 'tenant_member']);
        if ($system) {
            $this->audit->appendTenantSystem($c->tenantId, 'custom.ai-assistant.' . $action, $action, $request, ['kind' => $kind,'id' => (int) $r->id,'version' => (int) $r->version]);
        } else {
            $this->audit->appendTenantMember($c, 'custom.ai-assistant.' . $action, $action, 'custom.ai-assistant.' . $kind, (string) $r->id, metadata: ['version' => (int) $r->version,'request_id' => $request]);
        }
    }
    public function replay(TenantContext $c, string $kind, int $id, string $action, string $request, array $input, string $op): ?array
    {
        $this->find($c, $kind, $id, $op, true);
        $e = Event::where('tenant_id', $c->tenantId)->where('request_id', $request)->find();
        if ($e === null) {
            return null;
        }$d = self::data($e);
        if ($e->title !== $action || (int) $e->parent_id !== $id || (int) $e->created_by !== $c->memberId || ($d['kind'] ?? null) !== $kind || $d['input_hash'] !== self::hash($input)) {
            throw BusinessException::conflict('AI_REQUEST_CONFLICT', '相同请求标识对应不同操作');
        }
        if ($action === 'credential' && !self::credentialWriteVerified($d)) {
            throw BusinessException::conflict('AI_CREDENTIAL_RECEIPT_UNVERIFIED', '原回执缺少存储验证，请核对当前连接并用新请求重新绑定');
        }
        return $d['result'];
    }
    public static function credentialWriteVerified(array $event): bool
    {
        $version = $event['evidence']['stored_version'] ?? null;
        return is_int($version) && $version > 1 && $version === ($event['result']['version'] ?? null);
    }
    public function history(TenantContext $c, string $kind, int $id, ?int $before, int $limit): array
    {
        $this->find($c, $kind, $id);
        $q = Event::where('tenant_id', $c->tenantId)->where('parent_id', $id)->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details,'$.kind')) = ?", [$kind]);
        if ($before !== null) {
            $q->where('id', '<', $before);
        }
        $rows = $q->order('id', 'desc')->limit($limit + 1)->select()->all();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return ['list' => array_map(self::present(...), $rows),'next_before_id' => $more ? (int) end($rows)->id : null];
    }
    public static function data(Model $r): array
    {
        return self::decode($r->details);
    }
    public static function decode(mixed $v): array
    {
        return is_array($v) ? $v : json_decode((string) $v, true, 64, JSON_THROW_ON_ERROR);
    }
    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    public static function hash(mixed $v): string
    {
        return hash('sha256', self::json(self::canonical($v)));
    }
    private static function canonical(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }if (!array_is_list($v)) {
            ksort($v, SORT_STRING);
        }return array_map(self::canonical(...), $v);
    }
    public static function present(Model $r): array
    {
        $d = self::data($r);
        unset($d['_creation'],$d['_secret_hash']);
        if ($r instanceof GenerationTask && isset($d['configuration'])) {
            foreach ($d['configuration'] as $kind => $fixed) {
                $d['configuration'][$kind] = array_intersect_key($fixed, array_flip(['id','title','status','version']));
            }
        }
        return ['id' => (int) $r->id,'title' => (string) $r->title,'status' => (string) $r->status,'version' => (int) $r->version,'created_by' => (int) $r->created_by,'details' => $d,'created_at' => (int) $r->created_at,'updated_at' => (int) $r->updated_at];
    }
}
