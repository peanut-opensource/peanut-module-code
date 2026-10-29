<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialPaymentSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_payment_scene' => <<<'SQL'
CREATE TABLE `pa_payment_scene` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `terminal` tinyint unsigned NOT NULL COMMENT '终端：1小程序 2公众号 3H5 4PC 5iOS 6Android',
  `pay_way` tinyint unsigned NOT NULL COMMENT '支付渠道：2微信 3支付宝',
  `status` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '状态：0关闭 1开启',
  `is_default` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '是否默认：0否 1是',
  `create_time` int unsigned DEFAULT NULL,
  `update_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_terminal_pay_way` (`terminal`,`pay_way`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='充值终端支付场景';
SQL,
        'pa_payment_tenant_channel_grant' => <<<'SQL'
CREATE TABLE `pa_payment_tenant_channel_grant` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `provider` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_binding_id` bigint unsigned NOT NULL,
  `merchant_account_ref` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'non-secret merchant account reference',
  `merchant_group_ref` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'non-secret merchant group reference',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `revoked_at` int unsigned DEFAULT NULL,
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `active_key` tinyint unsigned GENERATED ALWAYS AS ((case when ((`status` = 1) and (`revoked_at` is null)) then 1 else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_grant_tenant_binding` (`tenant_id`,`provider`,`external_binding_id`),
  UNIQUE KEY `uk_payment_grant_tenant_provider_active` (`tenant_id`,`provider`,`active_key`),
  KEY `idx_payment_grant_binding_status` (`external_binding_id`,`status`,`id`),
  CONSTRAINT `fk_payment_grant_external_binding` FOREIGN KEY (`external_binding_id`) REFERENCES `pa_external_channel_binding` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payment_grant_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment-owned Tenant grant to use an external merchant channel';
SQL,
        'pa_recharge_order' => <<<'SQL'
CREATE TABLE `pa_recharge_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0' COMMENT '用户ID',
  `pay_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '支付状态：0未支付 1已支付',
  `order_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '充值金额',
  `order_terminal` tinyint(1) DEFAULT '1' COMMENT '支付终端',
  `refund_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已发起退款：0否 1是',
  `refund_transaction_id` varchar(255) DEFAULT NULL COMMENT '退款交易流水号',
  `delete_time` int unsigned DEFAULT NULL,
  `sn` varchar(64) NOT NULL COMMENT '充值订单编号',
  `pay_way` tinyint NOT NULL DEFAULT '2' COMMENT '支付方式：1余额 2微信 3支付宝',
  `pay_time` int unsigned DEFAULT NULL COMMENT '支付时间',
  `create_time` int unsigned DEFAULT NULL,
  `update_time` int unsigned DEFAULT NULL,
  `pay_sn` varchar(255) DEFAULT NULL COMMENT '支付请求编号',
  `transaction_id` varchar(128) DEFAULT NULL COMMENT '第三方交易流水号',
  `tenant_id` bigint unsigned NOT NULL,
  `payment_binding_id` bigint unsigned DEFAULT NULL COMMENT 'External channel binding used by this payment attempt',
  `payment_grant_id` bigint unsigned DEFAULT NULL COMMENT 'Payment Tenant channel grant used by this payment attempt',
  `payment_merchant_account_ref` varchar(191) NOT NULL DEFAULT '' COMMENT 'non-secret merchant account reference snapshot',
  `payment_merchant_group_ref` varchar(191) NOT NULL DEFAULT '' COMMENT 'non-secret merchant group reference snapshot',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  UNIQUE KEY `uk_recharge_order_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_pay_sn` (`pay_sn`),
  UNIQUE KEY `uk_transaction_id` (`transaction_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_pay_status` (`pay_status`),
  KEY `idx_refund_status` (`refund_status`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_recharge_order_tenant_member_time` (`tenant_id`,`user_id`,`create_time`,`id`),
  KEY `idx_recharge_order_payment_binding` (`tenant_id`,`payment_binding_id`,`id`),
  KEY `idx_recharge_order_payment_grant` (`tenant_id`,`payment_grant_id`,`id`),
  KEY `fk_recharge_order_payment_binding` (`payment_binding_id`),
  KEY `fk_recharge_order_payment_grant` (`payment_grant_id`),
  CONSTRAINT `fk_recharge_order_member` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_recharge_order_payment_binding` FOREIGN KEY (`payment_binding_id`) REFERENCES `pa_external_channel_binding` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_recharge_order_payment_grant` FOREIGN KEY (`payment_grant_id`) REFERENCES `pa_payment_tenant_channel_grant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_recharge_order_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='充值订单';
SQL,
        'pa_refund_record' => <<<'SQL'
CREATE TABLE `pa_refund_record` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_type` varchar(255) DEFAULT 'order',
  `order_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00',
  `refund_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00',
  `transaction_id` varchar(255) DEFAULT NULL,
  `refund_way` tinyint(1) NOT NULL DEFAULT '1',
  `refund_type` tinyint(1) NOT NULL DEFAULT '1',
  `refund_status` tinyint unsigned NOT NULL DEFAULT '0',
  `refund_msg` text,
  `create_time` int unsigned DEFAULT '0',
  `update_time` int DEFAULT NULL,
  `sn` varchar(32) NOT NULL DEFAULT '',
  `order_sn` varchar(64) NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `order_id` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  UNIQUE KEY `uk_refund_record_tenant_id` (`tenant_id`,`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_sn` (`order_sn`),
  KEY `idx_refund_status` (`refund_status`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_refund_record_tenant_order_amount` (`tenant_id`,`order_type`,`order_id`,`refund_amount`,`id`),
  KEY `idx_refund_record_tenant_status_time` (`tenant_id`,`refund_status`,`create_time`,`id`),
  KEY `fk_refund_record_member` (`tenant_id`,`user_id`),
  KEY `fk_refund_record_order` (`tenant_id`,`order_id`),
  CONSTRAINT `fk_refund_record_member` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_record_order` FOREIGN KEY (`tenant_id`, `order_id`) REFERENCES `pa_recharge_order` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_record_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='退款记录';
SQL,
        'pa_refund_log' => <<<'SQL'
CREATE TABLE `pa_refund_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sn` varchar(32) DEFAULT NULL,
  `record_id` int NOT NULL,
  `handle_id` int NOT NULL DEFAULT '0',
  `order_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00',
  `refund_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00',
  `refund_status` tinyint unsigned NOT NULL DEFAULT '0',
  `refund_msg` text,
  `create_time` int unsigned DEFAULT '0',
  `update_time` int DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refund_log_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_record_id` (`record_id`),
  KEY `idx_refund_status` (`refund_status`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_refund_log_tenant_record_time` (`tenant_id`,`record_id`,`create_time`,`id`),
  KEY `fk_refund_log_member` (`tenant_id`,`user_id`),
  CONSTRAINT `fk_refund_log_member` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_log_record` FOREIGN KEY (`tenant_id`, `record_id`) REFERENCES `pa_refund_record` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='退款日志';
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialPaymentSchema requires the native PDO adapter.');
        }
        $pdo = $adapter->getConnection();
        $pending = [];
        foreach (self::CREATE_SQL as $table => $definition) {
            if (!$adapter->hasTable($table)) {
                $pending[] = $definition;
                continue;
            }
            $statement = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
            $existing = $statement->fetch(PDO::FETCH_NUM);
            $statement->closeCursor();
            if (!is_array($existing) || !is_string($existing[1] ?? null)
                || $this->schemaDefinition($existing[1]) !== $this->schemaDefinition($definition)) {
                throw new RuntimeException('INITIAL_SCHEMA_MISMATCH: ' . $table);
            }
        }
        // Check existing definitions before creating missing tables in foreign-key order.
        foreach ($pending as $definition) {
            $this->execute($definition);
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Module data is retained. This initial schema migration cannot be rolled back.');
    }

    private function schemaDefinition(string $definition): string
    {
        // AUTO_INCREMENT is live row state rather than a schema difference.
        return (string) preg_replace('/ AUTO_INCREMENT=\d+(?= |$)/', '', rtrim(trim($definition), ';'));
    }
}
