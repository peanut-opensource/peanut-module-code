<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Infrastructure;

use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\ReferenceCodes\Contract\DictionaryCustomizationQuery;
use think\facade\Db;

/** Platform defaults are read-only here. Only tenant-owned policy and overlays are written. */
final class ThinkPhpDictionaryCustomizationProvider implements DictionaryCustomizationQuery
{
    public function mode(TenantContext $context, string $type): string
    {
        $systemType = $this->systemType($type);
        if ($systemType === null) {
            return 'tenant';
        }
        $selected = Db::name('dict_type_policy')
            ->where('tenant_id', $context->tenantId)->where('type_code', $type)->value('mode');
        return $this->effectiveMode($systemType, is_string($selected) ? $selected : null);
    }

    public function overrides(TenantContext $context, string $type): array
    {
        $rows = Db::name('dict_entry_override')
            ->where('tenant_id', $context->tenantId)->where('type_code', $type)
            ->field('value,action,name')->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $items[(string) $row['value']] = [
                'action' => (string) $row['action'],
                'name' => $row['name'] === null ? null : (string) $row['name'],
            ];
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    public function systemTypes(TenantContext $context): array
    {
        $types = Db::name('system_dict_type')
            ->field('code,name,is_disable,remark,tenant_mode')->order('code')->select()->toArray();
        $policies = Db::name('dict_type_policy')
            ->where('tenant_id', $context->tenantId)->column('mode', 'type_code');
        foreach ($types as &$type) {
            $type['mode'] = $this->effectiveMode($type, $policies[(string) $type['code']] ?? null);
        }
        unset($type);
        return $types;
    }

    /** @return array{type:array<string,mixed>,items:list<array<string,mixed>>} */
    public function systemTypeDetail(TenantContext $context, string $type): array
    {
        $definition = $this->systemType($type);
        if ($definition === null) {
            throw BusinessException::notFound('SYSTEM_DICTIONARY_TYPE_NOT_FOUND', '平台字典类型不存在');
        }
        $definition['mode'] = $this->mode($context, $type);
        $overrides = $this->overrides($context, $type);
        $items = Db::name('system_dict_data')->where('type_code', $type)
            ->field('name,value,sort,is_disable,remark')->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()->toArray();
        foreach ($items as &$item) {
            $item['override'] = $overrides[(string) $item['value']] ?? null;
        }
        unset($item);
        return ['type' => $definition, 'items' => $items];
    }

    public function saveMode(TenantContext $context, string $type, string $mode): void
    {
        if (!in_array($mode, ['system', 'extend', 'tenant'], true)) {
            throw BusinessException::conflict('DICTIONARY_MODE_INVALID', '字典使用模式无效');
        }
        $definition = $this->systemType($type);
        if ($definition === null || (int) $definition['is_disable'] !== 0) {
            throw BusinessException::notFound('SYSTEM_DICTIONARY_TYPE_NOT_FOUND', '平台字典类型不存在或已停用');
        }
        if ($definition['tenant_mode'] === 'system' && $mode !== 'system') {
            throw BusinessException::conflict('DICTIONARY_SYSTEM_LOCKED', '此字典由平台统一维护，不支持租户修改');
        }
        Db::name('dict_type_policy')->replace()->insert([
            'tenant_id' => $context->tenantId,
            'type_code' => $type,
            'mode' => $mode,
            'update_time' => time(),
        ]);
    }

    public function saveOverride(TenantContext $context, string $type, string $value, string $action, ?string $name): void
    {
        if ($this->mode($context, $type) !== 'extend') {
            throw BusinessException::conflict('DICTIONARY_NOT_EXTENDABLE', '当前字典未启用继承扩展');
        }
        if (!in_array($action, ['hide', 'rename'], true)
            || $value === '' || mb_strlen($value) > 255
            || ($action === 'rename' && ($name === null || trim($name) === '' || mb_strlen($name) > 100))) {
            throw BusinessException::conflict('DICTIONARY_OVERRIDE_INVALID', '字典覆盖参数无效');
        }
        $exists = Db::name('system_dict_data')->where('type_code', $type)
            ->where('value', $value)->where('is_disable', 0)->count();
        if ((int) $exists === 0) {
            throw BusinessException::notFound('SYSTEM_DICTIONARY_ENTRY_NOT_FOUND', '平台字典选项不存在或已停用');
        }
        Db::name('dict_entry_override')->replace()->insert([
            'tenant_id' => $context->tenantId,
            'type_code' => $type,
            'value' => $value,
            'action' => $action,
            'name' => $action === 'rename' ? trim((string) $name) : null,
            'update_time' => time(),
        ]);
    }

    public function deleteOverride(TenantContext $context, string $type, string $value): void
    {
        Db::name('dict_entry_override')->where('tenant_id', $context->tenantId)
            ->where('type_code', $type)->where('value', $value)->delete();
    }

    /** @return ?array<string,mixed> */
    private function systemType(string $type): ?array
    {
        $row = Db::name('system_dict_type')->where('code', $type)
            ->field('code,name,is_disable,remark,tenant_mode')->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $definition */
    private function effectiveMode(array $definition, ?string $selected): string
    {
        if ((int) $definition['is_disable'] !== 0) {
            return 'disabled';
        }
        $default = (string) $definition['tenant_mode'];
        if ($default === 'system') {
            return 'system';
        }
        if (!in_array($default, ['extend', 'tenant'], true)) {
            throw new \LogicException('DICTIONARY_PLATFORM_MODE_INVALID');
        }
        return in_array($selected, ['system', 'extend', 'tenant'], true) ? $selected : $default;
    }
}
