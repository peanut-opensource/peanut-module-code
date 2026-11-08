<?php

declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\DataPermission\Application\DataPolicyAdminService;

/**
 * @property-read DataPolicyAdminService $policies
 * @property-read ResourceOperationCatalog $catalog
 */
final class DataPolicyController extends BaseAdminController
{
    protected string $policiesClass = DataPolicyAdminService::class;
    protected string $catalogClass = ResourceOperationCatalog::class;

    public function detail()
    {
        $input = $this->fields($this->request->get(), ['role_id', 'resource_key', 'operation']);
        return $this->data($this->policies->get(
            $this->tenantAdminContext()->tenantId,
            $this->positive($input['role_id'] ?? null),
            $this->text($input['resource_key'] ?? null, 160),
            $this->text($input['operation'] ?? null, 96),
        ));
    }

    public function replace()
    {
        $input = $this->fields($this->request->put(), ['role_id', 'resource_key', 'operation', 'expected_revision', 'policy']);
        $policy = $input['policy'] ?? null;
        if (!is_array($policy) || array_is_list($policy)) {
            throw AdminAccessException::invalid('DATA_POLICY_INPUT_INVALID', 'A policy object is required.');
        }
        $expected = $input['expected_revision'] ?? null;
        return $this->data($this->policies->replace(
            $this->tenantAdminContext(),
            $this->positive($input['role_id'] ?? null),
            $this->text($input['resource_key'] ?? null, 160),
            $this->text($input['operation'] ?? null, 96),
            $policy,
            $expected === null ? null : $this->positive($expected),
        ));
    }

    public function catalog()
    {
        $input = $this->fields($this->request->get(), ['resource_key', 'operation', 'page_no', 'page_size']);
        $tenantId = $this->tenantAdminContext()->tenantId;
        if (array_key_exists('resource_key', $input) || array_key_exists('operation', $input)) {
            if (array_key_exists('page_no', $input) || array_key_exists('page_size', $input)) {
                throw AdminAccessException::invalid('DATA_POLICY_INPUT_INVALID', 'Select an operation or a catalog page.');
            }
            return $this->data($this->policies->catalog(
                $tenantId,
                $this->text($input['resource_key'] ?? null, 160),
                $this->text($input['operation'] ?? null, 96),
            ));
        }
        $page = new PageRequest($this->positive($input['page_no'] ?? 1), $this->positive($input['page_size'] ?? 20));
        $result = $this->catalog->availableOperations($tenantId, $page);
        return $this->data([
            'items' => array_map(static fn(ResourceOperation $operation): array => [
                'resource_key' => $operation->resourceKey, 'module_key' => $operation->moduleKey,
                'operation' => $operation->operation, 'access_mode' => $operation->accessMode,
                'target_cardinality' => $operation->targetCardinality,
            ], $result['items']),
            'total' => $result['total'], 'page_no' => $page->page, 'page_size' => $page->pageSize,
        ]);
    }

    private function fields(array $input, array $allowed): array
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw AdminAccessException::invalid('DATA_POLICY_INPUT_INVALID', 'Unknown policy request fields are rejected.');
        }
        return $input;
    }

    private function positive(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1)) || (int) $value < 1) {
            throw AdminAccessException::invalid('DATA_POLICY_INPUT_INVALID', 'A positive integer is required.');
        }
        return (int) $value;
    }

    private function text(mixed $value, int $length): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $length) {
            throw AdminAccessException::invalid('DATA_POLICY_INPUT_INVALID', 'A registered resource and operation are required.');
        }
        return $value;
    }
}
