<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use app\common\exception\BusinessException;
use Custom\Modules\AiAssistant\Model\Quota;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\Model;

final readonly class AiQuota
{
    public function __construct(private AiAccess $access) {}
    /** Caller holds the current connection row lock, serializing a new period and its full internal totals. */
    public function reserve(TenantContext $c, array $connection, Model $task): array
    {
        $period = gmdate('Y-m-d');
        $q = Quota::where('tenant_id', $c->tenantId)->where('connection_id', $connection['id'])->where('period', $period)->lock(true)->find();
        if ($q === null) {
            $q = new Quota();
            $q->save(['title' => '日请求额度 ' . $period,'status' => 'active','version' => 1,'created_by' => $c->memberId,'details' => AiAccess::json(['unit' => 'request','pricing' => 'not_configured']),'request_id' => 'quota:' . $connection['id'] . ':' . $period,'created_at' => time(),'updated_at' => time(),'connection_id' => $connection['id'],'period' => $period,'reserved_requests' => 0,'confirmed_requests' => 0,'unknown_requests' => 0]);
        }
        $used = (int) $q->reserved_requests + (int) $q->confirmed_requests + (int) $q->unknown_requests;
        if ($used >= $connection['details']['daily_request_limit']) {
            throw BusinessException::conflict('AI_QUOTA_EXCEEDED', '已预留、已确认及未知请求合计达到机构限额');
        }
        $q->save(['reserved_requests' => (int) $q->reserved_requests + 1,'version' => (int) $q->version + 1,'updated_at' => time()]);
        return ['quota_id' => (int) $q->id,'state' => 'reserved','unit' => 'request','period' => $period];
    }
    public function settle(TenantContext $c, Model $task, array &$d, string $next): void
    {
        if (!in_array($next, ['confirmed','unknown','not_sent'], true)) {
            throw new \InvalidArgumentException('AI_USAGE_STATE_INVALID');
        }
        $old = $d['quota']['state'];
        if ($old === $next) {
            return;
        }
        if (!in_array($old, ['reserved','unknown'], true) || $old === 'unknown' && $next === 'not_sent') {
            throw BusinessException::conflict('AI_USAGE_STATE_CONFLICT', '未知调用不能当作未发送释放');
        }
        $q = Quota::where('tenant_id', $c->tenantId)->where('id', $d['quota']['quota_id'])->lock(true)->find() ?? throw new \RuntimeException('AI_QUOTA_MISSING');
        $column = $old === 'reserved' ? 'reserved_requests' : 'unknown_requests';
        if ((int) $q->$column < 1) {
            throw new \RuntimeException('AI_QUOTA_INTEGRITY_FAILED');
        }
        $changes = [$column => (int) $q->$column - 1,'version' => (int) $q->version + 1,'updated_at' => time()];
        if ($next !== 'not_sent') {
            $target = $next === 'confirmed' ? 'confirmed_requests' : 'unknown_requests';
            $changes[$target] = (int) $q->$target + 1;
        }
        $q->save($changes);
        $d['quota']['state'] = $next;
        $d['usage_outcome'] = $next;
    }
}
