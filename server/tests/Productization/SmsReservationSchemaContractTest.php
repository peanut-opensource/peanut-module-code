<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/app/modules/peanut/notification/database/migrations/20261102000001_initial_notification_schema.php';

// Initial SQL invariants only; this migration preserves existing tables without comparing their schema.
$migration = (new ReflectionClass(InitialNotificationSchema::class))->newInstanceWithoutConstructor();
$reflection = new ReflectionClass($migration);
$sql = $reflection->getConstant('CREATE_SQL')['pa_notice_log'];
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$required = [
    '`reservation_key` char(37) CHARACTER SET ascii COLLATE ascii_bin',
    '`idempotency_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin',
    'UNIQUE KEY `uk_notice_sms_reservation_key` (`reservation_key`)',
    'UNIQUE KEY `uk_notice_sms_idempotency` (`tenant_id`,`idempotency_key_hash`)',
    'UNIQUE KEY `uk_notice_sms_active_receiver` (`tenant_id`,`channel`,`receiver_hash`,`reservation_active`)',
    'KEY `idx_notice_sms_reservation_expiry` (`tenant_id`,`reservation_active`,`reservation_until`,`id`)',
    'CONSTRAINT `chk_notice_sms_reservation_active` CHECK',
    '(`status` in (0,1,3))',
    '`reservation_until` int unsigned',
    '`reservation_active` tinyint unsigned',
    'PRIMARY KEY (`id`)',
    'ENGINE=InnoDB',
];
$hasInvariants = static function (string $definition) use ($required): bool {
    foreach ($required as $marker) {
        if (!str_contains($definition, $marker)) return false;
    }
    return true;
};
foreach ($required as $marker) {
    $expect(str_contains($sql, $marker), 'SMS reservation schema invariant missing: ' . $marker);
}
$expect($hasInvariants($sql), 'Initial reservation schema must satisfy its declared invariants');
foreach ([
    ['char(37)', 'char(36)'],
    ['ascii_bin', 'ascii_general_ci'],
    ['UNIQUE KEY `uk_notice_sms_idempotency`', 'KEY `uk_notice_sms_idempotency`'],
    ['(`tenant_id`,`idempotency_key_hash`)', '(`idempotency_key_hash`,`tenant_id`)'],
    ['`reservation_until` int unsigned', '`reservation_until` bigint unsigned'],
    ['`reservation_active` tinyint unsigned', '`reservation_active` int unsigned'],
    ['(`status` in (0,1,3))', '(`status` in (0,1,2,3))'],
    ['PRIMARY KEY (`id`)', 'UNIQUE KEY `id` (`id`)'],
    ['ENGINE=InnoDB', 'ENGINE=MyISAM'],
] as [$before, $after]) {
    $changed = str_replace($before, $after, $sql, $count);
    $expect($count > 0 && !$hasInvariants($changed), 'Initial SQL invariant accepted a changed definition: ' . $before);
}
$source = (string) file_get_contents($reflection->getFileName());
$expect(str_contains($source, 'if (!$adapter->hasTable($table))'), 'Existing tables must be preserved');
$expect(str_contains($source, '$this->execute($definition)'), 'Missing tables must be created from the initial SQL');
echo "SMS initial schema contract: reservation invariants and 9 changed definitions checked; no database or existing-schema comparison executed.\n";
