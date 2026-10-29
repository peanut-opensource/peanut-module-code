<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** Source-registered business producer, limited to its own durable facts and current object scope. */
interface NotificationProducer
{
    public function key(): string;
    public function resourceKey(): string;
    public function operation(): string;
    public function taskType(): string;
    public function handlerKey(): string;
    public function authorize(TenantContext $context, NotificationPublicationIdentity $identity): AuthorizedOperationContext;
    public function authorizeRead(TenantContext $context, NotificationPublicationIdentity $identity): AuthorizedOperationContext;
    /** Trusted template definition and frozen recipients/variables/file keys, never caller-selected content. */
    public function publication(AuthorizedOperationContext $context, NotificationPublicationIdentity $identity): array;
}
