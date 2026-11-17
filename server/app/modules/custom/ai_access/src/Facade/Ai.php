<?php

declare(strict_types=1);

namespace Custom\Modules\AiAccess\Facade;

use Custom\Modules\AiAccess\Contract\AiClient;
use think\Facade;

/**
 * @method static array generateText(string $prompt, string $requestKey, ?string $model = null, ?string $provider = null, ?string $credential = null)
 * @method static array generateImage(string $prompt, string $requestKey, ?string $model = null, ?string $provider = null, ?string $credential = null)
 * @method static array requestStatus(string $requestKey)
 */
final class Ai extends Facade
{
    protected static function getFacadeClass(): string
    {
        return AiClient::class;
    }
}
