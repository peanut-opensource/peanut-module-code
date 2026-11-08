<?php

declare(strict_types=1);

namespace app\common\http\middleware;

use app\common\services\installation\InstallationExecutionHost;
use think\facade\Config;

/** Keeps every business API closed while a guided fresh installation is incomplete. */
final class InstallationStateMiddleware
{
    public function __construct(private readonly InstallationExecutionHost $host) {}

    public function handle($request, \Closure $next)
    {
        if (trim((string) Config::get('peanut.installation.mode', 'automatic')) !== 'guided') {
            return $next($request);
        }

        try {
            $installed = $this->host->isInstalled();
        } catch (\Throwable) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                '系统安装状态不可用。',
                ['error_code' => 'INSTALL_STATUS_UNAVAILABLE'],
                50300,
            );
        }

        if (!$installed) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                '系统尚未完成安装。',
                ['error_code' => 'INSTALLATION_REQUIRED'],
                50300,
            );
        }

        return $next($request);
    }
}
