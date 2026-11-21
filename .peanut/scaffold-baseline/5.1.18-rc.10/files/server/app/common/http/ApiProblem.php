<?php

declare(strict_types=1);

namespace app\common\http;

/** Exception rendered by the single API error boundary. */
final class ApiProblem extends \RuntimeException
{
    /**
     * @param array<string,mixed> $details
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly array $details = [],
        public readonly ?int $responseCode = null,
        public readonly mixed $responseData = null,
        public readonly array $headers = [],
    ) {
        if ($errorCode === '' || $httpStatus < 400 || $httpStatus > 599) {
            throw new \InvalidArgumentException('API_PROBLEM_INVALID');
        }
        parent::__construct($message);
    }

    public function apiCode(): int
    {
        return $this->responseCode ?? $this->httpStatus * 100;
    }

    public function data(): mixed
    {
        return $this->responseCode === null
            ? ['error_code' => $this->errorCode] + $this->details
            : $this->responseData;
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->errorCode,
            $this->httpStatus,
            $this->getMessage(),
            $this->details,
            $this->responseCode,
            $this->responseData,
            $headers + $this->headers,
        );
    }

    /** Preserve the established JSON envelope while moving rendering to one boundary. */
    public static function fromEnvelope(
        string $message,
        mixed $data = null,
        int $code = 40000,
    ): self {
        $status = intdiv($code, 100);
        if ($status < 400 || $status > 599) {
            $status = 400;
        }
        $errorCode = is_array($data) && is_string($data['error_code'] ?? null)
            ? $data['error_code']
            : 'API_ERROR_' . $code;

        return new self($errorCode, $status, $message, [], $code, $data);
    }
}
