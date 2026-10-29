<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Service;

use PeanutAdmin\Modules\Notification\Model\NoticeLog;
use PeanutAdmin\Modules\Notification\Model\NoticeScene;
use PeanutAdmin\Modules\Notification\Contract\DeliveryResult;
use PeanutAdmin\Modules\Notification\Contract\VerificationResult;
use app\common\enum\notice\NoticeSceneEnum;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use app\common\context\notice\NoticeTenantContext;
use app\common\value\notice\sms\SmsDriverResult;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use think\facade\Db;
use PeanutAdmin\Modules\Notification\Delivery\Application\VerificationCodeSecret;
use PeanutAdmin\Modules\Notification\Contract\NoticeSmsSender;

/**
 * 手机验证码发送与核验服务。
 */
class VerificationCodeService
{
    public const DEFAULT_MAX_FAILED_ATTEMPTS = 5;
    private const SEND_INTERVAL = 60;
    private const VALID_PERIOD = 300;

    public function __construct(
        private readonly NoticeSmsSender $sender,
        private readonly CurrentExecutionContext $executionContext,
        private readonly bool $developmentMode,
        private readonly int $maxFailedAttempts = self::DEFAULT_MAX_FAILED_ATTEMPTS,
    ) {}

    public function send(
        TenantContext|TenantSystemContext $context,
        string $sceneCode,
        string $mobile,
    ): DeliveryResult {
        $tenantId = NoticeTenantContext::verificationTenantId(
            $this->executionContext,
            $context,
            'notice.verification.send',
        );
        if (!$this->validMobile($mobile)) {
            return new DeliveryResult(false, '', '手机号格式不正确');
        }

        if (!NoticeSceneEnum::isValid($sceneCode)) {
            return new DeliveryResult(false, '', '验证码场景不存在');
        }

        $scene = NoticeScene::where([])
            ->where('code', $sceneCode)->findOrEmpty();
        if ($scene->isEmpty() || (int) $scene->sms_status !== $scene::STATUS_ENABLED) {
            return new DeliveryResult(false, '', '验证码场景未启用');
        }

        $templateId = trim((string) $scene->sms_template_id);
        $templateContent = trim((string) $scene->sms_content);
        if ($templateId === '' || $templateContent === '') {
            return new DeliveryResult(false, '', '短信模板未配置');
        }

        $code = $this->developmentMode
            ? '1234'
            : (string) random_int(
                10 ** (NoticeSceneEnum::CODE_LENGTH - 1),
                (10 ** NoticeSceneEnum::CODE_LENGTH) - 1,
            );
        $content = $this->render($templateContent, ['code' => '****']);
        $reservation = $this->reserve(
            $context,
            $tenantId,
            (int) $scene->id,
            (string) $scene->name,
            $sceneCode,
            $mobile,
            $content,
            $templateId,
            $code,
        );
        if (!$reservation['execute']) {
            return $this->reservedDelivery($reservation['log'], $reservation['reason']);
        }

        $reservationKey = (string) $reservation['log']->reservation_key;
        $attemptStarted = false;
        try {
            $result = $this->sender->send(
                $context,
                $mobile,
                $templateId,
                ['code' => $code],
                function (string $provider) use ($context, $reservationKey, &$attemptStarted): void {
                    $this->markProviderAttemptStarted($context, $reservationKey, $provider);
                    $attemptStarted = true;
                },
            );
        } catch (\Throwable) {
            $outcome = $attemptStarted ? SmsDriverResult::OUTCOME_UNKNOWN : SmsDriverResult::OUTCOME_FAILED;
            $error = $attemptStarted ? '短信服务商调用结果未知，请稍后重试' : '短信发送前置处理失败';
            $this->finalizeReservation($context, $reservationKey, $outcome, '', $error, $templateId, []);
            return new DeliveryResult(false, '', $error);
        }

        $outcome = $this->normalizeOutcome($result);
        $provider = trim((string) ($result['provider'] ?? ''));
        $error = trim((string) ($result['error'] ?? ''));
        $receipt = is_array($result['result'] ?? null) ? $result['result'] : [];
        if ($outcome === SmsDriverResult::OUTCOME_UNKNOWN && $error === '') {
            $error = '短信服务商调用结果未知，请稍后重试';
        }
        try {
            $this->finalizeReservation(
                $context,
                $reservationKey,
                $outcome,
                $provider,
                $error,
                $templateId,
                $receipt,
            );
        } catch (\Throwable) {
            return new DeliveryResult(false, $provider, '短信服务商调用结果未知，请稍后重试');
        }

        return new DeliveryResult(
            $outcome === SmsDriverResult::OUTCOME_SUCCEEDED,
            $provider,
            $error,
            $receipt,
        );
    }

    public function verify(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $sceneCode,
        string $mobile,
        string $code,
    ): VerificationResult {
        NoticeTenantContext::verificationTenantId($this->executionContext, $context, 'notice.verification.verify');
        if (!$this->validMobile($mobile)) {
            return new VerificationResult(false, '手机号格式不正确');
        }

        if (!NoticeSceneEnum::isValid($sceneCode)) {
            return new VerificationResult(false, '验证码场景不存在');
        }

        $scene = NoticeScene::where([])
            ->where('code', $sceneCode)->findOrEmpty();
        if ($scene->isEmpty()) {
            return new VerificationResult(false, '验证码场景不存在');
        }

        return Db::transaction(function () use ($scene, $mobile, $code): VerificationResult {
            $log = NoticeLog::where([])
                ->where('scene_id', (int) $scene->id)
                ->where('channel', NoticeLog::CHANNEL_SMS)
                ->where('receiver', $mobile)
                ->where('status', NoticeLog::STATUS_SUCCESS)
                ->order('send_time', 'desc')
                ->order('id', 'desc')
                ->lock(true)
                ->findOrEmpty();

            if ($log->isEmpty() || (int) $log->is_verified === NoticeLog::VERIFIED_YES) {
                return new VerificationResult(false, '验证码不存在或已使用');
            }

            // 已耗尽的验证码不能再被正确核验；必须重新发送生成新记录。
            if ((int) $log->check_count >= $this->maxFailedAttempts()) {
                return new VerificationResult(false, '验证码验证次数已达上限');
            }
            if ((int) $log->send_time < time() - self::VALID_PERIOD) {
                return new VerificationResult(false, '验证码已过期');
            }

            if (!VerificationCodeSecret::matches($code, (string) $log->verify_code_hash)) {
                // 先提交失败计数，再由调用方抛出业务异常；调用方不得用可回滚外层事务包住核验。
                $log->check_count = (int) $log->check_count + 1;
                $log->save();
                return new VerificationResult(false, '验证码不正确');
            }

            $log->is_verified = NoticeLog::VERIFIED_YES;
            $log->verified_time = time();
            $log->save();
            return new VerificationResult(true);
        });
    }

    private function maxFailedAttempts(): int
    {
        return max(1, $this->maxFailedAttempts);
    }

    /**
     * Commits one durable send reservation before any Provider side effect.
     * A database unique key serializes all scenes for the same Tenant/mobile;
     * only an expired or explicitly failed reservation can release the slot.
     *
     * @return array{execute:bool,reason:string,log:\PeanutAdmin\Modules\Notification\Model\NoticeLog}
     */
    private function reserve(
        TenantContext|TenantSystemContext $context,
        int $tenantId,
        int $sceneId,
        string $sceneName,
        string $sceneCode,
        string $mobile,
        string $content,
        string $templateId,
        string $code,
    ): array {
        $idempotencyHash = hash('sha256', implode("\0", [
            'notice.verification.send',
            (string) $tenantId,
            $this->executionContext->requestId(),
        ]));
        $requestDigest = hash('sha256', implode("\0", [$sceneCode, $mobile]));
        $receiverHash = hash('sha256', $mobile);
        $create = function () use (
            $sceneId,
            $sceneName,
            $mobile,
            $content,
            $templateId,
            $code,
            $idempotencyHash,
            $requestDigest,
            $receiverHash,
        ): array {
            $existing = NoticeLog::where([])
                ->where('idempotency_key_hash', $idempotencyHash)
                ->lock(true)
                ->findOrEmpty();
            if (!$existing->isEmpty()) {
                $reason = hash_equals((string) $existing->request_digest, $requestDigest)
                    ? 'replay'
                    : 'conflict';
                return ['execute' => false, 'reason' => $reason, 'log' => $existing];
            }

            $active = NoticeLog::where([])
                ->where('channel', NoticeLog::CHANNEL_SMS)
                ->where('receiver_hash', $receiverHash)
                ->where('reservation_active', 1)
                ->lock(true)
                ->findOrEmpty();
            $reservationTime = time();
            if (!$active->isEmpty() && (int) $active->reservation_until > $reservationTime) {
                return ['execute' => false, 'reason' => 'active', 'log' => $active];
            }
            if (!$active->isEmpty()) {
                $active->reservation_active = null;
                $active->save();
            }

            $log = NoticeLog::create([
                'template_id' => 0,
                'scene_id' => $sceneId,
                'channel' => NoticeLog::CHANNEL_SMS,
                'receiver' => $mobile,
                'title' => $sceneName,
                'content' => $content,
                'status' => NoticeLog::STATUS_PENDING,
                'error' => '',
                'extra' => $this->encodeExtra($templateId, []),
                'send_time' => $reservationTime,
                'verify_code_hash' => VerificationCodeSecret::hash($code),
                'is_verified' => NoticeLog::VERIFIED_NO,
                'check_count' => 0,
                'verified_time' => 0,
                'provider' => '',
                'reservation_key' => 'smsr_' . bin2hex(random_bytes(16)),
                'idempotency_key_hash' => $idempotencyHash,
                'request_digest' => $requestDigest,
                'receiver_hash' => $receiverHash,
                'reservation_until' => $reservationTime + self::SEND_INTERVAL,
                'reservation_active' => 1,
            ]);
            return ['execute' => true, 'reason' => 'owner', 'log' => $log];
        };

        try {
            return Db::transaction($create);
        } catch (\Throwable $exception) {
            if (!$this->isUniqueConflict($exception)) {
                throw $exception;
            }
            return Db::transaction(function () use (
                $idempotencyHash,
                $requestDigest,
                $receiverHash,
            ): array {
                $existing = NoticeLog::where([])
                    ->where('idempotency_key_hash', $idempotencyHash)
                    ->lock(true)
                    ->findOrEmpty();
                if (!$existing->isEmpty()) {
                    $reason = hash_equals((string) $existing->request_digest, $requestDigest)
                        ? 'replay'
                        : 'conflict';
                    return ['execute' => false, 'reason' => $reason, 'log' => $existing];
                }
                $active = NoticeLog::where([])
                    ->where('channel', NoticeLog::CHANNEL_SMS)
                    ->where('receiver_hash', $receiverHash)
                    ->where('reservation_active', 1)
                    ->lock(true)
                    ->findOrEmpty();
                if ($active->isEmpty()) {
                    throw new \runtimeException('SMS_RESERVATION_CONFLICT_UNRESOLVED');
                }
                return ['execute' => false, 'reason' => 'active', 'log' => $active];
            });
        }
    }

    /** Marks the side-effect boundary before control crosses into the Provider transport. */
    private function markProviderAttemptStarted(
        TenantContext|TenantSystemContext $context,
        string $reservationKey,
        string $provider,
    ): void {
        Db::transaction(function () use ($context, $reservationKey, $provider): void {
            $log = $this->lockedReservation($context, $reservationKey);
            if ((int) $log->status !== NoticeLog::STATUS_PENDING) {
                throw new \LogicException('SMS_RESERVATION_NOT_PENDING');
            }
            $log->provider = trim($provider);
            $log->status = NoticeLog::STATUS_UNKNOWN;
            $log->error = '短信服务商调用结果待确认';
            $log->save();
        });
    }

    /** Finalizes one reservation; only explicit failure releases the active window early. */
    private function finalizeReservation(
        TenantContext|TenantSystemContext $context,
        string $reservationKey,
        string $outcome,
        string $provider,
        string $error,
        string $templateId,
        array $receipt,
    ): void {
        Db::transaction(function () use (
            $context,
            $reservationKey,
            $outcome,
            $provider,
            $error,
            $templateId,
            $receipt,
        ): void {
            $log = $this->lockedReservation($context, $reservationKey);
            $currentStatus = (int) $log->status;
            if (!in_array($currentStatus, [
                NoticeLog::STATUS_PENDING,
                NoticeLog::STATUS_UNKNOWN,
            ], true)) {
                throw new \LogicException('SMS_RESERVATION_ALREADY_FINALIZED');
            }
            if ($outcome === SmsDriverResult::OUTCOME_SUCCEEDED
                && $currentStatus !== NoticeLog::STATUS_UNKNOWN) {
                throw new \LogicException('SMS_PROVIDER_ATTEMPT_NOT_RECORDED');
            }

            $log->provider = trim($provider) !== '' ? trim($provider) : (string) $log->provider;
            $log->status = match ($outcome) {
                SmsDriverResult::OUTCOME_SUCCEEDED => NoticeLog::STATUS_SUCCESS,
                SmsDriverResult::OUTCOME_FAILED => NoticeLog::STATUS_FAIL,
                default => NoticeLog::STATUS_UNKNOWN,
            };
            $log->error = $error;
            $log->extra = $this->encodeExtra($templateId, $receipt);
            $log->reservation_active = $outcome === SmsDriverResult::OUTCOME_FAILED ? null : 1;
            $log->save();
        });
    }

    private function lockedReservation(
        TenantContext|TenantSystemContext $context,
        string $reservationKey,
    ): \PeanutAdmin\Modules\Notification\Model\NoticeLog {
        $log = NoticeLog::where([])
            ->where('reservation_key', $reservationKey)
            ->lock(true)
            ->findOrEmpty();
        if ($log->isEmpty()) {
            throw new \LogicException('SMS_RESERVATION_NOT_FOUND');
        }
        return $log;
    }

    private function reservedDelivery(
        \PeanutAdmin\Modules\Notification\Model\NoticeLog $log,
        string $reason,
    ): DeliveryResult {
        if ($reason === 'conflict') {
            return new DeliveryResult(false, '', '短信幂等请求内容冲突');
        }
        if ($reason === 'active') {
            $error = (int) $log->status === NoticeLog::STATUS_UNKNOWN
                ? '上次短信发送结果未知，请稍后重试'
                : '同一手机号1分钟只能发送1条短信';
            return new DeliveryResult(false, (string) $log->provider, $error);
        }

        $status = (int) $log->status;
        $receipt = $this->decodeReceipt((string) $log->extra);
        return match ($status) {
            NoticeLog::STATUS_SUCCESS => new DeliveryResult(true, (string) $log->provider, '', $receipt),
            NoticeLog::STATUS_FAIL => new DeliveryResult(false, (string) $log->provider, (string) $log->error, $receipt),
            NoticeLog::STATUS_UNKNOWN => new DeliveryResult(false, (string) $log->provider, '短信服务商调用结果未知，请稍后重试', $receipt),
            default => new DeliveryResult(false, (string) $log->provider, '验证码发送正在处理中'),
        };
    }

    /** @param array<string,mixed> $result */
    private function normalizeOutcome(array $result): string
    {
        $outcome = (string) ($result['outcome'] ?? '');
        if (!in_array($outcome, [
            SmsDriverResult::OUTCOME_SUCCEEDED,
            SmsDriverResult::OUTCOME_FAILED,
            SmsDriverResult::OUTCOME_UNKNOWN,
        ], true)) {
            return SmsDriverResult::OUTCOME_UNKNOWN;
        }
        if (($result['success'] ?? null) !== ($outcome === SmsDriverResult::OUTCOME_SUCCEEDED)) {
            return SmsDriverResult::OUTCOME_UNKNOWN;
        }
        return $outcome;
    }

    /** @return array<string,mixed> */
    private function decodeReceipt(string $extra): array
    {
        $decoded = json_decode($extra, true);
        return is_array($decoded['provider_result'] ?? null) ? $decoded['provider_result'] : [];
    }

    private function isUniqueConflict(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ((string) $current->getCode() === '23000'
                || str_contains(strtolower($current->getMessage()), 'duplicate entry')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,string> $variables */
    private function render(string $content, array $variables): string
    {
        foreach ($variables as $name => $value) {
            $content = str_replace(['${' . $name . '}', '{' . $name . '}'], $value, $content);
        }
        return $content;
    }

    /** @param array<string,mixed> $result */
    private function encodeExtra(string $templateId, array $result): string
    {
        return (string) json_encode([
            'provider_template_id' => $templateId,
            'provider_result' => $result,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function validMobile(string $mobile): bool
    {
        return preg_match('/^1[3-9]\d{9}$/', $mobile) === 1;
    }
}
