<?php

declare(strict_types=1);

namespace app\platform\infrastructure\provider;

use app\platform\value\provider\ProviderQualificationSubject;

final class NotificationQualificationContributor extends AbstractTenantBindingQualificationContributor
{
    protected function definitions(): array
    {
        return [
            ['provider_key' => 'notification.sms.aliyun', 'binding_provider' => 'notice.sms',
                'category' => 'notification', 'callback_required' => false],
            ['provider_key' => 'notification.sms.tencent', 'binding_provider' => 'notice.sms',
                'category' => 'notification', 'callback_required' => false],
        ];
    }

    public function subjects(): array
    {
        $subjects = parent::subjects();
        $tenantIds = [];
        foreach ($subjects as $subject) {
            $tenantIds[$subject->tenantId] = true;
        }
        foreach (array_keys($tenantIds) as $tenantId) {
            $providerKey = 'notification.email';
            $subjects[] = new ProviderQualificationSubject(
                $providerKey,
                'notification',
                'tenant',
                (int) $tenantId,
                $providerKey,
                false,
                false,
                null,
                hash('sha256', 'not-implemented:' . $tenantId . ':' . $providerKey),
                false,
            );
        }
        return $subjects;
    }
}
