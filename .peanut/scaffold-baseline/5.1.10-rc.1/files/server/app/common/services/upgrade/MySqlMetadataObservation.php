<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use PDO;
use RuntimeException;

/** Technical observations only; owners retain transactions, tables and fixed semantic assertions. */
final class MySqlMetadataObservation
{
    public static function uncached(PDO $pdo, callable $read): mixed
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('MYSQL_METADATA_DRIVER_UNSUPPORTED');
        }
        $original = $pdo->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchColumn();
        if ((!is_int($original) && !is_string($original))
            || preg_match('/\A[0-9]{1,10}\z/D', (string) $original) !== 1
            || (int) $original > 4294967295) {
            throw new RuntimeException('MYSQL_METADATA_STATS_STATE_UNKNOWN');
        }
        try {
            $pdo->exec('SET SESSION information_schema_stats_expiry=0');
            return $read();
        } finally {
            $pdo->exec('SET SESSION information_schema_stats_expiry=' . (string) $original);
        }
    }

    /** Complete literal tokens only; preserve grouping and literal bytes for each owner's closed forms. */
    public static function checkTokens(string $sql): ?array
    {
        $pattern = <<<'RX'
~\G(?:\s+|(?<identifier>`[a-z_][a-z0-9_]*`|"[a-z_][a-z0-9_]*")|(?:_(?:utf8mb4|ascii))?(?:\\'(?<metadata_literal>(?:[^'\\]|\\\\\\\\\.)*)\\'|(?<literal>'(?:''|[^'])*'))|(?<word>[a-z_][a-z0-9_]*|[0-9]+|<>|!=|>=|<=|[(),=<>+*%-]))~Ai
RX;
        $tokens = []; $offset = 0;
        while ($offset < strlen($sql)) {
            if (preg_match($pattern, $sql, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) { return null; }
            $offset += strlen($match[0]);
            if (trim($match[0]) === '') { continue; }
            $literal = $match['metadata_literal'];
            if ($literal !== null) {
                // Native CHECK metadata doubles the fixed SQL escaped-dot bytes.
                $literal = str_replace('\\\\\\\\.', '\\\\.', $literal);
            }
            $tokens[] = $literal !== null ? "'" . $literal . "'"
                : ($match['literal'] ?? strtolower($match['identifier'] !== null
                    ? substr($match['identifier'], 1, -1) : $match['word']));
        }
        return $tokens;
    }

    /** Metadata only: the caller owns the fixed source hash, table set and data-preservation assertions. */
    public static function tableSchema(PDO $pdo, string $table): ?array
    {
        if (preg_match('/\Apa_[a-z0-9_]+\z/D', $table) !== 1) { throw new RuntimeException('MYSQL_METADATA_TABLE_UNKNOWN'); }
        return self::uncached($pdo, static function () use ($pdo, $table): ?array {
            $metadata = self::rows($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,ROW_FORMAT,CREATE_OPTIONS,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if ($metadata === []) { return null; }
            if (count($metadata) !== 1 || $metadata[0]['TABLE_TYPE'] !== 'BASE TABLE' || $metadata[0]['ENGINE'] !== 'InnoDB') { throw new RuntimeException('MYSQL_METADATA_TABLE_UNKNOWN'); }
            $ddl = self::rows($pdo, 'SHOW CREATE TABLE `' . $table . '`');
            if (count($ddl) !== 1 || !is_string($ddl[0]['Create Table'] ?? null)) { throw new RuntimeException('MYSQL_METADATA_DDL_UNKNOWN'); }
            $columns = self::rows($pdo, 'SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
            $hasAllocator = false;
            foreach ($columns as $column) {
                if ($column['GENERATION_EXPRESSION'] !== '' || !in_array($column['EXTRA'], ['', 'auto_increment'], true)) { throw new RuntimeException('MYSQL_METADATA_COLUMN_UNKNOWN'); }
                $hasAllocator = $hasAllocator || $column['EXTRA'] === 'auto_increment';
            }
            // SHOW CREATE opens the table before the uncached allocator observation; NULL is never normalized to 1.
            $counter = self::rows($pdo, 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (count($counter) !== 1 || ($hasAllocator
                ? preg_match('/\A[1-9][0-9]*\z/D', (string) $counter[0]['AUTO_INCREMENT']) !== 1
                : $counter[0]['AUTO_INCREMENT'] !== null)) { throw new RuntimeException('MYSQL_METADATA_ALLOCATOR_UNKNOWN'); }
            $checks = self::rows($pdo, "SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK' ORDER BY t.CONSTRAINT_NAME", [$table]);
            foreach ($checks as &$check) {
                if ($check['ENFORCED'] !== 'YES') { throw new RuntimeException('MYSQL_METADATA_CHECK_NOT_ENFORCED'); }
                $check['CHECK_CLAUSE'] = self::checkExpression($check['CHECK_CLAUSE']);
            } unset($check);
            return ['metadata' => $metadata[0], 'ddl' => $ddl[0]['Create Table'], 'auto_increment' => $counter[0]['AUTO_INCREMENT'],
                'columns' => array_column($columns, null, 'COLUMN_NAME'), 'checks' => $checks,
                'indexes' => self::rows($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]),
                'foreign_keys' => self::rows($pdo, 'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$table]),
                'triggers' => self::rows($pdo, 'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT,SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$table])];
        });
    }

    /** Closed declarations of the pinned ArtifactRevision/receipt CREATE sources; never executes SQL. */
    public static function createTableSchema(string $fixedSql, string $table, string $database): array
    {
        if (preg_match('/\Apa_[a-z0-9_]+\z/D', $table) !== 1 || preg_match('/\A[a-z0-9_]+\z/D', $database) !== 1
            || preg_match_all('/CREATE TABLE IF NOT EXISTS `' . $table . '` \((.*?)\) ENGINE=InnoDB DEFAULT (?:CHARSET|CHARACTER SET)=utf8mb4 COLLATE=(utf8mb4_(?:bin|0900_ai_ci));/s', $fixedSql, $matches, PREG_SET_ORDER) !== 1) {
            throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN');
        }
        $definition = $matches[0]; $collation = $definition[2];
        $schema = ['metadata' => ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => $collation,
            'ROW_FORMAT' => 'Dynamic', 'CREATE_OPTIONS' => '', 'TABLE_COMMENT' => ''], 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => []];
        $indexes = []; $foreign = []; $anonymous = 0;
        foreach (self::declarations($definition[1]) as $line) {
            if (preg_match('/\A`([a-z0-9_]+)` (BIGINT UNSIGNED|INT UNSIGNED|SMALLINT UNSIGNED|TINYINT UNSIGNED|CHAR\([0-9]+\)|VARCHAR\([0-9]+\)|DATETIME\(3\)|JSON|LONGTEXT|TEXT)(?: CHARACTER SET (ascii))?(?: COLLATE (ascii_bin))? (NOT NULL|NULL)(?: DEFAULT ([0-9]+|\x27[a-z_]+\x27))?( AUTO_INCREMENT)?\z/D', $line, $m)) {
                if (isset($schema['columns'][$m[1]])) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
                $text = str_starts_with($m[2], 'CHAR(') || str_starts_with($m[2], 'VARCHAR(') || in_array($m[2], ['LONGTEXT', 'TEXT'], true);
                $charset = $text ? (($m[3] ?? '') !== '' ? $m[3] : 'utf8mb4') : null;
                $default = isset($m[6]) && $m[6] !== '' ? trim($m[6], "'") : null;
                $schema['columns'][$m[1]] = ['COLUMN_NAME' => $m[1], 'ORDINAL_POSITION' => (string) (count($schema['columns']) + 1),
                    'COLUMN_TYPE' => strtolower($m[2]), 'IS_NULLABLE' => $m[5] === 'NOT NULL' ? 'NO' : 'YES', 'COLUMN_DEFAULT' => $default,
                    'EXTRA' => ($m[7] ?? '') !== '' ? 'auto_increment' : '', 'CHARACTER_SET_NAME' => $charset,
                    'COLLATION_NAME' => $text ? (($m[4] ?? '') !== '' ? $m[4] : $collation) : null,
                    'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
                continue;
            }
            if (preg_match('/\A(PRIMARY KEY|UNIQUE KEY `([a-z0-9_]+)`|KEY `([a-z0-9_]+)`) \(([^)]+)\)\z/D', $line, $m)) {
                $name = $m[1] === 'PRIMARY KEY' ? 'PRIMARY' : (($m[2] ?? '') !== '' ? $m[2] : $m[3]);
                if (isset($indexes[$name])) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
                $indexes[$name] = ['unique' => !str_starts_with($m[1], 'KEY '), 'columns' => self::columnNames($m[4])]; continue;
            }
            if (preg_match('/\ACONSTRAINT `([a-z0-9_]+)` FOREIGN KEY \(([^)]+)\) REFERENCES `(pa_[a-z0-9_]+)` \(([^)]+)\)( ON DELETE RESTRICT)?\z/D', $line, $m)) {
                if (isset($foreign[$m[1]])) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
                $foreign[$m[1]] = ['columns' => self::columnNames($m[2]), 'table' => $m[3], 'references' => self::columnNames($m[4]),
                    'delete' => ($m[5] ?? '') !== '' ? 'RESTRICT' : 'NO ACTION']; continue;
            }
            if (preg_match('/\A(?:CONSTRAINT `([a-z0-9_]+)` )?CHECK \((.*)\)\z/Ds', $line, $m)) {
                $name = ($m[1] ?? '') !== '' ? $m[1] : $table . '_chk_' . ++$anonymous;
                foreach ($schema['checks'] as $check) { if ($check['CONSTRAINT_NAME'] === $name) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); } }
                $schema['checks'][] = ['CONSTRAINT_NAME' => $name, 'CHECK_CLAUSE' => self::checkExpression($m[2]), 'ENFORCED' => 'YES']; continue;
            }
            throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN');
        }
        foreach ($foreign as $name => $relation) {
            $supported = false;
            foreach ($indexes as $index) { $supported = $supported || array_slice($index['columns'], 0, count($relation['columns'])) === $relation['columns']; }
            if (!$supported) {
                if (isset($indexes[$name])) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
                $indexes[$name] = ['unique' => false, 'columns' => $relation['columns']];
            }
            if (count($relation['columns']) !== count($relation['references'])) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
            foreach ($relation['columns'] as $ordinal => $column) {
                $schema['foreign_keys'][] = ['CONSTRAINT_NAME' => $name, 'ORDINAL_POSITION' => (string) ($ordinal + 1), 'COLUMN_NAME' => $column,
                    'REFERENCED_TABLE_SCHEMA' => $database, 'REFERENCED_TABLE_NAME' => $relation['table'], 'REFERENCED_COLUMN_NAME' => $relation['references'][$ordinal],
                    'UPDATE_RULE' => 'NO ACTION', 'DELETE_RULE' => $relation['delete']];
            }
        }
        foreach ($indexes as $name => $index) {
            foreach ($index['columns'] as $ordinal => $column) {
                if (!isset($schema['columns'][$column])) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
                $schema['indexes'][] = ['INDEX_NAME' => $name, 'NON_UNIQUE' => $index['unique'] ? '0' : '1', 'SEQ_IN_INDEX' => (string) ($ordinal + 1),
                    'COLUMN_NAME' => $column, 'COLLATION' => 'A', 'SUB_PART' => null, 'INDEX_TYPE' => 'BTREE', 'IS_VISIBLE' => 'YES', 'EXPRESSION' => null, 'INDEX_COMMENT' => ''];
            }
        }
        usort($schema['indexes'], static fn(array $a, array $b): int => strcasecmp($a['INDEX_NAME'], $b['INDEX_NAME']) ?: (int) $a['SEQ_IN_INDEX'] <=> (int) $b['SEQ_IN_INDEX']);
        usort($schema['foreign_keys'], static fn(array $a, array $b): int => strcmp($a['CONSTRAINT_NAME'], $b['CONSTRAINT_NAME']) ?: (int) $a['ORDINAL_POSITION'] <=> (int) $b['ORDINAL_POSITION']);
        usort($schema['checks'], static fn(array $a, array $b): int => strcmp($a['CONSTRAINT_NAME'], $b['CONSTRAINT_NAME']));
        return $schema;
    }

    private static function columnNames(string $list): array
    {
        $columns = [];
        foreach (explode(',', $list) as $column) {
            if (preg_match('/\A`([a-z0-9_]+)`\z/D', trim($column), $m) !== 1) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
            $columns[] = $m[1];
        }
        if ($columns === [] || count($columns) !== count(array_unique($columns))) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
        return $columns;
    }

    private static function declarations(string $body): array
    {
        $items = []; $start = 0; $depth = 0; $quote = null;
        for ($i = 0, $size = strlen($body); $i < $size; ++$i) {
            $char = $body[$i];
            if ($quote !== null) {
                if ($char === '\\') { ++$i; }
                elseif ($char === $quote) { if (($body[$i + 1] ?? null) === $quote) { ++$i; } else { $quote = null; } }
            } elseif ($char === "'" || $char === '`') { $quote = $char; }
            elseif ($char === '(') { ++$depth; }
            elseif ($char === ')') { if (--$depth < 0) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); } }
            elseif ($char === ',' && $depth === 0) { $items[] = trim(substr($body, $start, $i - $start)); $start = $i + 1; }
        }
        if ($quote !== null || $depth !== 0) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
        $items[] = trim(substr($body, $start));
        if (in_array('', $items, true)) { throw new RuntimeException('MYSQL_METADATA_CREATE_SOURCE_UNKNOWN'); }
        return $items;
    }

    private static function rows(PDO $pdo, string $sql, array $arguments = []): array
    {
        $statement = $pdo->prepare($sql);
        try {
            $statement->execute($arguments);
            return array_map(static fn(array $row): array => array_map(static fn(mixed $value): ?string => $value === null ? null : (string) $value, $row), $statement->fetchAll(PDO::FETCH_ASSOC));
        } finally { $statement->closeCursor(); }
    }

    /** Closed boolean forms used by fixed CREATE owners; unknown operators fail closed. */
    public static function checkExpression(string $sql): array
    {
        $tokens = self::checkTokens($sql);
        if ($tokens === null) { throw new RuntimeException('MYSQL_METADATA_CHECK_UNKNOWN'); }
        $at = 0; $or = $and = $term = $value = null;
        $fail = static function (): never { throw new RuntimeException('MYSQL_METADATA_CHECK_UNKNOWN'); };
        $value = static function () use (&$at, $tokens, &$or, &$value, $fail): array {
            $token = $tokens[$at++] ?? null;
            if ($token === '(') { $node = $or(); if (($tokens[$at++] ?? null) !== ')') { $fail(); } return $node; }
            if (is_string($token) && preg_match('/\A[0-9]+\z/D', $token)) { return ['number', $token]; }
            if (is_string($token) && str_starts_with($token, "'")) { return ['literal', $token]; }
            if (!is_string($token) || preg_match('/\A[a-z_][a-z0-9_]*\z/D', $token) !== 1) { $fail(); }
            if (($tokens[$at] ?? null) !== '(') { return ['column', $token]; }
            if ($token !== 'regexp_like') { $fail(); }
            ++$at; $left = $value(); if (($tokens[$at++] ?? null) !== ',') { $fail(); }
            $pattern = $value(); if (($tokens[$at++] ?? null) !== ')') { $fail(); }
            return ['regexp', $left, $pattern];
        };
        $term = static function () use (&$at, $tokens, &$value, $fail): array {
            $left = $value(); $operator = $tokens[$at] ?? null;
            if (in_array($operator, ['=', '<>', '!=', '>', '>=', '<', '<=', 'regexp'], true)) {
                ++$at; return [$operator === '!=' ? '<>' : $operator, $left, $value()];
            }
            if ($operator === 'is') {
                ++$at; $not = ($tokens[$at] ?? null) === 'not'; if ($not) { ++$at; }
                if (($tokens[$at++] ?? null) !== 'null') { $fail(); }
                return [$not ? 'is not null' : 'is null', $left];
            }
            if ($operator === 'in') {
                $at += 2; if (($tokens[$at - 1] ?? null) !== '(') { $fail(); }
                $items = [$value()]; while (($tokens[$at] ?? null) === ',') { ++$at; $items[] = $value(); }
                if (($tokens[$at++] ?? null) !== ')') { $fail(); } return ['in', $left, $items];
            }
            return $left;
        };
        $combine = static function (string $operator, callable $next) use (&$at, $tokens): array {
            $nodes = [$next()]; while (($tokens[$at] ?? null) === $operator) { ++$at; $nodes[] = $next(); }
            $flat = []; foreach ($nodes as $node) { if (($node[0] ?? null) === $operator) { array_push($flat, ...array_slice($node, 1)); } else { $flat[] = $node; } }
            return count($flat) === 1 ? $flat[0] : [$operator, ...$flat];
        };
        $and = static fn(): array => $combine('and', $term); $or = static fn(): array => $combine('or', $and);
        $tree = $or(); if ($at !== count($tokens)) { $fail(); } return $tree;
    }
}
