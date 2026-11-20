<?php

declare(strict_types=1);

namespace app\common\model\generator;

use app\common\model\InstanceOwnedModel;

class GeneratorTable extends InstanceOwnedModel
{
    protected $name = 'generator_table';
    protected $json = ['tree_config', 'relations'];
    protected $jsonAssoc = true;

    public function columns()
    {
        return $this->hasMany(GeneratorColumn::class, 'table_id', 'id')->order('sort', 'asc');
    }
}
