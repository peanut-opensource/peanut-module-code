<?php

declare (strict_types=1);

namespace app;

use app\common\execution\CurrentExecutionContext;
use app\common\validate\InputValidator;
use app\common\validate\ValidatedInput;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use think\App;
use think\db\BaseQuery;
use think\exception\ValidateException;
use think\Model;
use think\Request;
use think\Validate;

/**
 * 控制器基础类
 *
 * @property-read CurrentExecutionContext $context 当前 App 已注册的执行上下文读取器
 */
abstract class BaseController
{
    /** 这些名称属于 Controller 固定运行环境，业务声明不得覆盖。 */
    private const RESERVED_READONLY_PROPERTIES = [
        'app',
        'request',
        'context',
        'batchValidate',
        'middleware',
    ];

    /**
     * Request实例
     */
    protected Request $request;

    /**
     * 应用实例
     */
    protected App $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /** @var list<string> */
    private array $controllerDependencyResolutionStack = [];

    /** @var array<string,Model> Model 只在当前 Controller 操作内复用，不进入 App 容器共享实例。 */
    private array $controllerModelInstances = [];

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;
        $this->assertControllerDependencyDeclarations();

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize() {}

    /**
     * 始终从构造当前 Controller 的 App 读取已注册 reader；不创建空上下文。
     */
    final protected function executionContext(): CurrentExecutionContext
    {
        return $this->app->get(CurrentExecutionContext::class);
    }

    final public function __get(string $name): mixed
    {
        if ($name === 'context') {
            return $this->executionContext();
        }

        $dependencyClass = $this->declaredControllerDependency($name);
        if ($dependencyClass === null) {
            throw new LogicException(sprintf(
                'Undefined readonly controller property: %s::$%s',
                static::class,
                $name,
            ));
        }

        if (in_array($name, $this->controllerDependencyResolutionStack, true)) {
            $cycle = [...$this->controllerDependencyResolutionStack, $name];
            throw new LogicException(sprintf(
                'Circular controller dependency: %s.',
                implode(' -> ', array_map(
                    fn(string $property): string => static::class . '::$' . $property,
                    $cycle,
                )),
            ));
        }

        $this->controllerDependencyResolutionStack[] = $name;
        $previousErrorHandler = null;
        $previousErrorHandler = set_error_handler(function (
            int $severity,
            string $message,
            string $file,
            int $line,
        ) use (&$previousErrorHandler): bool {
            foreach ($this->controllerDependencyResolutionStack as $property) {
                if ($severity === E_WARNING
                    && $message === 'Undefined property: ' . static::class . '::$' . $property
                ) {
                    $cycle = [...$this->controllerDependencyResolutionStack, $property];
                    throw new LogicException(sprintf(
                        'Circular controller dependency: %s.',
                        implode(' -> ', array_map(
                            fn(string $item): string => static::class . '::$' . $item,
                            $cycle,
                        )),
                    ));
                }
            }

            return is_callable($previousErrorHandler)
                ? (bool) $previousErrorHandler($severity, $message, $file, $line)
                : false;
        });
        try {
            // Only the exact recursive-property warning above diagnoses a cycle.
            // An unrelated factory TypeError must retain its original cause and stack.
            return $this->resolveControllerDependency($name, $dependencyClass);
        } finally {
            restore_error_handler();
            array_pop($this->controllerDependencyResolutionStack);
        }
    }

    final public function __isset(string $name): bool
    {
        if ($name === 'context') {
            return $this->app->has(CurrentExecutionContext::class);
        }

        return $this->declaredControllerDependency($name) !== null;
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
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return ValidatedInput
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

    /** 新建记录前由业务 Controller 显式清掉当前操作内的 Model 实例。 */
    final protected function resetControllerModel(string $name): void
    {
        $dependencyClass = $this->declaredControllerDependency($name)
            ?? throw new LogicException(sprintf(
                'Undefined readonly controller property: %s::$%s',
                static::class,
                $name,
            ));
        if (!is_subclass_of($dependencyClass, Model::class)) {
            throw new LogicException(sprintf(
                '%s::$%s is not a Model dependency.',
                static::class,
                $name,
            ));
        }
        unset($this->controllerModelInstances[$name]);
    }

    /** @return class-string<object>|null */
    private function declaredControllerDependency(string $name): ?string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            return null;
        }

        $declarationName = $name . 'Class';
        $declarations = [];
        for ($class = new ReflectionClass($this); $class !== false; $class = $class->getParentClass()) {
            if (!$class->hasProperty($declarationName)) {
                continue;
            }
            $property = $class->getProperty($declarationName);
            if ($property->getDeclaringClass()->getName() === $class->getName()) {
                $declarations[] = $property;
            }
        }
        if ($declarations === []) {
            return null;
        }

        if (in_array($name, self::RESERVED_READONLY_PROPERTIES, true)) {
            throw new LogicException(sprintf(
                'Reserved controller property cannot be declared: %s::$%s.',
                static::class,
                $name,
            ));
        }
        if ((new ReflectionClass($this))->hasProperty($name)) {
            throw new LogicException(sprintf(
                'Controller dependency conflicts with a real property: %s::$%s.',
                static::class,
                $name,
            ));
        }

        foreach ($declarations as $property) {
            $type = $property->getType();
            if (!$property->isProtected()
                || $property->isStatic()
                || !$type instanceof ReflectionNamedType
                || $type->allowsNull()
                || !$type->isBuiltin()
                || $type->getName() !== 'string'
            ) {
                throw new LogicException(sprintf(
                    'Controller dependency declaration must be a non-static protected string: %s::$%s.',
                    $property->getDeclaringClass()->getName(),
                    $declarationName,
                ));
            }
            $defaults = $property->getDeclaringClass()->getDefaultProperties();
            $declared = $defaults[$declarationName] ?? null;
            if (!is_string($declared) || trim($declared) === '') {
                throw new LogicException(sprintf(
                    'Controller dependency declaration must have a source default: %s::$%s.',
                    $property->getDeclaringClass()->getName(),
                    $declarationName,
                ));
            }
        }

        $selected = $declarations[0];
        $dependencyClass = ltrim((string) ($selected->getDeclaringClass()
            ->getDefaultProperties()[$declarationName] ?? ''), '\\');
        if (!class_exists($dependencyClass) && !interface_exists($dependencyClass)) {
            throw new LogicException(sprintf(
                'Controller dependency class does not exist: %s::$%s => %s.',
                static::class,
                $declarationName,
                $dependencyClass,
            ));
        }
        if (interface_exists($dependencyClass)) {
            if (!$this->app->has($dependencyClass)) {
                throw new LogicException(sprintf(
                    'Controller dependency interface is not bound in the current App: %s.',
                    $dependencyClass,
                ));
            }
        } elseif (!(new ReflectionClass($dependencyClass))->isInstantiable()
            && !$this->app->has($dependencyClass)) {
            throw new LogicException(sprintf(
                'Controller dependency class is not instantiable: %s.',
                $dependencyClass,
            ));
        }

        /** @var class-string<object> $dependencyClass */
        return $dependencyClass;
    }

    /** Controller 建立时即拒绝隐藏的保留名、冲突或错误继承声明。 */
    private function assertControllerDependencyDeclarations(): void
    {
        $names = [];
        for ($class = new ReflectionClass($this); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName()
                    || !str_ends_with($property->getName(), 'Class')) {
                    continue;
                }
                $name = substr($property->getName(), 0, -5);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }
        foreach (array_keys($names) as $name) {
            $this->declaredControllerDependency($name);
        }
    }

    /** @param class-string<object> $dependencyClass */
    private function resolveControllerDependency(string $name, string $dependencyClass): object
    {
        if (is_subclass_of($dependencyClass, Model::class)) {
            $object = $this->controllerModelInstances[$name]
                ??= $this->app->make($dependencyClass, [], true);
        } elseif (is_a($dependencyClass, Validate::class, true)
            || is_a($dependencyClass, BaseQuery::class, true)) {
            // Validator 与 Query 都是可变对象，每次读取从干净状态开始。
            $object = $this->app->make($dependencyClass, [], true);
        } elseif (interface_exists($dependencyClass) || !(new ReflectionClass($dependencyClass))->isInstantiable()) {
            $object = $this->app->get($dependencyClass);
        } else {
            $object = $this->app->make($dependencyClass);
        }

        if (!$object instanceof $dependencyClass) {
            throw new LogicException(sprintf(
                'Controller dependency resolved to an invalid type: %s::$%s expected %s, got %s.',
                static::class,
                $name,
                $dependencyClass,
                get_debug_type($object),
            ));
        }
        return $object;
    }

}
