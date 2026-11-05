<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class RetainRechargePaymentAttempts extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS pa_recharge_payment_attempt (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    order_id int unsigned NOT NULL,
    pay_way tinyint NOT NULL,
    binding_id bigint unsigned NOT NULL,
    grant_id bigint unsigned NOT NULL,
    merchant_identity_hash char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    merchant_order_sn varchar(64) COLLATE utf8mb4_0900_ai_ci NOT NULL,
    amount_cents bigint unsigned NOT NULL,
    currency char(3) NOT NULL,
    expires_at int unsigned DEFAULT NULL,
    status varchar(16) NOT NULL,
    transaction_id varchar(128) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_recharge_attempt_trade (binding_id, merchant_order_sn),
    UNIQUE KEY uk_recharge_attempt_channel_transaction (pay_way, transaction_id),
    KEY idx_recharge_attempt_order (tenant_id, order_id, status),
    CONSTRAINT fk_recharge_attempt_order FOREIGN KEY (tenant_id, order_id) REFERENCES pa_recharge_order (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_recharge_attempt_binding FOREIGN KEY (binding_id) REFERENCES `pa_external_channel_binding` (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recharge_attempt_grant FOREIGN KEY (grant_id) REFERENCES pa_payment_tenant_channel_grant (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        // The old gateway sent recharge_order.sn, not its locally generated pay_sn.
        // Keep the recorded merchant snapshot. Runtime resolves and checks it against Integration before use.
        $this->execute(<<<'SQL'
INSERT INTO pa_recharge_payment_attempt (
    tenant_id, order_id, pay_way, binding_id, grant_id, merchant_identity_hash,
    merchant_order_sn, amount_cents, currency, status, transaction_id
)
SELECT o.tenant_id, o.id, o.pay_way, o.payment_binding_id, g.id, o.payment_merchant_account_ref,
    o.sn, o.order_amount * 100, 'CNY', IF(o.pay_status = 1, 'paid', 'open'), NULLIF(o.transaction_id, '')
FROM pa_recharge_order o
JOIN pa_payment_tenant_channel_grant g
    ON g.id = o.payment_grant_id AND g.tenant_id = o.tenant_id AND g.external_binding_id = o.payment_binding_id
WHERE o.payment_merchant_account_ref REGEXP '^[a-f0-9]{64}$'
    AND ((o.pay_way = 2 AND g.provider = 'payment.wechat') OR (o.pay_way = 3 AND g.provider = 'payment.alipay'))
    AND (o.pay_status = 0 OR (o.pay_status = 1 AND NULLIF(o.transaction_id, '') IS NOT NULL))
    AND NOT EXISTS (SELECT 1 FROM pa_recharge_payment_attempt a WHERE a.tenant_id = o.tenant_id AND a.order_id = o.id)
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Payment attempt history is retained.');
    }
}
