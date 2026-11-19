<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Carries installed menu customization, permission bindings and jobs into the canonical Identity schema. */
final class AdoptCanonicalIdentityCatalogs extends Migrator
{
    private const PRESENTATION_FIELDS = [
        'name' => 'name', 'icon' => 'icon', 'sort' => 'sort_order', 'paths' => 'route_path',
        'is_show' => 'is_show', 'is_cache' => 'is_cache', 'is_disable' => 'is_disable',
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('Identity catalog migration requires native PDO.');
        }
        $pdo = $adapter->getConnection();
        $legacyMenus = $adapter->hasTable('pa_menu_definition');
        $legacyApi = $adapter->hasTable('pa_api_endpoint');
        $legacyJobs = $adapter->hasTable('pa_jobs');

        if (($legacyMenus && $adapter->hasTable('pa_system_menu_legacy'))
            || ($legacyApi && $adapter->hasTable('pa_system_api'))
            || ($legacyJobs && $adapter->hasTable('pa_job'))) {
            throw new RuntimeException('Legacy and canonical Identity catalogs exist simultaneously. Reconcile their records before migration.');
        }
        if ($legacyJobs && $adapter->hasColumn('pa_jobs', 'is_disable')
            && $pdo->query('SELECT id FROM pa_jobs WHERE status NOT IN (0,1) OR is_disable <> 1 - status LIMIT 1')->fetchColumn() !== false) {
            throw new RuntimeException('Legacy job status and disabled flag disagree. Resolve the existing job state before migration.');
        }
        $presentations = $legacyMenus ? $this->legacyMenuPresentations($pdo) : [];

        if ($legacyMenus) {
            if (!$adapter->hasColumn('pa_menu_definition', 'source')) {
                $this->execute(<<<'SQL'
ALTER TABLE pa_menu_definition
  ADD COLUMN source varchar(16) NOT NULL DEFAULT 'module' AFTER module_key,
  ADD CONSTRAINT chk_menu_source CHECK (source IN ('system','module','custom')),
  DROP CHECK chk_menu_type,
  ADD CONSTRAINT chk_system_menu_type CHECK (type IN ('group','page','link','action'))
SQL);
            }
            $this->updateMenus($pdo, $presentations);
            // Swap atomically so the split menu catalogs can never be left with neither canonical name.
            $this->execute('RENAME TABLE pa_system_menu TO pa_system_menu_legacy, pa_menu_definition TO pa_system_menu');
        }
        if ($legacyApi) {
            // MySQL updates the existing pa_permission_api foreign key without changing its IDs or bindings.
            $this->execute('RENAME TABLE pa_api_endpoint TO pa_system_api');
        }
        if ($adapter->hasTable('pa_permission_api_config')) {
            $this->adoptApiPermissionConfiguration($adapter, $pdo);
        }
        if ($legacyJobs) {
            $this->execute('RENAME TABLE pa_jobs TO pa_job');
        }
        // The retired job flag and department leader label remain available in upgraded databases for audit.
    }

    private function legacyMenuPresentations(PDO $pdo): array
    {
        $legacy = $pdo->query('SELECT * FROM pa_system_menu ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $canonical = $pdo->query('SELECT m.*, p.`key` AS required_permission FROM pa_menu_definition m
            LEFT JOIN pa_permission p ON p.id = m.required_permission_id')->fetchAll(PDO::FETCH_ASSOC);
        $byId = array_column($legacy, 'menu_key', 'id');
        $byKey = array_column($canonical, null, 'key');
        $permissions = $pdo->query('SELECT `key`, id FROM pa_permission')->fetchAll(PDO::FETCH_KEY_PAIR);
        $presentations = [];
        foreach ($legacy as $row) {
            $key = $row['menu_key'];
            $menu = $byKey[$key] ?? null;
            if ($menu === null || $menu['scope'] !== 'tenant' || $menu['module_key'] !== $row['module_key']) {
                throw new RuntimeException('Legacy menu has no matching canonical tenant menu: ' . $key);
            }
            $baseline = $row['upstream_defaults_json'] === null ? []
                : json_decode($row['upstream_defaults_json'], true, 512, JSON_THROW_ON_ERROR);
            $parent = $row['parent_key'] ?? ($byId[$row['pid']] ?? null);
            $knownParent = array_key_exists('parent_key', $baseline)
                || (array_key_exists('pid', $baseline) && ((int) $baseline['pid'] === 0 || isset($byId[$baseline['pid']])));
            $previousParent = array_key_exists('parent_key', $baseline) ? $baseline['parent_key'] : ($byId[$baseline['pid'] ?? 0] ?? null);
            if (!$knownParent || $parent !== $previousParent) {
                $menu['parent_key'] = $parent;
            }
            foreach (self::PRESENTATION_FIELDS as $from => $to) {
                $value = $row[$from];
                $numeric = $from === 'sort' || str_starts_with($from, 'is_');
                if ($numeric) {
                    $value = (int) $value;
                }
                if (array_key_exists($from, $baseline) && $value === ($numeric ? (int) $baseline[$from] : $baseline[$from])) {
                    continue;
                }
                if ($from === 'paths' && $value === '' && $menu['route_path'] === null) {
                    continue;
                }
                $menu[$to] = $from === 'sort' ? -$value : $value;
            }
            if ($menu['icon'] !== null && mb_strlen($menu['icon'], 'UTF-8') > 64) {
                throw new RuntimeException('Legacy menu icon exceeds canonical length: ' . $key);
            }
            if ($menu['instance_permission_id'] === null && $row['perms'] !== ($menu['required_permission'] ?? '')
                && (!array_key_exists('perms', $baseline) || $row['perms'] !== $baseline['perms'])) {
                $permission = $permissions[$row['perms']] ?? null;
                if ($permission === null) {
                    throw new RuntimeException('Legacy menu has a permission override without a canonical permission: ' . $key);
                }
                $menu['instance_permission_id'] = $permission;
            }
            $presentations[] = $menu;
        }
        return $presentations;
    }

    private function updateMenus(PDO $pdo, array $presentations): void
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->exec("UPDATE pa_menu_definition SET source = 'system' WHERE module_key IN ('core','platform')");
            $update = $pdo->prepare(<<<'SQL'
UPDATE pa_menu_definition SET parent_key = :parent_key, name = :name, icon = :icon, sort_order = :sort_order,
       route_path = :route_path, is_show = :is_show, is_cache = :is_cache, is_disable = :is_disable,
       instance_permission_id = :instance_permission_id
 WHERE id = :id
SQL);
            $fields = array_fill_keys(['parent_key', ...array_values(self::PRESENTATION_FIELDS), 'instance_permission_id', 'id'], true);
            foreach ($presentations as $menu) {
                $update->execute(array_intersect_key($menu, $fields));
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function adoptApiPermissionConfiguration(PdoAdapter $adapter, PDO $pdo): void
    {
        if (!$adapter->hasColumn('pa_permission', 'api_configured')) {
            $this->execute('ALTER TABLE pa_permission ADD COLUMN revision bigint unsigned NOT NULL DEFAULT 1,
                ADD COLUMN api_configured tinyint unsigned NOT NULL DEFAULT 0');
        }
        // Legacy explicit configurations begin at revision 2. Revision 1 is the canonical default.
        $pdo->exec('UPDATE pa_permission p JOIN pa_permission_api_config c ON c.permission_id = p.id
            SET p.revision = c.revision, p.api_configured = c.configured,
                p.updated_at = GREATEST(p.updated_at,c.updated_at)
            WHERE p.revision < c.revision');
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Restore the paired pre-upgrade backup to restore the split Identity catalogs.');
    }
}
