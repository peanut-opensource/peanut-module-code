<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\common\value\http\OutboundHttpRequest;
use app\common\contract\http\OutboundHttpTransport;
use app\common\contract\payment\PaymentTransportInterface;
use app\common\dto\payment\TransportResponse;

final class CurlPaymentTransport implements PaymentTransportInterface
{
    public function __construct(private readonly OutboundHttpTransport $transport) {}

    public function request(string $method, string $url, array $headers, string $body = ''): TransportResponse
    {
        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                [$name, $value] = explode(':', $value, 2);
            }
            $normalizedHeaders[trim((string) $name)] = trim((string) $value);
        }
        $response = $this->transport->send(
            new OutboundHttpRequest(
                $method,
                $url,
                $normalizedHeaders,
                $body,
                timeoutSeconds: 30,
            ),
        );
        return new TransportResponse($response->status, $response->body, $response->headers);
    }
}
