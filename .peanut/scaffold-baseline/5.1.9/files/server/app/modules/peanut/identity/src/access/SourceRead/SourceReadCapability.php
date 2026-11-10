<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\access\SourceRead;

final readonly class SourceReadCapability
{
    /** 接收租户获准使用的功能模块，与提供数据的 moduleKey 分开。 */
    public string $recipientModuleKey;

    /** @param non-empty-list<string> $fields */
    public function __construct(
        public string $key,
        public string $action,
        public string $moduleKey,
        public string $readPermissionKey,
        public string $managePermissionKey,
        public array $fields,
        ?string $recipientModuleKey = null,
    ) {
        // 旧登记省略接收模块时保留原含义；新汇总能力应显式声明双方模块。
        $this->recipientModuleKey = $recipientModuleKey ?? $moduleKey;
        foreach ([$key, $moduleKey, $this->recipientModuleKey, $readPermissionKey, $managePermissionKey] as $value) {
            if (preg_match('/^[a-z][a-z0-9.-]{2,159}$/D', $value) !== 1) {
                throw new \InvalidArgumentException('SOURCE_READ_CAPABILITY_INVALID');
            }
        }
        if (preg_match('/^[a-z][a-z0-9.-]{1,63}$/D', $action) !== 1
            || $fields === [] || !array_is_list($fields)) {
            throw new \InvalidArgumentException('SOURCE_READ_CAPABILITY_INVALID');
        }
        $seen = [];
        foreach ($fields as $field) {
            if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1
                || isset($seen[$field])) {
                throw new \InvalidArgumentException('SOURCE_READ_CAPABILITY_INVALID');
            }
            $seen[$field] = true;
        }
    }
}
