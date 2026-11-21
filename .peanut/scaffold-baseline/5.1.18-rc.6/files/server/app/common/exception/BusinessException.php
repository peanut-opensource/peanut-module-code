<?php

declare(strict_types=1);

namespace app\common\exception;

/** A domain rejection whose message is safe to expose through the API boundary. */
final class BusinessException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        if ($errorCode === '' || $httpStatus < 400 || $httpStatus > 499) {
            throw new \InvalidArgumentException('BUSINESS_EXCEPTION_INVALID');
        }
        parent::__construct($message);
    }

    public static function invalid(string $errorCode, string $message): self
    {
        return new self($errorCode, 400, $message);
    }

    public static function forbidden(string $errorCode, string $message): self
    {
        return new self($errorCode, 403, $message);
    }

    public static function notFound(string $errorCode, string $message): self
    {
        return new self($errorCode, 404, $message);
    }

    public static function conflict(string $errorCode, string $message): self
    {
        return new self($errorCode, 409, $message);
    }
}
