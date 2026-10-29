<?php

declare(strict_types=1);

namespace app\common\enum\notice;

/**
 * 固定验证码通知场景。
 */
class NoticeSceneEnum
{
    public const LOGIN_CODE = 'login_code';
    public const BIND_MOBILE = 'bind_mobile';
    public const CHANGE_MOBILE = 'change_mobile';
    public const RESET_PASSWORD = 'reset_password';

    public const CODE_LENGTH = 4;

    /**
     * @return array<string,array{name:string,variables:string[]}>
     */
    public static function definitions(): array
    {
        return [
            self::LOGIN_CODE => [
                'name' => '登录验证码',
                'variables' => ['code'],
            ],
            self::BIND_MOBILE => [
                'name' => '绑定手机验证码',
                'variables' => ['code'],
            ],
            self::CHANGE_MOBILE => [
                'name' => '变更手机验证码',
                'variables' => ['code'],
            ],
            self::RESET_PASSWORD => [
                'name' => '重置密码验证码',
                'variables' => ['code'],
            ],
        ];
    }

    public static function isValid(string $scene): bool
    {
        return isset(self::definitions()[$scene]);
    }

    public static function getName(string $scene): string
    {
        return self::definitions()[$scene]['name'] ?? '';
    }

    /** @return string[] */
    public static function getVariables(string $scene): array
    {
        return self::definitions()[$scene]['variables'] ?? [];
    }
}
