<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/app/modules/peanut/notification/database/migrations/20261102000001_initial_notification_schema.php';

// Native migration definition comparison only, not a live preservation proof.
$migration = (new ReflectionClass(InitialNotificationSchema::class))->newInstanceWithoutConstructor();
$reflection = new ReflectionClass($migration);
$sql = $reflection->getConstant('CREATE_SQL')['pa_notice_log'];
$normalize = $reflection->getMethod('schemaDefinition');
$expected = $normalize->invoke($migration, $sql);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
foreach ([
    '`reservation_key` char(37) CHARACTER SET ascii COLLATE ascii_bin',
    '`idempotency_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin',
    'UNIQUE KEY `uk_notice_sms_reservation_key` (`reservation_key`)',
    'UNIQUE KEY `uk_notice_sms_idempotency` (`tenant_id`,`idempotency_key_hash`)',
    'UNIQUE KEY `uk_notice_sms_active_receiver` (`tenant_id`,`channel`,`receiver_hash`,`reservation_active`)',
    'KEY `idx_notice_sms_reservation_expiry` (`tenant_id`,`reservation_active`,`reservation_until`,`id`)',
    'CONSTRAINT `chk_notice_sms_reservation_active` CHECK',
    '(`status` in (0,1,3))',
] as $marker) {
    $expect(str_contains($sql, $marker), 'SMS reservation schema invariant missing: ' . $marker);
}
$expect($normalize->invoke($migration, str_replace('ENGINE=InnoDB', 'ENGINE=InnoDB AUTO_INCREMENT=99', $sql)) === $expected, 'Live sequence value is not a schema difference');
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
    $expect($count > 0 && $normalize->invoke($migration, $changed) !== $expected, 'Native schema comparison accepted structural drift: ' . $before);
}
$source = (string) file_get_contents($reflection->getFileName());
$expect(str_contains($source, "throw new RuntimeException('INITIAL_SCHEMA_MISMATCH: '"), 'Existing schema drift must fail closed');
$expect(strpos($source, '$this->schemaDefinition($existing[1])') < strpos($source, '$this->execute($definition)'), 'Existing schemas must be checked before creating missing tables');
echo "SMS native schema contract: reservation invariants and 9 structural drifts checked; no database executed.\n";
