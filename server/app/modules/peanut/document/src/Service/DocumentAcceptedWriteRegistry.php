<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Document\Contract\DocumentAcceptedWriteProvider;
use think\App;

final readonly class DocumentAcceptedWriteRegistry
{
    public function __construct(private App $app, private ?string $providerClass) {}

    public function requireProvider(): DocumentAcceptedWriteProvider
    {
        if ($this->providerClass === null) {
            throw BusinessException::forbidden('DOCUMENT_ACCEPTED_PROVIDER_UNAVAILABLE', '协作接纳证明不可用');
        }
        $provider = $this->app->make($this->providerClass);
        if (!$provider instanceof DocumentAcceptedWriteProvider) {
            throw new \LogicException('DOCUMENT_ACCEPTED_PROVIDER_INVALID');
        }
        return $provider;
    }
}
