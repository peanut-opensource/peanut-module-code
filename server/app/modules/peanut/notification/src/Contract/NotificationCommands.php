<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

interface NotificationCommands
{
    public function saveChannel(string $section, array $input): void;

    public function saveScene(array $params): void;
}
