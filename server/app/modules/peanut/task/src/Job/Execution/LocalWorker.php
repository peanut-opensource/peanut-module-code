<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Job\Execution;

use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use think\facade\Db;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\LeaseLostException;
use PeanutAdmin\Modules\Task\Contract\RetryableTaskException;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use Throwable;
use Closure;

final readonly class LocalWorker
{
    public function __construct(
        private int $tenantId,
        private string $workerId,
        private TaskJobStore $repository,
        private TaskHandlerRegistry $handlers,
        private JobHandlerAdapter $authorization,
        /** Host-owned lifecycle, invoked only after signed claim reauthorization. */
        private Closure $executeAuthorizedJob,
        private int $leaseSeconds = 60,
        private ?string $negativeSigningKey = null,
    ) {
        if ($tenantId < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $workerId) !== 1
            || $leaseSeconds < 5 || $leaseSeconds > 3600
        ) {
            throw TaskJobException::invalid();
        }
    }

    public function runOnce(): ?string
    {
        $claim = Db::transaction(
            fn(): ?JobClaim => $this->repository->claim($this->tenantId, $this->workerId, $this->leaseSeconds),
        );
        if ($claim === null) {
            return null;
        }
        try {
            $this->assertExecutable($claim);
            $handler = $this->handlers->require($claim->handlerKey);
            $authorization = new JobAuthorizationFence($this->authorization, $claim, $this->negativeSigningKey);
            if ($claim->profile === NegativeFactJobEnvelope::PROFILE) {
                if (!$handler instanceof \PeanutAdmin\Modules\Task\Contract\NegativeFactTaskHandler) {
                    throw TaskJobException::denied();
                }
                $envelope = $authorization->negativeEnvelope();
                ($this->executeAuthorizedJob)($envelope, $claim, function () use ($envelope, $claim, $handler, $authorization): void {
                    $authorization->assertNegativeAuthorized($handler);
                    $execution = new JobExecution(
                        $claim->jobKey,
                        $claim->tenantId,
                        $claim->attemptNumber,
                        $claim->payload,
                        fn() => Db::transaction(function () use ($claim): void {
                            $this->renew($claim);
                            $this->assertExecutable($claim);
                        }),
                        fn() => $this->assertExecutable($claim),
                        fn() => $authorization->assertNegativeAuthorized($handler),
                        fn(string $reference) => $this->repository->negativeState($claim, $reference),
                        fn(string $reference, array $state) => Db::transaction(function () use ($claim, $reference, $state): void {
                            $this->repository->recordNegativeState($claim, $reference, $state);
                        }),
                    );
                    $handler->handleNegativeFact($envelope, $execution);
                    $execution->assertLeaseOwned();
                });
            } elseif ($claim->profile === 'member') {
                $authorization->handle(
                    function ($context, $envelope) use ($claim, $handler, $authorization): void {
                        $execution = new JobExecution(
                            $claim->jobKey,
                            $claim->tenantId,
                            $claim->attemptNumber,
                            $claim->payload,
                            function () use ($claim): void {
                                Db::transaction(function () use ($claim): void {
                                    $this->renew($claim);
                                    $this->assertExecutable($claim);
                                });
                            },
                            fn() => $this->assertExecutable($claim),
                            fn() => $authorization->assertAuthorized(),
                        );
                        ($this->executeAuthorizedJob)($context, $claim, function () use ($handler, $context, $execution): void {
                            $handler->handle($context, $execution);
                            $execution->assertLeaseOwned();
                        });
                    },
                );
            } else {
                throw TaskJobException::denied();
            }
        } catch (LeaseLostException) {
            return 'lease_lost';
        } catch (RetryableTaskException $exception) {
            return $this->failClaim($claim, $exception->safeCode, true, $this->backoff($claim->attemptNumber));
        } catch (Throwable $exception) {
            $code = $exception instanceof TaskJobException ? $exception->problemCode : 'TASK_HANDLER_FAILED';
            return $this->failClaim($claim, $code, false, 0);
        }
        try {
            Db::transaction(function () use ($claim): void {
                $this->repository->succeed($claim);
            });
        } catch (TaskJobException $exception) {
            if ($exception->problemCode === 'TASK_STATE_CONFLICT') {
                return 'lease_lost';
            }
            throw $exception;
        }
        return 'succeeded';
    }

    private function failClaim(JobClaim $claim, string $code, bool $retryable, int $backoff): string
    {
        try {
            return Db::transaction(
                fn(): string => $this->repository->fail($claim, $code, $retryable, $backoff),
            );
        } catch (TaskJobException $exception) {
            if ($exception->problemCode === 'TASK_STATE_CONFLICT') {
                return 'lease_lost';
            }
            throw $exception;
        }
    }

    public function renew(JobClaim $claim): void
    {
        if ($claim->tenantId !== $this->tenantId) {
            throw TaskJobException::denied();
        }
        $this->repository->renew($claim, $this->leaseSeconds);
    }

    private function assertExecutable(JobClaim $claim): void
    {
        $this->repository->assertExecutable($claim);
    }

    private function backoff(int $attempt): int
    {
        return min(300, 5 * (2 ** max(0, $attempt - 1)));
    }

}
