<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Model;

use app\common\model\TenantOwnedModel;
use think\model\concern\SoftDelete;

/**
 * 定时任务模型
 *
 * @property string $command
 * @property string $error
 * @property string $params
 * @property int $status
 */
class Crontab extends TenantOwnedModel
{
    use SoftDelete;
    protected $name = 'crontab';
    protected $deleteTime = 'delete_time';
}
