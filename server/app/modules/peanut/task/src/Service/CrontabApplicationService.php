<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use app\common\enum\CrontabEnum;
use app\common\exception\BusinessException;
use app\common\http\PageResult;
use PeanutAdmin\Modules\Task\Model\Crontab;
use Cron\CronExpression;
use app\common\services\CrontabCommandService;
use app\common\support\PaginationInput;
use think\facade\Db;

/**
 * 定时任务逻辑层
 */
class CrontabApplicationService
{
    public function __construct(
        private readonly CrontabCommandService $commands,
    ) {}

    /** 分页列表：支持 name(模糊) / status 过滤 */
    public function lists(array $params): PageResult
    {
        $where = [];
        if (!empty($params['name'])) {
            $where[] = ['name', 'like', '%' . $params['name'] . '%'];
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $where[] = ['status', '=', (int) $params['status']];
        }

        $pagination = PaginationInput::from($params);

        $pageResult = $pagination->result(Crontab::where($where)
            ->order(['id' => 'desc']));
        $pageResult = $pageResult->map(static fn(Crontab $item): array => $item->toArray());
        $lists = $pageResult->items;

        return new PageResult(self::formatRows($lists), $pageResult->total, $pageResult->page, $pageResult->pageSize);
    }

    public function detail(int $id): array
    {
        $row = Crontab::find($id)?->toArray() ?? [];
        if ($row === []) {
            throw BusinessException::notFound('TASK_CRONTAB_NOT_FOUND', '定时任务不存在');
        }
        return self::formatRows([$row])[0];
    }

    public function add(array $params): bool
    {
        $this->commands->assertAllowed(trim((string) $params['command']));
        Crontab::create([
            'name'       => (string) $params['name'],
            'type'       => (int) $params['type'],
            'command'    => (string) $params['command'],
            'params'     => (string) ($params['params'] ?? ''),
            'status'     => (int) $params['status'],
            'expression' => (string) $params['expression'],
            'sort'       => (int) ($params['sort'] ?? 0),
            'remark'     => (string) ($params['remark'] ?? ''),
            'last_time'  => time(),
        ]);
        return true;
    }

    public function edit(array $params): bool
    {
        $this->commands->assertAllowed(trim((string) $params['command']));
        Db::transaction(function () use ($params): void {
            $crontab = Crontab::where([])
                ->where('id', (int) $params['id'])->lock(true)->findOrEmpty();
            if ($crontab->isEmpty()) {
                throw new \runtimeException('定时任务不存在');
            }
            $crontab->save([
                'name' => (string) $params['name'],
                'type' => (int) $params['type'],
                'command' => trim((string) $params['command']),
                'params' => (string) ($params['params'] ?? ''),
                'status' => (int) $params['status'],
                'expression' => (string) $params['expression'],
                'sort' => (int) ($params['sort'] ?? 0),
                'remark' => (string) ($params['remark'] ?? ''),
            ]);
        });
        return true;
    }

    public function delete(int $id): bool
    {
        $crontab = Crontab::find($id);
        if ($crontab === null) {
            throw new \runtimeException('定时任务不存在');
        }
        $crontab->delete();
        return true;
    }

    /** 运行 / 停止 */
    public function operate(int $id, string $operate): bool
    {
        $crontab = Crontab::find($id);
        if ($crontab === null) {
            throw new \runtimeException('定时任务不存在');
        }
        if ($operate === 'start') {
            $crontab->status = CrontabEnum::START;
            $crontab->error  = ''; // 重新启动时清除历史错误
        } elseif ($operate === 'stop') {
            $crontab->status = CrontabEnum::STOP;
        } else {
            throw new \InvalidArgumentException('操作类型错误');
        }
        $crontab->save();
        return true;
    }

    /** 预览 cron 表达式未来 5 次执行时间 */
    public function expression(string $expression): array
    {
        $cron   = new CronExpression($expression);
        $result = $cron->getMultipleRunDates(5);
        $lists  = [];
        foreach ($result as $k => $date) {
            $lists[] = [
                'time' => $k + 1,
                'date' => $date->format('Y-m-d H:i:s'),
            ];
        }
        return $lists;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function formatRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $type = (int) ($row['type'] ?? 0);
            $status = (int) ($row['status'] ?? 0);
            $row['type_desc'] = CrontabEnum::TYPE_DESC[$type] ?? '';
            $row['status_desc'] = CrontabEnum::STATUS_DESC[$status] ?? '';
            $row['last_time'] = empty($row['last_time'])
                ? ''
                : date('Y-m-d H:i:s', (int) $row['last_time']);
        }
        unset($row);
        return $rows;
    }
}
