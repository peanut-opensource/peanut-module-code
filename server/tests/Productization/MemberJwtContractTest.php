<?php

declare(strict_types=1);

use app\api\middleware\CheckTokenMiddleware;
use PeanutAdmin\Modules\Member\Service\UserTokenService;
use PeanutAdmin\Modules\Member\Contract\MemberSessions;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionGrant;
use Firebase\JWT\Key;
use Firebase\JWT\JWT;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// 本组验证JWT和Bearer协议；真实持久化由独立数据库回归负责。
$sessions = new class implements MemberSessions {
    private array $grants = [];
    public function issue(int $memberId, int $issuedAt, int $expiresAt): MemberSessionGrant
    {
        $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return $this->grants[$key] = new MemberSessionGrant($key, 31, $memberId, 1, $issuedAt, $expiresAt);
    }
    public function verify(string $key, int $tenant, int $member, int $revision, int $issued, int $expires, int $now): void
    {
        $grant = $this->grants[$key] ?? null;
        if ($grant === null || [$tenant,$member,$revision,$issued,$expires] !== [$grant->tenantId,$grant->memberId,$grant->sessionRevision,$grant->issuedAt,$grant->expiresAt]
            || $issued > $now || $expires <= $now) {
            throw new UnexpectedValueException('SESSION_INVALID');
        }
    }
    public function revokeCurrent(string $key, int $tenant, int $member, int $revision, int $issued, int $expires, int $now): void
    {
        $this->verify($key, $tenant, $member, $revision, $issued, $expires, $now);
        unset($this->grants[$key]);
    }
    public function revokeAll(int $tenant, int $member, string $reason, int $now): void
    {
        foreach ($this->grants as $key => $grant) {
            if ($grant->tenantId === $tenant && $grant->memberId === $member) {
                unset($this->grants[$key]);
            }
        }
    }
};

function jwtExpect(bool $condition, string $message): void
{
    $GLOBALS['jwt_assertions'] = ($GLOBALS['jwt_assertions'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function jwtExpectThrows(callable $operation, string $message): void
{
    $GLOBALS['jwt_assertions'] = ($GLOBALS['jwt_assertions'] ?? 0) + 1;
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

function jwtExpectInvalidToken(callable $operation, string $message): void
{
    $GLOBALS['jwt_assertions'] = ($GLOBALS['jwt_assertions'] ?? 0) + 1;
    try {
        $operation();
    } catch (UnexpectedValueException) {
        return;
    }
    throw new RuntimeException($message);
}

/** @param array<string,mixed> $overrides */
function jwtToken(string $secret, array $overrides = [], string $algorithm = 'HS256'): string
{
    $payload = array_merge($GLOBALS['jwt_valid_claims'], $overrides);
    foreach ($payload as $claim => $value) {
        if ($value === null) {
            unset($payload[$claim]);
        }
    }
    return JWT::encode($payload, $secret, $algorithm);
}

jwtExpectThrows(
    static fn(): UserTokenService => new UserTokenService('', 7200, $sessions),
    'member JWT signing accepted a missing secret',
);
jwtExpectThrows(
    static fn(): UserTokenService => new UserTokenService(str_repeat('s', 31), 7200, $sessions),
    'member JWT signing accepted a secret shorter than 32 bytes',
);

$secret = str_repeat('s', 64);
jwtExpectThrows(
    static fn(): UserTokenService => new UserTokenService($secret, 0, $sessions),
    'member JWT signing accepted an invalid expiry',
);
$tokens = new UserTokenService($secret, 7200, $sessions);
$issued = $tokens->createToken(17);
$GLOBALS['jwt_valid_claims'] = (array) JWT::decode($issued, new Key($secret, 'HS256'));
jwtExpect($tokens->parseToken($issued) === 17, 'member JWT round trip failed');
jwtExpectThrows(
    static fn(): string => $tokens->createToken(0),
    'member JWT signing accepted an invalid member id',
);

$invalidClaims = [
    'missing sid' => ['sid' => null],
    'missing tenant' => ['tenant_id' => null],
    'foreign tenant' => ['tenant_id' => 32],
    'missing revision' => ['rev' => null],
    'wrong revision' => ['rev' => 2],
    'missing iss' => ['iss' => null],
    'wrong iss type' => ['iss' => 1],
    'wrong aud' => ['aud' => 'another-api'],
    'wrong aud type' => ['aud' => ['peanut-admin-member-api']],
    'wrong sub' => ['sub' => 'member:18'],
    'wrong sub type' => ['sub' => 17],
    'missing iat' => ['iat' => null],
    'wrong iat type' => ['iat' => (string) time()],
    'missing nbf' => ['nbf' => null],
    'wrong nbf type' => ['nbf' => time() + 0.5],
    'missing exp' => ['exp' => null],
    'wrong exp type' => ['exp' => (string) (time() + 7200)],
    'member id string' => ['member_id' => '17'],
    'member id mismatch' => ['member_id' => 18],
    'iat after nbf' => ['iat' => time() + 1, 'nbf' => time()],
    'nbf at exp' => ['nbf' => time(), 'exp' => time()],
    'expired' => ['iat' => time() - 7201, 'nbf' => time() - 7201, 'exp' => time() - 1],
    'future' => ['iat' => time() + 60, 'nbf' => time() + 60, 'exp' => time() + 7260],
];
foreach ($invalidClaims as $label => $claims) {
    jwtExpectInvalidToken(
        static fn(): int => $tokens->parseToken(jwtToken($secret, $claims)),
        'member JWT accepted invalid claims: ' . $label,
    );
}
jwtExpectInvalidToken(
    static fn(): int => $tokens->parseToken(jwtToken($secret, [], 'HS512')),
    'member JWT accepted a non-HS256 algorithm',
);

$tokens->revokeToken($issued);
jwtExpectInvalidToken(static fn(): int => $tokens->parseToken($issued), 'logged-out token remained valid');

$bearerParser = new ReflectionMethod(CheckTokenMiddleware::class, 'bearerToken');
$compactToken = 'abc.DEF_123.xyz-789';
foreach (['Bearer ', 'bearer ', 'BEARER  '] as $prefix) {
    jwtExpect(
        $bearerParser->invoke(null, $prefix . $compactToken) === $compactToken,
        'member middleware rejected a valid case-insensitive Bearer scheme',
    );
}
foreach ([
    '',
    $compactToken,
    'Basic ' . $compactToken,
    ' Bearer ' . $compactToken,
    'Bearer\t' . $compactToken,
    'Bearer ' . $compactToken . ' ',
    'Bearer abc',
    'Bearer abc.def.ghi.extra',
    'Bearer abc.def.ghi,another',
] as $authorization) {
    jwtExpect(
        $bearerParser->invoke(null, $authorization) === '',
        'member middleware accepted a malformed Authorization value: ' . $authorization,
    );
}

$rootEnvExample = (string) file_get_contents(dirname(__DIR__, 3) . '/.env.example');
$envExample = (string) file_get_contents(dirname(__DIR__, 2) . '/.env.example');
jwtExpect(
    !str_contains($rootEnvExample, 'JWT_SECRET=')
        && preg_match('/^JWT_SECRET=$/m', $envExample) === 1,
    'backend environment samples expose, duplicate, or supply a JWT secret',
);
$jwtConfig = (string) file_get_contents(dirname(__DIR__, 2) . '/config/jwt.php');
jwtExpect(
    preg_match("/'secret'\\s*=>\\s*env\\('JWT_SECRET'\\),/", $jwtConfig) === 1,
    'JWT configuration supplies a default secret',
);

echo "Member JWT contract passed: " . $GLOBALS['jwt_assertions'] . " assertions\n";
