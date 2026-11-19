<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Controller;

use PeanutAdmin\Modules\Integration\Application\IntegrationAdminApplicationService;
use PeanutAdmin\Modules\Integration\Application\IntegrationSecurityPage;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use think\response\Json;
use think\App;

final class WebhookDeliveryController extends IntegrationAdminController
{
    public function __construct(
        App $app,
        private readonly IntegrationAdminApplicationService $deliveries,
    ) {
        parent::__construct($app);
    }

    public function index(): Json
    {
        try {
            return $this->response($this->deliveries->deliveries(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $this->positiveInteger($this->request->get('page', 1)),
                $this->positiveInteger($this->request->get('page_size', 20)),
            ));
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function attempts(string $deliveryKey): Json
    {
        try {
            return $this->response($this->deliveries->deliveryAttempts(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $deliveryKey,
                $this->positiveInteger($this->request->get('page', 1)),
                $this->positiveInteger($this->request->get('page_size', 20)),
            ));
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    private function response(IntegrationSecurityPage $page): Json
    {
        return json([
            'data' => ['items' => $page->items],
            'meta' => [
                'request_id' => $this->executionContext()->requestId(),
                'page' => $page->page, 'page_size' => $page->pageSize, 'total' => $page->total,
            ],
        ]);
    }
}
