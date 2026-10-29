<?php

declare(strict_types=1);

namespace app\common\validate;

use app\common\exception\BusinessException;
use DateTimeImmutable;
use think\Validate;

/** 会员本人资料的单字段写入合同，供 HTTP 用例和会员模块公开命令共同复用。 */
final class MemberProfileSelfFieldValidate extends Validate
{
    private const FIELDS = ['nickname', 'avatar', 'sex', 'birthday', 'email'];

    protected $rule = [
        'field' => 'require|in:nickname,avatar,sex,birthday,email',
        // require 前缀使 ThinkPHP 对空值也调用自定义规则，从而明确每个字段的清空合同。
        'value' => 'requireSelfValue',
    ];

    protected $message = [
        'field.require' => '不支持修改该字段',
        'field.in' => '不支持修改该字段',
    ];

    /**
     * 校验并规范化一项会员本人资料。
     *
     * 昵称、头像、邮箱可用空字符串清空；生日的空字符串或 null 统一写为 NULL；
     * 性别仅接受现有的 0（未知）、1（男）、2（女）枚举。头像原始输入只校验类型，
     * 文件服务规范化为实际存储引用后再按数据库的 255 字符限制校验。
     */
    public static function normalize(mixed $field, mixed $value, bool $avatarStored = true): mixed
    {
        $validator = new self();
        if (!$validator->check(['field' => $field, 'value' => $value, 'avatar_stored' => $avatarStored])) {
            if (!self::isSupportedField($field)) {
                throw BusinessException::invalid('MEMBER_PROFILE_FIELD_UNSUPPORTED', '不支持修改该字段');
            }
            throw BusinessException::invalid('MEMBER_PROFILE_VALUE_INVALID', (string) $validator->getError());
        }

        return match ($field) {
            'sex' => (int) $value,
            'birthday' => $value === '' || $value === null ? null : $value,
            default => $value,
        };
    }

    protected function requireSelfValue(mixed $value, mixed $rule, array $data): bool|string
    {
        $field = $data['field'] ?? null;
        if (!self::isSupportedField($field)) {
            return true;
        }

        return match ($field) {
            'nickname' => $this->isStringWithin($value, 50) ?: '昵称必须是最多 50 个字符的字符串',
            'avatar' => $this->isValidAvatar($value, (bool) ($data['avatar_stored'] ?? true)) ?: '头像必须是最多 255 个字符的字符串',
            'email' => $this->isValidEmail($value) ?: '邮箱格式或长度不正确',
            'sex' => $this->isSex($value) ?: '性别值无效',
            'birthday' => $this->isBirthday($value) ?: '生日必须是 YYYY-MM-DD 格式的有效日期',
        };
    }

    private static function isSupportedField(mixed $field): bool
    {
        return is_string($field) && in_array($field, self::FIELDS, true);
    }

    private function isStringWithin(mixed $value, int $maxLength): bool
    {
        return is_string($value) && mb_strlen($value) <= $maxLength;
    }

    private function isValidAvatar(mixed $value, bool $stored): bool
    {
        return is_string($value) && (!$stored || mb_strlen($value) <= 255);
    }

    private function isValidEmail(mixed $value): bool
    {
        return is_string($value)
            && mb_strlen($value) <= 100
            && ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false);
    }

    private function isSex(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && in_array((string) $value, ['0', '1', '2'], true);
    }

    private function isBirthday(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_string($value) || preg_match('/^(?:[1-9][0-9]{3})-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])$/D', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
