<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Owns the global terminal/payment catalog formerly supplied by the application baseline. */
final class PaymentSceneDefaults extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
INSERT INTO `pa_payment_scene`
  (`terminal`,`pay_way`,`status`,`is_default`,`create_time`,`update_time`)
VALUES
  (1,2,1,1,0,0),
  (2,2,1,1,0,0),
  (2,3,0,0,0,0),
  (3,2,1,1,0,0),
  (3,3,0,0,0,0),
  (4,2,1,1,0,0),
  (4,3,0,0,0,0),
  (5,2,1,1,0,0),
  (5,3,0,0,0,0),
  (6,2,1,1,0,0),
  (6,3,0,0,0,0)
ON DUPLICATE KEY UPDATE `terminal` = VALUES(`terminal`);
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Payment scene defaults and configuration are retained.');
    }
}
