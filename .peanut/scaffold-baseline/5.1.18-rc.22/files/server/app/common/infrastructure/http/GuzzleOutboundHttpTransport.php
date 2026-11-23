<?php

declare(strict_types=1);

namespace app\common\infrastructure\http;

use app\common\contract\http\OutboundHttpTransport;
use app\common\exception\http\OutboundHttpException;
use app\common\value\http\OutboundHttpAttemptObservation;
use app\common\value\http\OutboundHttpRequest;
use app\common\value\http\OutboundHttpResponse;
use app\common\execution\CurrentExecutionContext;
use app\common\infrastructure\runtime\OperationalLog;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Single outbound HTTP adapter with bounded retry and secret-free diagnostics. */
final readonly class GuzzleOutboundHttpTransport implements OutboundHttpTransport
{
    private ClientInterface $client;

    public function __construct(
        private CurrentExecutionContext $executionContext,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client ?? new Client();
    }

    public function send(OutboundHttpRequest $request): OutboundHttpResponse
    {
        $attempts = $request->retrySafe ? 2 : 1;
        $last = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $startedAt = hrtime(true);
            try {
                $response = $this->client->request(
                    strtoupper($request->method),
                    $request->url,
                    array_filter([
                        'allow_redirects' => $request->allowRedirects,
                        'body' => $request->body !== '' ? $request->body : null,
                        'connect_timeout' => $request->connectTimeoutSeconds,
                        'headers' => $request->headers,
                        'http_errors' => false,
                        'multipart' => $request->multipart !== [] ? $request->multipart : null,
                        'proxy' => $request->directConnection ? '' : null,
                        'sink' => $request->sink,
                        'timeout' => $request->timeoutSeconds,
                        'verify' => $request->caBundleFile ?? true,
                    ], static fn(mixed $value): bool => $value !== null),
                );
                $status = $response->getStatusCode();
                OutboundHttpAttemptObservation::response(
                    $this->executionContext,
                    $request->method,
                    $request->url,
                    $attempt,
                    $startedAt,
                    $status,
                );
                if ($request->retrySafe && $attempt < $attempts && $status >= 500) {
                    continue;
                }
                $headers = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $headers[strtolower($name)] = implode(', ', $values);
                }
                return new OutboundHttpResponse(
                    $status,
                    $request->sink === null ? (string) $response->getBody() : '',
                    $headers,
                );
            } catch (GuzzleException $exception) {
                $last = $exception;
                OutboundHttpAttemptObservation::failure(
                    $this->executionContext,
                    $request->method,
                    $request->url,
                    $attempt,
                    $startedAt,
                    $exception,
                );
                if ($attempt < $attempts) {
                    continue;
                }
            }
        }

        OperationalLog::warning($this->executionContext, 'outbound_http_unavailable', [
            'method' => strtoupper($request->method),
            'host' => (string) (parse_url($request->url, PHP_URL_HOST) ?: 'unknown'),
            'exception' => $last === null ? 'unknown' : $last::class,
        ]);
        throw new OutboundHttpException($last);
    }

}
