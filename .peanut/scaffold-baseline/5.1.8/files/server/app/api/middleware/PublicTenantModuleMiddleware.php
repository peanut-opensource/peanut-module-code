<?php

declare(strict_types=1);

namespace app\api\middleware;

use app\common\execution\ConsumerExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblem;
use app\common\http\RequestTrace;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;

/** Establishes a Host-bound public Tenant and, when present, its Module boundary. */
final class PublicTenantModuleMiddleware
{
    /** @var array<string,list<string>> */
    private const RESTRICTED_OPERATIONS = [
        'peanut.hot-search.public-read' => ['hot-search.lists'],
        'peanut.member.public-auth' => ['member.register', 'member.login'],
        'peanut.notice.verification' => ['notice.verification.send', 'notice.verification.verify'],
    ];

    public function __construct(
        private readonly ExecutionContextStore $executionContexts,
        private readonly CurrentExecutionContext $executionContext,
        private readonly TenantEntryBindingResolver $entryBindings,
        private readonly ModuleExecutionBoundary $modules,
    ) {}

    public function handle(
        $request,
        \Closure $next,
        string $actor,
        string $moduleKey,
        string $operation,
    ) {
        if (isset(self::RESTRICTED_OPERATIONS[$actor])
            && !in_array($operation, self::RESTRICTED_OPERATIONS[$actor], true)) {
            throw ApiProblem::fromEnvelope('默认租户不可用', null, 50300);
        }

        try {
            $context = $this->entryBindings->system(
                $request,
                TenantEntryBindingResolver::MEMBER_CLIENT,
                $actor,
                $operation,
                RequestTrace::id($this->executionContext, $request, 'public'),
            );
        } catch (\DomainException|\InvalidArgumentException|\PDOException) {
            throw ApiProblem::fromEnvelope(
                $moduleKey === 'peanut.article' ? '默认租户不可用' : '租户入口不可用',
                null,
                50300,
            );
        }

        return $this->executionContexts->run(
            ConsumerExecutionContext::publicTenant($context),
            function () use ($moduleKey, $next, $operation, $request) {
                // An empty key denotes an application-owned capability without a Module manifest.
                if ($moduleKey !== '') {
                    try {
                        $this->modules->assertHttp($moduleKey, $operation);
                    } catch (ModuleException $exception) {
                        if ($moduleKey === 'peanut.article') {
                            throw ApiProblem::fromEnvelope(
                                '文章模块当前不可用',
                                ['error_code' => $exception->errorCode],
                                40400,
                            );
                        }
                        throw $exception;
                    }
                }

                return $next($request);
            },
        );
    }
}
