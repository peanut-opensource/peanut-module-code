<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Infrastructure;

use PeanutAdmin\IntegrationSecurity\Webhook\WebhookRequest;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookResponse;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookTransport;

/** HTTPS boundary: connect only to the policy-approved IP; dispatcher owns retries. */
final readonly class CurlWebhookTransport implements WebhookTransport
{
    private const MAX_RESPONSE_BYTES = 65536;

    public function send(WebhookRequest $request): WebhookResponse
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('WEBHOOK_CURL_REQUIRED');
        }
        $destination = $request->destination;
        $address = $destination->approvedAddresses[0] ?? null;
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false
            || $destination->port !== 443 || $request->followRedirects
            || parse_url($destination->url, PHP_URL_SCHEME) !== 'https'
            || trim((string) parse_url($destination->url, PHP_URL_HOST), '[]') !== $destination->host
        ) {
            throw new \RuntimeException('WEBHOOK_DESTINATION_INVALID');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        // Brackets delimit IPv6 addresses in libcurl's resolve entry. The URL
        // retains the original hostname for Host, certificate verification and SNI.
        $resolvedAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $resolvedHost = str_contains($destination->host, ':') ? '[' . $destination->host . ']' : $destination->host;
        $headerBytes = 0;
        $bodyBytes = 0;
        $handle = curl_init();
        if ($handle === false) {
            throw new \RuntimeException('WEBHOOK_CURL_UNAVAILABLE');
        }
        $startedAt = hrtime(true);
        try {
            if (!curl_setopt_array($handle, [
                CURLOPT_URL => $destination->url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $request->body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RESOLVE => [$resolvedHost . ':443:' . $resolvedAddress],
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => min(3, $request->timeoutSeconds),
                CURLOPT_TIMEOUT => $request->timeoutSeconds,
                CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$headerBytes): int {
                    $headerBytes += strlen($chunk);
                    return $headerBytes <= self::MAX_RESPONSE_BYTES ? strlen($chunk) : 0;
                },
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$bodyBytes): int {
                    $bodyBytes += strlen($chunk);
                    return $bodyBytes <= self::MAX_RESPONSE_BYTES ? strlen($chunk) : 0;
                },
            ]) || curl_exec($handle) === false) {
                // Do not expose response bodies, signed headers, URL queries or cURL errors.
                throw new \RuntimeException('WEBHOOK_TRANSPORT_FAILED');
            }
            return new WebhookResponse(
                (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                min(30000, (int) ((hrtime(true) - $startedAt) / 1000000)),
            );
        } finally {
            curl_close($handle);
        }
    }
}
