<?php

declare(strict_types=1);

namespace app\platform\controller;

use PeanutAdmin\Modules\File\Contract\StorageConfiguration;
use app\platform\context\PlatformOperatorContext;

/** @property-read StorageConfiguration $storage 当前 App 中声明式解析的控制器依赖。 */
final class PlatformStorageController extends BasePlatformController
{
    protected string $storageClass = StorageConfiguration::class;

    public function snapshot()
    {
        return $this->data($this->storage->snapshot());
    }

    public function createAccount()
    {
        return $this->data([
            'id' => $this->storage->createAccount($this->context(), $this->request->post()),
        ]);
    }

    public function updateAccount()
    {
        $this->storage->updateAccount($this->context(), $this->request->post());
        return $this->success('存储账号已更新');
    }

    public function createSpace()
    {
        return $this->data([
            'id' => $this->storage->createSpace($this->context(), $this->request->post()),
        ]);
    }

    public function updateSpace()
    {
        $this->storage->updateSpace($this->context(), $this->request->post());
        return $this->success('Space 已更新');
    }

    public function setRoute()
    {
        $this->storage->setRoute($this->context(), $this->request->post());
        return $this->success('存储路由已更新');
    }

    private function context(): PlatformOperatorContext
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                'Platform authentication is required.',
                null,
                40100,
            );
        }
        return $this->platformContext;
    }
}
