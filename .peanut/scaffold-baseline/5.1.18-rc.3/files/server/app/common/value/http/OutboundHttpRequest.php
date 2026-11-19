<?php

declare(strict_types=1);

namespace app\common\value\http;

final readonly class OutboundHttpRequest
{
    /**
     * @param array<string,string> $headers
     * @param list<array{name:string,contents:mixed,filename?:string,headers?:array<string,string>}> $multipart
     * @param bool $directConnection Bypass client/process proxies for private service traffic.
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public string $body = '',
        public int $connectTimeoutSeconds = 10,
        public int $timeoutSeconds = 20,
        public bool $retrySafe = false,
        public ?string $sink = null,
        public bool $allowRedirects = false,
        public array $multipart = [],
        public ?string $caBundleFile = null,
        public bool $directConnection = false,
        // Explicit local collaboration opt-in; ordinary outbound callers remain HTTPS-only.
        public bool $allowDevelopmentHttp = false,
    ) {
        if (!in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || !(str_starts_with($url, 'https://') || ($allowDevelopmentHttp && str_starts_with($url, 'http://')))
            || $connectTimeoutSeconds < 1
            || $connectTimeoutSeconds > 30
            || $timeoutSeconds < $connectTimeoutSeconds
            || $timeoutSeconds > 120
            || ($retrySafe && strtoupper($method) !== 'GET')
            || ($body !== '' && $multipart !== [])
            || ($multipart !== [] && strtoupper($method) !== 'POST')) {
            throw new \InvalidArgumentException('OUTBOUND_HTTP_REQUEST_INVALID');
        }
        foreach ($multipart as $part) {
            if (!is_array($part) || trim((string) ($part['name'] ?? '')) === ''
                || !array_key_exists('contents', $part)) {
                throw new \InvalidArgumentException('OUTBOUND_HTTP_MULTIPART_INVALID');
            }
        }
        if ($caBundleFile !== null) {
            $stat = @lstat($caBundleFile);
            if (!str_starts_with($caBundleFile, '/') || realpath($caBundleFile) !== $caBundleFile || is_link($caBundleFile)
                || !is_file($caBundleFile) || !is_readable($caBundleFile) || !is_array($stat) || $stat['nlink'] !== 1
                || ($stat['mode'] & 0022) !== 0 || $stat['size'] < 1 || $stat['size'] > 1048576) {
                throw new \InvalidArgumentException('OUTBOUND_HTTP_CA_BUNDLE_INVALID');
            }
        }
    }
}
