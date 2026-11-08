<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

/** Read-only task projection shared with registered dependent modules. */
interface TaskJobRecordView
{
    /**
     * @return array{
     *   job_key:string,task_type:string,status:string,attempt_count:int,max_attempts:int,
     *   revision:int,last_error_code:?string,available_at:string,created_at:string,
     *   updated_at:string,completed_at:?string
     * }
     */
    public function toPublicArray(): array;
}
