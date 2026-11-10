<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Infrastructure\Storage;

use app\common\exception\BusinessException;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;
use Qiniu\Auth;

/** File visibility policy for a Qiniu source with no bound origin/CDN and no provider object versions. */
final readonly class QiniuVisibilityOrigin
{
    public function __construct(
        private Auth $auth,
        private QiniuStorageHttpTransport $transport,
        private string $bucket,
        private string $region,
        private string $domain,
    ) {}

    /** Stable provider facts for a signed manifest, never credentials, signed URLs or an operator-supplied qualification flag. */
    public function observe(): array
    {
        $bucket = $this->read('POST', 'https://uc.qbox.me/v2/bucketInfo?bucket=' . rawurlencode($this->bucket), false);
        // This first provider closure supports the authenticated cn-north-1/z1 route. Other regions remain explicit unsupported inputs.
        if (($bucket['private'] ?? null) !== 0 || ($bucket['versioning'] ?? null) !== false
            || ($bucket['region'] ?? null) !== 'z1' || $this->region !== 'cn-north-1') {
            $this->unqualified();
        }
        $origins = $this->read('GET', 'https://uc.qiniuapi.com/domain?bucket=' . rawurlencode($this->bucket), true);
        if (!array_is_list($origins) || $origins !== []) {
            $this->unqualified();
        }
        $cdn = $this->read('GET', 'https://api.qiniu.com/domain?' . http_build_query([
            'sourceTypes' => 'qiniuBucket', 'sourceQiniuBucket' => $this->bucket, 'limit' => 1000,
        ]), true);
        if (!is_array($cdn['domains'] ?? null) || !array_is_list($cdn['domains']) || $cdn['domains'] !== []
            || !in_array($cdn['marker'] ?? '', ['', null], true)) {
            $this->unqualified();
        }
        $parts = parse_url($this->domain);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== $this->bucket . '.s3.' . $this->region . '.qiniucs.com'
            || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            $this->unqualified();
        }
        return ['provider' => 'qiniu', 'bucket' => $this->bucket, 'region' => $this->region,
            'download_origin' => rtrim($this->domain, '/'), 'private' => 0, 'versioning' => false,
            'custom_origins' => [], 'known_cdn' => [], 'domain_listing_complete' => true];
    }

    public function verifyAnonymousAbsent(string $objectKey): int
    {
        $key = StorageObjectKey::assert($objectKey);
        $url = rtrim($this->domain, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $response = $this->transport->request([
            'method' => 'GET', 'url' => $url, 'headers' => ['Range' => 'bytes=0-0'],
            'connectTimeout' => 5, 'timeout' => 15, 'retrySafe' => false,
        ]);
        if (!in_array($response['status'], [401, 403, 404, 410], true)) {
            throw BusinessException::conflict('FILE_VISIBILITY_SOURCE_STILL_PUBLIC', '原公开地址尚未确认拒绝匿名访问');
        }
        return $response['status'];
    }

    private function read(string $method, string $url, bool $v2): array
    {
        $type = 'application/x-www-form-urlencoded';
        $headers = $v2 ? $this->auth->authorizationV2($url, $method, null, $type) : $this->auth->authorization($url, null, $type);
        $headers['Content-Type'] = $type;
        $response = $this->transport->request([
            'method' => $method, 'url' => $url, 'headers' => $headers,
            'connectTimeout' => 5, 'timeout' => 15, 'retrySafe' => false,
        ]);
        $body = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($body)) {
            $this->unqualified();
        }
        return $body;
    }

    private function unqualified(): never
    {
        throw BusinessException::conflict('FILE_VISIBILITY_ORIGIN_UNQUALIFIED', '源站、历史版本或已知缓存关闭能力尚未确认');
    }
}
