<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use app\common\http\PageResult;

/** Public administrative use cases for the File Module. */
interface FileAdministration
{
    public function lists(array $params): PageResult;

    public function imageAssets(array $params): PageResult;

    public function move(array $ids, int $cid): void;

    public function rename(int $id, string $name): void;

    /** @return array{files_deleted:int,storage_deleted:int} */
    public function delete(array $ids): array;

    /** @return list<array<string,mixed>> */
    public function categoryLists(int $type): array;

    public function addCategory(array $params): void;

    public function editCategory(array $params): void;

    /** @return array{categories_deleted:int,files_deleted:int,storage_deleted:int} */
    public function deleteCategory(int $id): array;
}
