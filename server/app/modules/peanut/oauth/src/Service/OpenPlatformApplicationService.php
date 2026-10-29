<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;

class OpenPlatformApplicationService
{
    private const CONFIG_TYPE = 'open_platform';

    public function __construct(private readonly ExternalChannelBindings $bindings) {}

    public function getConfig(TenantContext $context): array
    {
        $stored = $this->bindings->config($context, ExternalProvider::WECHAT_OPEN_PLATFORM);
        $secret = (string) ($stored['app_secret'] ?? '');
        return [
            'app_id' => (string) ($stored['app_id'] ?? ''),
            'app_secret' => $secret !== '' ? '******' : '',
            'app_secret_configured' => $secret !== '',
        ];
    }

    public function setConfig(TenantContext $context, array $params): bool
    {
        $current = $this->bindings->config($context, ExternalProvider::WECHAT_OPEN_PLATFORM);
        $currentSecret = (string) ($current['app_secret'] ?? '');
        $incomingSecret = trim((string) $params['app_secret']);
        $secret = $incomingSecret === '******' ? $currentSecret : $incomingSecret;
        if ($secret === '') {
            throw BusinessException::invalid('OAUTH_APP_SECRET_REQUIRED', 'AppSecret 不能为空');
        }
        $data = [
            'app_id' => trim((string) $params['app_id']),
            'app_secret' => $secret,
        ];
        $this->bindings->update(
            $context,
            ExternalProvider::WECHAT_OPEN_PLATFORM,
            $data,
            $data['app_id'],
        );
        return true;
    }
}
