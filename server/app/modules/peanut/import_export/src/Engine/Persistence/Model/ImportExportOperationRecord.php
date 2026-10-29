<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Engine\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class ImportExportOperationRecord extends TenantModel
{
    /** @var string */ protected $name = 'import_export_operation';
    /** MySQL DATETIME(3) 的读出精度必须与业务时间合同一致。 */
    protected $dateFormat = 'Y-m-d H:i:s.v';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'created_by_member_id' => 'integer',
        'processed_rows' => 'integer', 'accepted_rows' => 'integer', 'rejected_rows' => 'integer',
        'total_rows' => 'integer', 'attempt_number' => 'integer', 'revision' => 'integer',
    ];
}
