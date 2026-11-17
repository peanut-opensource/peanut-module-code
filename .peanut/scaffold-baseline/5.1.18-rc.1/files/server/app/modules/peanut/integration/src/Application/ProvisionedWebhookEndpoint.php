<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Application;

final readonly class ProvisionedWebhookEndpoint
{
    public function __construct(public WebhookEndpoint $endpoint, public string $signingSecret) {}
}
