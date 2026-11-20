<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Member\Contract\MemberAdministration;
use PeanutAdmin\Modules\Member\Validation\MemberTagValidate;
use think\App;

class MemberTagController extends BaseAdminController
{
    use CrudTrait {
        validatedInput as private validateCrudInput;
    }

    protected const CRUD_VALIDATE = MemberTagValidate::class;
    protected const CRUD_INPUT_FIELDS = [
        'add' => ['name', 'remark'],
        'edit' => ['id', 'name', 'remark'],
        'delete' => ['id'],
    ];
    protected const CRUD_WRITABLE_FIELDS = [
        'add' => ['name', 'remark'],
        'edit' => ['name', 'remark'],
    ];

    public function __construct(
        App $app,
        private readonly MemberAdministration $members,
    ) {
        parent::__construct($app);
    }

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function validatedInput(mixed $context, string $scene, array $params): array
    {
        // Older clients can post a full list record. Only this action's fields reach validation and storage.
        $fields = $this->crudInputFields($scene);
        if ($fields !== null) {
            $params = array_intersect_key($params, array_flip($fields));
        }
        return $this->validateCrudInput($context, $scene, $params);
    }

    protected function performLists(mixed $context, array $params): array
    {
        return $this->members->tags();
    }

    protected function performAdd(mixed $context, array $params): bool
    {
        $this->members->createTag($params);
        return true;
    }

    protected function performEdit(mixed $context, array $params): bool
    {
        $this->members->updateTag($params);
        return true;
    }

    protected function performDelete(mixed $context, array $params): bool
    {
        $this->members->deleteTag((int) $params['id']);
        return true;
    }
}
