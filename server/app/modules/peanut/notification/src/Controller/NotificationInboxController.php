<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\ApiProblem;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationMessage;
use PeanutAdmin\Modules\Notification\Service\NotificationAdminApplicationService;
use think\response\Json;

/** @property-read NotificationAdminApplicationService $notifications 当前 App 中声明式解析的控制器依赖。 */
final class NotificationInboxController extends BaseAdminController
{
    protected string $notificationsClass = NotificationAdminApplicationService::class;

    public function index(): Json
    {
        try {
            $status = trim((string) $this->request->get('status', 'all'));
            $page = $this->positiveInteger($this->request->get('page', 1));
            $pageSize = $this->positiveInteger($this->request->get('page_size', 20));
            $result = $this->notifications->messages(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $status,
                $page,
                $pageSize,
            );
            return json([
                'data' => ['items' => array_map(
                    static fn(NotificationMessage $message): array => $message->toArray(),
                    $result['items'],
                )],
                'meta' => [
                    'request_id' => $this->executionContext()->requestId(),
                    'page' => $result['page'],
                    'page_size' => $result['page_size'],
                    'total' => $result['total'],
                ],
            ]);
        } catch (NotificationException $exception) {
            throw $this->problem($exception);
        }
    }

    public function markRead(string $messageKey): Json
    {
        try {
            $revision = $this->revisionHeader();
            $message = $this->notifications->markRead(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $messageKey,
                $revision,
            );
            return json([
                'data' => $message->toArray(),
                'meta' => ['request_id' => $this->executionContext()->requestId()],
            ]);
        } catch (NotificationException $exception) {
            throw $this->problem($exception);
        }
    }

    public function bulk(): Json
    {
        try {
            $keys = $this->request->post('message_keys', []);
            $action = trim((string) $this->request->post('action', ''));
            if (!is_array($keys) || !array_is_list($keys)) {
                throw NotificationException::invalid();
            }
            $changed = $this->notifications->bulk(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $keys,
                $action,
            );
            return json([
                'data' => ['changed' => $changed],
                'meta' => ['request_id' => $this->executionContext()->requestId()],
            ]);
        } catch (NotificationException $exception) {
            throw $this->problem($exception);
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            || (int) $value < 1
        ) {
            throw NotificationException::invalid();
        }
        return (int) $value;
    }

    private function revisionHeader(): int
    {
        $value = trim((string) $this->request->header('If-Match', ''));
        if (preg_match('/^"rev-([1-9][0-9]*)"$/D', $value, $matches) !== 1) {
            throw NotificationException::invalid();
        }
        return (int) $matches[1];
    }

    private function problem(NotificationException $exception): ApiProblem
    {
        $status = match ($exception->problemCode) {
            'NOTIFICATION_PERMISSION_DENIED' => 403,
            'NOTIFICATION_NOT_FOUND' => 404,
            'NOTIFICATION_STATE_CONFLICT' => 409,
            default => 422,
        };
        return new ApiProblem($exception->problemCode, $status, 'Notification inbox request was rejected.');
    }
}
