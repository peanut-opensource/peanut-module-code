<?php

declare(strict_types=1);

namespace app\common\model\generator;

use app\common\model\InstanceOwnedModel;

/**
 * @property int|string $id
 * @property string $archive_path
 * @property int|string $admin_id
 */
class GeneratorDownload extends InstanceOwnedModel
{
    protected $name = 'generator_download';
}
