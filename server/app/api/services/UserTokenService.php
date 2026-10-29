<?php

declare(strict_types=1);

namespace app\api\services;

use PeanutAdmin\Modules\Member\Contract\MemberSessions;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * 用户端访问凭证服务。
 *
 * JWT 只承载签名声明；每次解析还必须命中会员模块的持久会话及当前安全修订。
 * 旧版无 sid/tenant_id/rev 声明的 JWT 会被明确拒绝，避免把“验签成功”误当成会话有效。
 */
class UserTokenService
{
    private const ALGORITHM = 'HS256';
    private const ISSUER = 'peanut-admin';
    private const AUDIENCE = 'peanut-admin-member-api';
    private const SUBJECT_PREFIX = 'member:';

    public function __construct(
        private readonly string $secret,
        private readonly int $expire,
        private readonly MemberSessions $sessions,
    ) {
        if (strlen($this->secret) < 32) {
            throw new \RuntimeException('JWT_SECRET 必须至少为 32 字节');
        }
        if ($this->expire < 1) {
            throw new \RuntimeException('JWT 有效期配置无效');
        }
    }

    public function createToken(int $memberId): string
    {
        if ($memberId < 1) {
            throw new \InvalidArgumentException('会员身份无效');
        }
        $issuedAt = time();
        if ($this->expire > PHP_INT_MAX - $issuedAt) {
            throw new \RuntimeException('JWT 有效期配置无效');
        }
        $expiresAt = $issuedAt + $this->expire;
        $session = $this->sessions->issue($memberId, $issuedAt, $expiresAt);
        $payload = [
            'iss'       => self::ISSUER,
            'aud'       => self::AUDIENCE,
            'sub'       => self::SUBJECT_PREFIX . $memberId,
            'iat'       => $issuedAt,
            'nbf'       => $issuedAt,
            'exp'       => $expiresAt,
            'member_id' => $session->memberId,
            'tenant_id' => $session->tenantId,
            'sid'       => $session->sessionKey,
            'rev'       => $session->sessionRevision,
        ];
        return JWT::encode($payload, $this->secret, self::ALGORITHM);
    }

    public function parseToken(string $token): int
    {
        return $this->parseSessionToken($token)['member_id'];
    }

    /** @return array{member_id:int,tenant_id:int,session_key:string,session_revision:int,issued_at:int,expires_at:int} */
    public function parseSessionToken(string $token): array
    {
        try {
            if ($token === '') {
                throw new \UnexpectedValueException('MEMBER_TOKEN_INVALID');
            }
            $decoded = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
            $memberId = is_int($decoded->member_id ?? null) && $decoded->member_id > 0
                ? $decoded->member_id
                : null;
            $tenantId = is_int($decoded->tenant_id ?? null) && $decoded->tenant_id > 0
                ? $decoded->tenant_id
                : null;
            $sessionRevision = is_int($decoded->rev ?? null) && $decoded->rev > 0
                ? $decoded->rev
                : null;
            $sessionKey = is_string($decoded->sid ?? null) ? $decoded->sid : null;
            $issuedAt = self::timestamp($decoded->iat ?? null);
            $notBefore = self::timestamp($decoded->nbf ?? null);
            $expiresAt = self::timestamp($decoded->exp ?? null);
            if ($memberId === null
                || $tenantId === null
                || $sessionRevision === null
                || $sessionKey === null
                || $issuedAt === null
                || $notBefore === null
                || $expiresAt === null
                || $issuedAt > $notBefore
                || $notBefore >= $expiresAt
                || !is_string($decoded->iss ?? null)
                || !hash_equals(self::ISSUER, $decoded->iss)
                || !is_string($decoded->aud ?? null)
                || !hash_equals(self::AUDIENCE, $decoded->aud)
                || !is_string($decoded->sub ?? null)
                || !hash_equals(self::SUBJECT_PREFIX . $memberId, $decoded->sub)) {
                throw new \UnexpectedValueException('MEMBER_TOKEN_INVALID');
            }
            $this->sessions->verify(
                $sessionKey,
                $tenantId,
                $memberId,
                $sessionRevision,
                $issuedAt,
                $expiresAt,
                time(),
            );
            return [
                'member_id' => $memberId,
                'tenant_id' => $tenantId,
                'session_key' => $sessionKey,
                'session_revision' => $sessionRevision,
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
            ];
        } catch (\UnexpectedValueException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \UnexpectedValueException('MEMBER_TOKEN_INVALID', 0, $exception);
        }
    }

    public function revokeToken(string $token): void
    {
        $session = $this->parseSessionToken($token);
        try {
            $this->sessions->revokeCurrent(
                $session['session_key'],
                $session['tenant_id'],
                $session['member_id'],
                $session['session_revision'],
                $session['issued_at'],
                $session['expires_at'],
                time(),
            );
        } catch (\UnexpectedValueException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \UnexpectedValueException('MEMBER_TOKEN_INVALID', 0, $exception);
        }
    }

    private static function timestamp(mixed $value): ?int
    {
        if (!is_int($value) || $value < 1) {
            return null;
        }
        return $value;
    }
}
