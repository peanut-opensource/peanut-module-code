<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerPolicy;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use think\App;

/** Composition-root declarations only; no dynamic caller class lookup. */
final readonly class DocumentOwnerRegistry
{
    public function __construct(private App $app, private array $policies) {}
    public function requirePolicy(DocumentOwnerReference $owner): DocumentOwnerPolicy
    {
        $class = $this->policies[$owner->moduleKey . ':' . $owner->objectType] ?? null;
        if (!is_string($class)) {
            throw BusinessException::notFound('DOCUMENT_OWNER_UNAVAILABLE', '文档业务归属不可用');
        }
        $policy = $this->app->make($class);
        if (!$policy instanceof DocumentOwnerPolicy) {
            throw new \LogicException('DOCUMENT_OWNER_POLICY_INVALID');
        }
        return $policy;
    }
}
