<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Service;

use PeanutAdmin\Modules\OAuth\Contract\OfficialAccountCallbacks;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Modules\OAuth\Infrastructure\WechatOfficialAccountService;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;

class OfficialAccountApplicationService implements OfficialAccountCallbacks
{
    private const CONFIG_TYPE = 'oa_setting';
    private const SECRET_MASK = '******';

    public function __construct(
        private readonly ExternalChannelBindings $bindings,
        private readonly FileReferences $files,
        private readonly OfficialAccountReplyApplicationService $replies,
    ) {}

    public function verify(array $params, array $config): bool
    {
        return WechatOfficialAccountService::verifySignature(
            (string) ($config['token'] ?? ''),
            (string) ($params['timestamp'] ?? ''),
            (string) ($params['nonce'] ?? ''),
            (string) ($params['signature'] ?? ''),
        );
    }

    public function handlePlain(TenantSystemContext $context, string $xml): string
    {
        try {
            $message = WechatOfficialAccountService::parsePlainMessage($xml);
        } catch (\runtimeException) {
            throw BusinessException::forbidden('OFFICIAL_ACCOUNT_MESSAGE_INVALID', 'callback rejected');
        }
        $reply = $this->replies->resolve($context, $message);
        if ($reply === null || trim((string) ($reply['content'] ?? '')) === '') {
            return 'success';
        }
        return WechatOfficialAccountService::textReplyXml($message, (string) $reply['content']);
    }

    public function getConfig(TenantContext $context, string $domain): array
    {
        $stored = $this->bindings->config($context, ExternalProvider::WECHAT_OFFICIAL_CALLBACK);
        $qrCode = (string) ($stored['qr_code'] ?? '');
        $secret = (string) ($stored['app_secret'] ?? '');
        $token = (string) ($stored['token'] ?? '');
        $domain = rtrim($domain, '/');
        $authority = self::authority($domain);

        return [
            'name' => (string) ($stored['name'] ?? ''),
            'original_id' => (string) ($stored['original_id'] ?? ''),
            'qr_code' => $this->files->getFileUrl($qrCode),
            'app_id' => (string) ($stored['app_id'] ?? ''),
            'app_secret' => self::maskedSecret($secret),
            'app_secret_configured' => $secret !== '',
            'url' => $token === '' ? '' : $domain . '/api/wechat/official-account/callback/'
                . $this->bindings->callbackKey($context, ExternalProvider::WECHAT_OFFICIAL_CALLBACK),
            'token' => self::maskedSecret($token),
            'token_configured' => $token !== '',
            'business_domain' => $authority,
            'js_secure_domain' => $authority,
            'web_auth_domain' => $authority,
            'callback_mode' => 'plaintext',
        ];
    }

    public function setConfig(TenantContext $context, array $params): bool
    {
        $current = $this->bindings->config($context, ExternalProvider::WECHAT_OFFICIAL_CALLBACK);
        $currentSecret = (string) ($current['app_secret'] ?? '');
        $incomingSecret = trim((string) $params['app_secret']);
        $secret = self::retainedSecret($incomingSecret, $currentSecret);
        if ($secret === '') {
            throw BusinessException::invalid('OAUTH_APP_SECRET_REQUIRED', 'AppSecret 不能为空');
        }
        $currentToken = (string) ($current['token'] ?? '');
        $incomingToken = trim((string) ($params['token'] ?? ''));
        $token = self::retainedSecret($incomingToken, $currentToken);
        $data = [
            'name' => trim((string) ($params['name'] ?? '')),
            'original_id' => trim((string) ($params['original_id'] ?? '')),
            'qr_code' => $this->relativeFile($context, (string) ($params['qr_code'] ?? '')),
            'app_id' => trim((string) $params['app_id']),
            'app_secret' => $secret,
            'token' => $token,
        ];
        Db::transaction(function () use ($context, $data): void {
            $this->bindings->update(
                $context,
                ExternalProvider::WECHAT_OFFICIAL_CALLBACK,
                $data,
                $data['original_id'] !== '' ? $data['original_id'] : $data['app_id'],
            );
            $this->bindings->update(
                $context,
                ExternalProvider::WECHAT_OFFICIAL_OAUTH,
                ['app_id' => $data['app_id'], 'app_secret' => $data['app_secret']],
                $data['app_id'],
            );
        });
        return true;
    }

    private static function maskedSecret(string $value): string
    {
        return $value === '' ? '' : self::SECRET_MASK;
    }

    private static function retainedSecret(string $incoming, string $current): string
    {
        return hash_equals(self::SECRET_MASK, $incoming) ? $current : $incoming;
    }

    private function relativeFile(TenantContext $context, string $value): string
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

    private static function authority(string $domain): string
    {
        $parts = parse_url($domain);
        if (!is_array($parts) || empty($parts['host'])) {
            return trim((string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $domain), '/');
        }
        $host = (string) $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        return $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
