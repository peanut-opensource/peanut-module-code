<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Validation;

use app\common\exception\BusinessException;

final class AiInput
{
    public static function invalid(string $message): never
    {
        throw BusinessException::invalid('AI_INPUT_INVALID', $message);
    }
    public static function only(array $input, array $keys): void
    {
        if (array_diff(array_keys($input), $keys) !== []) {
            self::invalid('请求包含未声明字段');
        }
    }
    /** One real CAS identity: native job revision, or business preparation version for cancel before enqueue. */
    public static function taskAction(array $p): array
    {
        self::only($p, ['id','action','job_revision','version','request_id']);
        $action = self::choice($p['action'] ?? null, ['cancel','retry','observeUnknown','poll']);
        $job = array_key_exists('job_revision', $p) ? self::integer($p['job_revision']) : null;
        $version = array_key_exists('version', $p) ? self::integer($p['version']) : null;
        if ($job !== null && $version !== null || $job === null && ($action !== 'cancel' || $version === null)) {
            self::invalid('操作须提供原生任务修订；仅未排队取消准备使用业务版本');
        }
        return ['id' => self::integer($p['id'] ?? null),'action' => $action,'job_revision' => $job,
            'version' => $version,'request_id' => self::requestId($p['request_id'] ?? null)];
    }

    public static function integer(mixed $v, int $min = 1, int $max = PHP_INT_MAX): int
    {
        if (!(is_int($v) || is_string($v) && preg_match('/^[0-9]+$/D', $v)) || filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < $min || (int) $v > $max) {
            self::invalid('整数参数无效');
        }return (int) $v;
    }
    public static function text(mixed $v, int $max = 2000, bool $required = true): string
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || mb_strlen($v) > $max || $required && trim($v) === '') {
            self::invalid('文本必填或超出长度');
        }return trim($v);
    }
    public static function choice(mixed $v, array $choices): string
    {
        if (!is_string($v) || !in_array($v, $choices, true)) {
            self::invalid('选项无效');
        }return $v;
    }
    public static function consumerModule(mixed $value): string
    {
        $key = self::text($value, 96);
        if ($key !== 'general' && !preg_match('/^[a-z][a-z0-9-]*(?:[.][a-z][a-z0-9-]*)+$/D', $key)) {
            self::invalid('使用模块须填写有效模块标识或 general');
        }
        return $key;
    }
    public static function boolean(mixed $v): bool
    {
        if (!is_bool($v)) {
            self::invalid('布尔值无效');
        }return $v;
    }
    public static function requestId(mixed $v): string
    {
        $v = self::text($v, 100);
        if (!preg_match('/^[A-Za-z0-9:_-]+$/D', $v)) {
            self::invalid('请求标识无效');
        }return $v;
    }
    public static function file(mixed $v): string
    {
        $v = self::text($v, 40);
        if (!preg_match('/^file_[a-f0-9]{32}$/D', $v)) {
            self::invalid('须为真实File稳定标识');
        }return $v;
    }
    public static function time(mixed $v): string
    {
        $v = self::text($v, 20);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $v, new \DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d\\TH:i:s\\Z') !== $v) {
            self::invalid('时间须为真实UTC，例如2026-11-01T00:00:00Z');
        }return $v;
    }
    public static function object(mixed $v): array
    {
        if (!is_array($v) || array_is_list($v) && $v !== []) {
            self::invalid('须为业务对象');
        }return $v;
    }
}
