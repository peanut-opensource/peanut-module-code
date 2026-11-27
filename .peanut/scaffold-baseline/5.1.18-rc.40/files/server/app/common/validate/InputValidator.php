<?php

declare(strict_types=1);

namespace app\common\validate;

use app\common\execution\CurrentExecutionContext;
use LogicException;
use think\App;
use think\exception\ValidateException;
use think\Validate;

/** Creates validators through the container and binds trusted execution state. */
final readonly class InputValidator
{
    public function __construct(
        private App $app,
        private CurrentExecutionContext $execution,
    ) {}

    /**
     * @param array<string,mixed> $data
     * @param class-string<Validate>|array<string,mixed> $specification
     * @param array<string,string> $messages
     */
    public function validate(
        array $data,
        string|array $specification,
        array $messages = [],
        bool $batch = false,
    ): ValidatedInput {
        $this->check($data, $specification, $messages, $batch);

        // 未迁移入口只把返回值当“校验已通过”标志；保持完整输入以免旧合法字段静默丢失。
        return new ValidatedInput($data, $data, []);
    }

    /**
     * 使用显式字段树区分动作接受字段与可写字段。
     *
     * 数字键表示当前层字段名；关联键的值可为 true（整值保留）或下一层字段树。
     * 列表节点使用 `'*' => [...]` 声明每一项允许的字段，不能从校验规则字符串猜测。
     *
     * @param array<string,mixed> $data
     * @param class-string<Validate>|array<string,mixed> $specification
     * @param array<int|string,mixed> $acceptedFields
     * @param array<int|string,mixed>|null $writableFields
     * @param array<string,string> $messages
     */
    public function validateInput(
        array $data,
        string|array $specification,
        array $acceptedFields,
        ?array $writableFields = null,
        array $messages = [],
        bool $batch = false,
        bool $rejectUnknown = true,
    ): ValidatedInput {
        $acceptedPolicy = self::normalizePolicy($acceptedFields);
        $writablePolicy = self::normalizePolicy($writableFields ?? $acceptedFields);
        self::assertPolicySubset($writablePolicy, $acceptedPolicy);

        $validated = self::project($data, $acceptedPolicy, '', $rejectUnknown);
        // 未声明字段在进入场景/自定义校验器前即被拒绝，不能影响可信字段的判定。
        $this->check($validated, $specification, $messages, $batch);
        $writable = self::project($validated, $writablePolicy, '', false);

        return new ValidatedInput($data, $validated, $writable);
    }

    /**
     * @param array<string,mixed> $data
     * @param class-string<Validate>|array<string,mixed> $specification
     * @param array<string,string> $messages
     */
    private function check(
        array $data,
        string|array $specification,
        array $messages,
        bool $batch,
    ): void {
        [$validator, $scene] = $this->createValidator($specification);
        if ($validator instanceof TenantContextValidate) {
            $validator->forTenant($this->execution->tenantAdmin());
        }
        if ($scene !== null) {
            if (!$validator->hasScene($scene)) {
                throw new \think\exception\ValidateException('验证场景不存在');
            }
            $validator->scene($scene);
        }
        $validator->message($messages);
        if ($batch) {
            $validator->batch(true);
        }
        $validator->failException(true)->check($data);
    }

    /**
     * @param class-string<Validate>|array<string,mixed> $specification
     * @return array{Validate,?string}
     */
    private function createValidator(string|array $specification): array
    {
        if (is_array($specification)) {
            $validator = $this->app->make(Validate::class, [], true);
            $validator->rule($specification);
            return [$validator, null];
        }

        $scene = null;
        if (str_contains($specification, '.')) {
            [$specification, $scene] = explode('.', $specification, 2);
        }
        $class = str_contains($specification, '\\')
            ? $specification
            : $this->app->parseClass('validate', $specification);
        // Validate 保存 scene/only/append/batch/error 等可变状态，禁止复用容器缓存实例。
        $validator = $this->app->make($class, [], true);
        if (!$validator instanceof Validate) {
            throw new \LogicException(sprintf('%s must extend %s.', $class, Validate::class));
        }

        return [$validator, $scene];
    }

    /** @param array<int|string,mixed> $fields @return array<string,true|array> */
    private static function normalizePolicy(array $fields): array
    {
        $policy = [];
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value) || !self::validFieldName($value)) {
                    throw new LogicException('Input field policy contains an invalid field name.');
                }
                $policy[$value] = true;
                continue;
            }
            if (!self::validFieldName($key) || ($value !== true && !is_array($value))) {
                throw new LogicException('Input field policy contains an invalid field definition.');
            }
            $policy[$key] = $value === true ? true : self::normalizePolicy($value);
        }
        return $policy;
    }

    private static function validFieldName(string $field): bool
    {
        return $field === '*' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field) === 1;
    }

    /** @param array<string,true|array> $subset @param array<string,true|array> $superset */
    private static function assertPolicySubset(array $subset, array $superset, string $path = ''): void
    {
        foreach ($subset as $field => $children) {
            if (!array_key_exists($field, $superset)) {
                throw new LogicException(sprintf(
                    'Writable input field is not accepted: %s.',
                    ltrim($path . '.' . $field, '.'),
                ));
            }
            $acceptedChildren = $superset[$field];
            if ($children === true && is_array($acceptedChildren)) {
                throw new LogicException(sprintf(
                    'Writable input field is broader than accepted input: %s.',
                    ltrim($path . '.' . $field, '.'),
                ));
            }
            if (is_array($children)) {
                if (!is_array($acceptedChildren)) {
                    // accepted=true intentionally accepts the complete value, including a narrower writable tree.
                    continue;
                }
                self::assertPolicySubset($children, $acceptedChildren, $path . '.' . $field);
            }
        }
    }

    /**
     * @param array<mixed> $data
     * @param array<string,true|array> $policy
     * @return array<mixed>
     */
    private static function project(
        array $data,
        array $policy,
        string $path,
        bool $rejectUnknown,
    ): array {
        $wildcard = $policy['*'] ?? null;
        if ($rejectUnknown) {
            foreach ($data as $field => $_value) {
                if (!array_key_exists((string) $field, $policy) && $wildcard === null) {
                    throw new ValidateException(sprintf(
                        '请求包含未声明字段：%s',
                        ltrim($path . '.' . (string) $field, '.'),
                    ));
                }
            }
        }

        $result = [];
        foreach ($data as $field => $value) {
            $definition = $policy[(string) $field] ?? $wildcard;
            if ($definition === null) {
                continue;
            }
            if ($definition === true || $value === null) {
                $result[$field] = $value;
                continue;
            }
            if (!is_array($value)) {
                throw new ValidateException(sprintf(
                    '字段必须是数组：%s',
                    ltrim($path . '.' . (string) $field, '.'),
                ));
            }
            $result[$field] = self::project(
                $value,
                $definition,
                $path . '.' . (string) $field,
                $rejectUnknown,
            );
        }
        return $result;
    }
}
