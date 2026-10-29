<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery;

final class Package
{
    public const MODULE_KEY = 'peanut.notification';
    public const RESOURCE_KEY = 'peanut.notification.delivery';
    public const READ_PERMISSION = 'peanut.notification.inbox.read';
    public const MANAGE_PERMISSION = 'peanut.notification.delivery.manage';

    private function __construct() {}
}
