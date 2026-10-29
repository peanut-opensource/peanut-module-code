<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** 微信小程序基础配置。 */
class MiniProgramApplicationService
{
    protected const CONFIG_TYPE = 'mnp_setting';

    public function __construct(
        private readonly ExternalChannelBindings $bindings,
        private readonly FileReferences $files,
    ) {}

    public function getConfig(TenantContext $context, string $domain): array
    {
        $stored = $this->bindings->config($context, ExternalProvider::WECHAT_MINI_PROGRAM);
        $qrCode = (string) ($stored['qr_code'] ?? '');
        $secret = (string) ($stored['app_secret'] ?? '');
        $domains = self::domainConfig($domain);

        return [
            'name'                 => (string) ($stored['name'] ?? ''),
            'original_id'          => (string) ($stored['original_id'] ?? ''),
            'qr_code'              => $this->files->getFileUrl($qrCode),
            'app_id'               => (string) ($stored['app_id'] ?? ''),
            'app_secret'           => $secret !== '' ? '******' : '',
            'app_secret_configured' => $secret !== '',
            'request_domain'       => $domains['https'],
            'socket_domain'        => $domains['wss'],
            'upload_file_domain'   => $domains['https'],
            'download_file_domain' => $domains['https'],
            'udp_domain'           => $domains['udp'],
            'business_domain'      => $domains['authority'],
        ];
    }

    public function setConfig(TenantContext $context, array $params): bool
    {
        $current = $this->bindings->config($context, ExternalProvider::WECHAT_MINI_PROGRAM);
        $currentSecret = (string) ($current['app_secret'] ?? '');
        $incomingSecret = trim((string) $params['app_secret']);
        $secret = $incomingSecret === '******' ? $currentSecret : $incomingSecret;
        if ($secret === '') {
            throw BusinessException::invalid('OAUTH_APP_SECRET_REQUIRED', 'AppSecret 不能为空');
        }
        $data = [
            'name'        => trim((string) ($params['name'] ?? '')),
            'original_id' => trim((string) ($params['original_id'] ?? '')),
            'qr_code'     => $this->relativeQrCode($context, (string) ($params['qr_code'] ?? '')),
            'app_id'      => trim((string) $params['app_id']),
            'app_secret'  => $secret,
        ];
        $this->bindings->update(
            $context,
            ExternalProvider::WECHAT_MINI_PROGRAM,
            $data,
            $data['app_id'],
        );
        return true;
    }

    /** @return array{https:string,wss:string,udp:string,authority:string} */
    private static function domainConfig(string $domain): array
    {
        $domain = rtrim($domain, '/');
        $parts = parse_url($domain);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if ($host === '') {
            $authority = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $domain) ?: '';
            $authority = trim($authority, '/');
        } else {
            if (str_contains($host, ':') && !str_starts_with($host, '[')) {
                $host = '[' . $host . ']';
            }
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $authority = $host . $port;
        }

        return [
            'https'     => 'https://' . $authority,
            'wss'       => 'wss://' . $authority,
            'udp'       => 'udp://' . $authority,
            'authority' => $authority,
        ];
    }

    private function relativeQrCode(TenantContext $context, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $uri = $this->files->setTenantFileUrl($context, $value);
        if (preg_match('#^https?://#i', $uri)) {
            $uri = (string) parse_url($uri, PHP_URL_PATH);
        }
        return ltrim($uri, '/');
    }
}
