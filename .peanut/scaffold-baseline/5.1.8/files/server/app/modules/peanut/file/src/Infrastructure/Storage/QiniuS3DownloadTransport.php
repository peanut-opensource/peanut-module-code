<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Infrastructure\Storage;

use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Psr7\Request;
use PeanutAdmin\FileMedia\Storage\StorageHttpTransport;

/** Only Qiniu's configured S3 download edge needs AWS authentication; Kodo calls retain their native transport. */
final readonly class QiniuS3DownloadTransport implements StorageHttpTransport
{
    private string $origin;
    private string $host;
    private string $signingRegion;
    private Credentials $credentials;

    public function __construct(
        private StorageHttpTransport $transport,
        string $domain,
        string $bucket,
        string $region,
        array $credentials,
    ) {
        $parts = parse_url($domain);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || preg_match('/^' . preg_quote($bucket, '/') . '\.s3\.([a-z0-9-]+)\.qiniucs\.com$/D', $parts['host'] ?? '', $match) !== 1
            || $region !== $match[1] || !is_string($credentials['access_key'] ?? null) || $credentials['access_key'] === ''
            || !is_string($credentials['secret_key'] ?? null) || $credentials['secret_key'] === '') {
            throw new \InvalidArgumentException('QINIU_S3_DOWNLOAD_CONFIGURATION_INVALID');
        }
        $this->host = $parts['host'];
        $this->origin = 'https://' . $this->host;
        $this->signingRegion = $region;
        // Explicit decrypted account credentials only: no provider chain, environment profile, IMDS or discovery.
        $this->credentials = new Credentials($credentials['access_key'], $credentials['secret_key']);
    }

    public static function usesS3Domain(string $domain): bool
    {
        $host = parse_url($domain, PHP_URL_HOST);
        return is_string($host) && str_contains($host, '.s3.') && str_ends_with($host, '.qiniucs.com');
    }

    public function request(array $request): array
    {
        $parts = parse_url($request['url']);
        if (($parts['host'] ?? null) !== $this->host) {
            return $this->transport->request($request);
        }
        if (($request['method'] ?? '') !== 'GET' || ($parts['scheme'] ?? '') !== 'https'
            || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['port']) || isset($parts['fragment'])
            || !is_string($request['sink'] ?? null) || $request['sink'] === ''
            || ($request['body'] ?? '') !== '' || ($request['multipart'] ?? []) !== []) {
            throw new \InvalidArgumentException('QINIU_S3_DOWNLOAD_REQUEST_INVALID');
        }
        parse_str($parts['query'] ?? '', $query);
        if (array_diff(array_keys($query), ['e', 'token']) !== [] || count($query) !== 2
            || !is_string($query['e'] ?? null) || !ctype_digit($query['e'])
            || !is_string($query['token'] ?? null) || $query['token'] === '') {
            throw new \InvalidArgumentException('QINIU_S3_NATIVE_DOWNLOAD_INPUT_INVALID');
        }
        // The Core driver supplied a native-token URL. That token is never forwarded to the S3 protocol.
        $unsigned = new Request('GET', $this->origin . ($parts['path'] ?? '/'), $request['headers'] ?? []);
        $signed = (new S3SignatureV4('s3', $this->signingRegion))->signRequest($unsigned, $this->credentials);
        $request['url'] = (string) $signed->getUri();
        $request['headers'] = array_map(static fn(array $values): string => implode(', ', $values), $signed->getHeaders());
        // Keep the existing strict TLS, no-redirect, bounded retry, timeout and sink behavior.
        return $this->transport->request($request);
    }
}
