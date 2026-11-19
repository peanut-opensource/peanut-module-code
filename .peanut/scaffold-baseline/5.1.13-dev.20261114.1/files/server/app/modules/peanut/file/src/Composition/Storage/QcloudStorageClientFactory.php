<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Composition\Storage;

use app\common\execution\CurrentExecutionContext;
use app\common\value\http\OutboundHttpAttemptObservation;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Qcloud\Cos\Client;

/** Provider SDK assembly kept outside business storage drivers. */
final class QcloudStorageClientFactory
{
    public function __construct(private readonly CurrentExecutionContext $executionContext) {}

    public function make(array $account, array $space): Client
    {
        $credentials = (array) ($account['resolved_credentials'] ?? []);
        $client = new Client([
            'scheme' => 'https',
            'region' => (string) ($space['region'] ?? ''),
            'connect_timeout' => 10,
            'timeout' => 30,
            'retry' => 1,
            'allow_redirects' => false,
            'credentials' => [
                'secretId' => (string) ($credentials['access_key'] ?? ''),
                'secretKey' => (string) ($credentials['secret_key'] ?? ''),
            ],
        ]);
        /** @var HandlerStack $handler */
        $handler = $client->httpClient->getConfig('handler');
        $executionContext = $this->executionContext;
        $handler->push(static function (callable $next) use ($executionContext): callable {
            return static function (RequestInterface $request, array $options) use ($next, $executionContext) {
                $startedAt = hrtime(true);
                $attempt = (int) ($options['retries'] ?? 0) + 1;
                return $next($request, $options)->then(
                    static function (ResponseInterface $response) use ($executionContext, $request, $attempt, $startedAt): ResponseInterface {
                        OutboundHttpAttemptObservation::response(
                            $executionContext,
                            $request->getMethod(),
                            (string) $request->getUri(),
                            $attempt,
                            $startedAt,
                            $response->getStatusCode(),
                        );
                        return $response;
                    },
                    static function (\Throwable $exception) use ($executionContext, $request, $attempt, $startedAt) {
                        OutboundHttpAttemptObservation::failure(
                            $executionContext,
                            $request->getMethod(),
                            (string) $request->getUri(),
                            $attempt,
                            $startedAt,
                            $exception,
                        );
                        return Create::rejectionFor($exception);
                    },
                );
            };
        });
        return $client;
    }
}
