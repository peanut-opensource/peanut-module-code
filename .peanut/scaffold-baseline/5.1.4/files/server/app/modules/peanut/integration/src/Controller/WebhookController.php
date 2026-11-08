<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Controller;

use PeanutAdmin\Modules\Integration\Application\IntegrationAdminApplicationService;
use PeanutAdmin\Modules\Integration\Application\WebhookEndpoint;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use think\response\Json;

/** @property-read IntegrationAdminApplicationService $webhooks 当前 App 中声明式解析的控制器依赖。 */
final class WebhookController extends IntegrationAdminController
{
    protected string $webhooksClass = IntegrationAdminApplicationService::class;

    public function index(): Json
    {
        try {
            $items = $this->webhooks->webhooks($this->tenantAdminContext(), $this->tenantAdminActor());
            return $this->response(['items' => array_map($this->endpoint(...), $items)]);
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function create(): Json
    {
        try {
            $body = $this->body(['name', 'url', 'events']);
            if (!is_string($body['name'] ?? null) || !is_string($body['url'] ?? null)
                || !is_array($body['events'] ?? null) || !array_is_list($body['events'])
            ) {
                throw IntegrationSecurityException::invalid();
            }
            $created = $this->webhooks->createWebhook(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $body['name'],
                $body['url'],
                $body['events'],
            );
            return $this->response([
                'endpoint' => $this->endpoint($created->endpoint),
                'signing_secret' => $created->signingSecret,
            ], 201);
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function rotateSecret(string $endpointKey): Json
    {
        try {
            $body = $this->body(['revision']);
            $rotated = $this->webhooks->rotateWebhookSecret(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $endpointKey,
                $this->positiveInteger($body['revision'] ?? null),
            );
            return $this->response([
                'endpoint' => $this->endpoint($rotated->endpoint),
                'signing_secret' => $rotated->signingSecret,
            ]);
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function disable(string $endpointKey): Json
    {
        try {
            $body = $this->body(['revision']);
            return $this->response($this->endpoint($this->webhooks->disableWebhook(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $endpointKey,
                $this->positiveInteger($body['revision'] ?? null),
            )));
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    /** @return array<string,mixed> */
    private function endpoint(WebhookEndpoint $endpoint): array
    {
        return [
            'endpoint_key' => $endpoint->endpointKey, 'name' => $endpoint->name,
            'url' => $endpoint->url, 'events' => $endpoint->events,
            'status' => $endpoint->status, 'revision' => $endpoint->revision,
            'created_at' => $endpoint->createdAt,
        ];
    }

    private function response(mixed $data, int $status = 200): Json
    {
        return json(['data' => $data, 'meta' => ['request_id' => $this->executionContext()->requestId()]], $status);
    }
}
