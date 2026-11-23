<?php

declare (strict_types=1);

namespace app;

use app\common\execution\CurrentExecutionContext;
use app\common\validate\InputValidator;
use app\common\validate\ValidatedInput;
use LogicException;
use think\App;
use think\exception\ValidateException;
use think\Request;

/**
 * 控制器基础类
 *
 * @property-read CurrentExecutionContext $context 当前 App 已注册的执行上下文读取器
 */
abstract class BaseController
{
    /** Request 实例 */
    protected Request $request;

    /** 应用实例 */
    protected App $app;

    /** 是否批量验证 */
    protected $batchValidate = false;

    /** 控制器中间件 */
    protected $middleware = [];

    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;
        $this->initialize();
    }

    protected function initialize() {}

    /** 始终从构造当前 Controller 的 App 读取已注册 reader；不创建空上下文。 */
    final protected function executionContext(): CurrentExecutionContext
    {
        return $this->app->get(CurrentExecutionContext::class);
    }

    final public function __get(string $name): mixed
    {
        if ($name === 'context') {
            return $this->executionContext();
        }
        throw new LogicException(sprintf(
            'Undefined readonly controller property: %s::$%s',
            static::class,
            $name,
        ));
    }

    final public function __isset(string $name): bool
    {
        return $name === 'context' && $this->app->has(CurrentExecutionContext::class);
    }

    final public function __set(string $name, mixed $value): never
    {
        throw new LogicException(sprintf(
            'Readonly controller property cannot be written: %s::$%s',
            static::class,
            $name,
        ));
    }

    final public function __unset(string $name): never
    {
        throw new LogicException(sprintf(
            'Readonly controller property cannot be unset: %s::$%s',
            static::class,
            $name,
        ));
    }

    /**
     * 验证数据
     * @throws ValidateException
     */
    protected function validate(
        array $data,
        string|array $validate,
        array $message = [],
        bool $batch = false,
    ): ValidatedInput {
        return $this->app->make(InputValidator::class)->validate(
            $data,
            $validate,
            $message,
            $batch || $this->batchValidate,
        );
    }

    /**
     * 对写入口应用显式字段政策；未知字段默认拒绝，空值与缺字段保持原样。
     *
     * @param array<string,mixed> $data
     * @param string|array<string,mixed> $validate
     * @param array<int|string,mixed> $acceptedFields
     * @param array<int|string,mixed>|null $writableFields
     * @param array<string,string> $message
     */
    protected function validateInput(
        array $data,
        string|array $validate,
        array $acceptedFields,
        ?array $writableFields = null,
        array $message = [],
        bool $batch = false,
        bool $rejectUnknown = true,
    ): ValidatedInput {
        return $this->app->make(InputValidator::class)->validateInput(
            $data,
            $validate,
            $acceptedFields,
            $writableFields,
            $message,
            $batch || $this->batchValidate,
            $rejectUnknown,
        );
    }
}
