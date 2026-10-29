<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Validation;

use Cron\CronExpression;
use think\Validate;
use app\common\services\CrontabCommandService;

class CrontabValidate extends Validate
{
    public function __construct(private readonly CrontabCommandService $commands)
    {
        parent::__construct();
    }

    protected $rule = [
        'id'         => 'require|integer|gt:0',
        'name'       => 'require|max:100',
        'type'       => 'require|in:1',
        'command'    => 'require|max:100|checkCommand',
        'status'     => 'require|in:1,2,3',
        'expression' => 'require|max:100|checkExpression',
        'operate'     => 'require|in:start,stop',
    ];

    protected $message = [
        'id.require'         => 'id 不能为空',
        'name.require'       => '请输入定时任务名称',
        'name.max'           => '任务名称最多 100 个字符',
        'type.require'       => '请选择类型',
        'type.in'            => '类型值错误',
        'command.require'    => '请输入命令',
        'command.max'        => '命令最多 100 个字符',
        'status.require'     => '请选择状态',
        'status.in'          => '状态值错误',
        'expression.require' => '请输入运行规则',
        'expression.max'     => '运行规则最多 100 个字符',
    ];

    protected $scene = [
        'add'  => ['name', 'type', 'command', 'status', 'expression'],
        'edit' => ['id', 'name', 'type', 'command', 'status', 'expression'],
        'detail' => ['id'],
        'delete' => ['id'],
        'operate' => ['id', 'operate'],
        'expression' => ['expression'],
    ];

    /** 校验 cron 表达式合法性 */
    protected function checkExpression($value): bool|string
    {
        if (!CronExpression::isValidExpression((string) $value)) {
            return '定时任务运行规则错误';
        }
        return true;
    }

    protected function checkCommand($value): bool|string
    {
        try {
            $this->commands->assertAllowed(trim((string) $value));
            return true;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
