#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

// Honor an already selected test autoloader; parse, never execute, audited sources.
if (!class_exists(ParserFactory::class)) {
    require_once dirname(__DIR__) . '/tools/quality/vendor/autoload.php';
}

/** @return array{Node,list<Node\Expr\MethodCall>} */
function moduleBoundaryQueryChain(Node $node): array
{
    $calls = [];
    while ($node instanceof Node\Expr\MethodCall) {
        $calls[] = $node;
        $node = $node->var;
    }
    return [$node, $calls];
}

function moduleBoundaryLiteral(Node $node, array $constants = []): ?string
{
    if ($node instanceof Node\Scalar\String_) {
        return $node->value;
    }
    if ($node instanceof Node\Scalar\Int_) {
        return (string) $node->value;
    }
    if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
        return $constants[$node->class->toString() . '::' . $node->name->toString()] ?? null;
    }
    if ($node instanceof Node\Expr\BinaryOp\Concat) {
        $left = moduleBoundaryLiteral($node->left, $constants);
        $right = moduleBoundaryLiteral($node->right, $constants);
        return $left === null || $right === null ? null : $left . $right;
    }
    return null;
}

/** 静态只承认闭合的私有Query工厂；运行时授权/复杂关系需独立行为验证，不用文件名豁免。 */
function moduleBoundaryReadAssociations(array $nodes, string $owner, array $modules, array $namespaces, array $tables): array
{
    $finder = new \PhpParser\NodeFinder();
    $approved = [];
    $parser = (new ParserFactory())->createForHostVersion();
    $declarations = static function (string $class) use ($owner, $modules, $namespaces, $parser, $finder): array {
        foreach ($namespaces as $prefix => $moduleOwner) {
            if ($moduleOwner !== $owner || !str_starts_with($class, $prefix)) {
                continue;
            }
            foreach ($modules[$owner]['sources'] ?? [] as $source) {
                $file = $source . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (!is_file($file) || is_link($file)) {
                    continue;
                }
                $tree = $parser->parse((string) file_get_contents($file)) ?? [];
                $resolver = new NodeTraverser();
                $resolver->addVisitor(new NameResolver());
                return array_values(array_filter(
                    $finder->findInstanceOf($resolver->traverse($tree), Node\Stmt\Class_::class),
                    static fn($item): bool => isset($item->namespacedName) && $item->namespacedName->toString() === $class,
                ));
            }
        }
        return [];
    };
    $isScopedModel = static function (string $class) use ($declarations): bool {
        foreach ($declarations($class) as $item) {
            if ($item->extends?->toString() === 'app\\common\\model\\TenantOwnedModel') {
                return true;
            }
        }
        return false;
    };
    $constants = [];
    foreach ($finder->findInstanceOf($nodes, Node\Expr\ClassConstFetch::class) as $fetch) {
        if (!$fetch->class instanceof Node\Name || !$fetch->name instanceof Node\Identifier) {
            continue;
        }
        foreach ($declarations($fetch->class->toString()) as $declaration) {
            foreach ($declaration->getConstants() as $statement) {
                foreach ($statement->consts as $constant) {
                    $value = moduleBoundaryLiteral($constant->value);
                    if ($value !== null) {
                        $constants[$fetch->class->toString() . '::' . $constant->name->toString()] = $value;
                    }
                }
            }
        }
    }
    $builders = ['alias','where','whereor','wherein','wherenotin','wherebetween','wherenull','wherenotnull','join','leftjoin','rightjoin','field','fieldraw','order','orderraw','group','page','limit'];
    $reads = ['count','select','find','findorempty','paginate','sum','column','value','toarray'];
    foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
        foreach ($class->getMethods() as $factory) {
            if (!$factory->isPrivate() || !$factory->isStatic() || !$factory->returnType instanceof Node\Name
                || $factory->returnType->toString() !== 'think\\db\\Query') {
                continue;
            }
            $methodName = $factory->name->toString();
            $queryName = null;
            $alias = null;
            $joins = [];
            $valid = true;
            foreach ($finder->findInstanceOf($factory->stmts ?? [], Node\Expr\Assign::class) as $assign) {
                if (!$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)) {
                    continue;
                }
                [$root, $chain] = moduleBoundaryQueryChain($assign->expr);
                if (!$root instanceof Node\Expr\StaticCall || !$root->class instanceof Node\Name
                    || !$root->name instanceof Node\Identifier || strtolower($root->name->toString()) !== 'alias'
                    || !$isScopedModel($root->class->toString())) {
                    continue;
                }
                if ($queryName !== null) {
                    $valid = false;
                    break;
                }
                $queryName = $assign->var->name;
                $alias = isset($root->args[0]) ? moduleBoundaryLiteral($root->args[0]->value) : null;
            }
            if (!$valid || $queryName === null || $alias === null || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias) !== 1) {
                continue;
            }
            $returns = $finder->findInstanceOf($factory->stmts ?? [], Node\Stmt\Return_::class);
            if (count($returns) !== 1 || !$returns[0]->expr instanceof Node\Expr\Variable || $returns[0]->expr->name !== $queryName) {
                continue;
            }
            foreach ($finder->findInstanceOf($factory->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
                if (!$call->name instanceof Node\Identifier) {
                    $valid = false;
                    break;
                }
                $name = strtolower($call->name->toString());
                if (!in_array($name, $builders, true)) {
                    $valid = false;
                    break;
                }
                if (!in_array($name, ['join','leftjoin','rightjoin'], true)) {
                    continue;
                }
                $table = isset($call->args[0]) ? moduleBoundaryLiteral($call->args[0]->value) : null;
                $condition = isset($call->args[1]) ? moduleBoundaryLiteral($call->args[1]->value) : null;
                if ($table === null || $condition === null || preg_match('/^(?:pa_)?([a-z][a-z0-9_]*)\s+([a-z][a-z0-9_]*)$/iD', $table, $parts) !== 1) {
                    $valid = false;
                    break;
                }
                $logical = $parts[1];
                $joined = $parts[2];
                $targetOwner = $tables[$logical] ?? null;
                if ($targetOwner === null || $targetOwner === $owner) {
                    continue;
                }
                $normalized = str_replace(['`', ' '], '', $condition);
                $tenantPairs = [strtolower($joined . '.tenant_id=' . $alias . '.tenant_id'), strtolower($alias . '.tenant_id=' . $joined . '.tenant_id')];
                $terms = preg_split('/\bAND\b/i', $condition) ?: [];
                $hasTenant = false;
                foreach ($terms as $term) {
                    $hasTenant = $hasTenant || in_array(strtolower(str_replace(['`',' '], '', trim($term))), $tenantPairs, true);
                }
                if (!$hasTenant || preg_match('/\bOR\b|;|--|\/\*/i', $condition)) {
                    $valid = false;
                    break;
                }
                $joins[$call->getStartFilePos()] = ['target_owner' => $targetOwner, 'target' => $logical, 'alias' => $joined];
            }
            if (!$valid || $joins === []) {
                continue;
            }
            $factoryFields = [];
            foreach ($finder->findInstanceOf($factory->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
                if (!$call->name instanceof Node\Identifier || !in_array(strtolower($call->name->toString()), ['field','fieldraw'], true)) {
                    continue;
                }
                $field = isset($call->args[0]) ? moduleBoundaryLiteral($call->args[0]->value, $constants) : null;
                if ($field === null || trim($field) === '*') {
                    $valid = false;
                    break;
                }
                $factoryFields[] = $field;
            }
            if (!$valid) {
                continue;
            }
            $isFactoryRoot = static fn(Node $root): bool => $root instanceof Node\Expr\StaticCall && $root->class instanceof Node\Name
                && in_array(strtolower($root->class->toString()), ['self','static'], true)
                && $root->name instanceof Node\Identifier && $root->name->toString() === $methodName;
            $consumers = 0;
            foreach ($class->getMethods() as $method) {
                $queryVars = $method === $factory ? [$queryName => true] : [];
                $paginationVars = [];
                foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\Assign::class) as $assign) {
                    if (!$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)) {
                        continue;
                    }
                    [$root, $chain] = moduleBoundaryQueryChain($assign->expr);
                    if ($root instanceof Node\Expr\StaticCall && $root->class instanceof Node\Name
                        && $root->class->toString() === 'app\\common\\support\\PaginationInput') {
                        $paginationVars[$assign->var->name] = true;
                    }
                    if ($isFactoryRoot($root) && array_filter($chain, static fn($call): bool => $call->name instanceof Node\Identifier && in_array(strtolower($call->name->toString()), $reads, true)) === []) {
                        $queryVars[$assign->var->name] = true;
                    }
                }
                $fields = $method === $factory ? $factoryFields : [];
                $methodReads = false;
                $isQuery = static function (Node $node) use ($queryVars, $isFactoryRoot): bool {
                    [$root] = moduleBoundaryQueryChain($node);
                    return $isFactoryRoot($root) || ($root instanceof Node\Expr\Variable && is_string($root->name) && isset($queryVars[$root->name]));
                };
                foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
                    if (!$isQuery($call)) {
                        continue;
                    }
                    if (!$call->name instanceof Node\Identifier) {
                        $valid = false;
                        break;
                    }
                    $name = strtolower($call->name->toString());
                    if (!in_array($name, [...$builders, ...$reads], true)) {
                        $valid = false;
                        break;
                    }
                    $methodReads = $methodReads || in_array($name, ['select','find','findorempty','paginate'], true);
                    if (in_array($name, ['field','fieldraw'], true)) {
                        $field = isset($call->args[0]) ? moduleBoundaryLiteral($call->args[0]->value, $constants) : null;
                        if ($field === null || trim($field) === '*') {
                            $valid = false;
                            break;
                        }
                        $fields[] = $field;
                    }
                }
                foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\StaticCall::class) as $call) {
                    if ($isFactoryRoot($call)) {
                        ++$consumers;
                    }
                }
                // A Query may be consumed only locally or by the existing bounded PaginationInput adapter.
                foreach ($finder->find($method->stmts ?? [], static fn(Node $node): bool => $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\New_) as $call) {
                    foreach ($call->args as $argument) {
                        if (!$argument instanceof Node\Arg || !$isQuery($argument->value)) {
                            continue;
                        }
                        [, $chain] = moduleBoundaryQueryChain($argument->value);
                        $consumed = array_filter($chain, static fn($item): bool => $item->name instanceof Node\Identifier && in_array(strtolower($item->name->toString()), $reads, true)) !== [];
                        if ($consumed) {
                            continue;
                        }
                        $pagination = $call instanceof Node\Expr\MethodCall && $call->name instanceof Node\Identifier && $call->name->toString() === 'result'
                            && (($call->var instanceof Node\Expr\Variable && isset($paginationVars[$call->var->name]))
                                || ($call->var instanceof Node\Expr\StaticCall && $call->var->class instanceof Node\Name && $call->var->class->toString() === 'app\\common\\support\\PaginationInput'));
                        if (!$pagination) {
                            $valid = false;
                            break 2;
                        }
                        $methodReads = true;
                    }
                }
                foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class) as $return) {
                    if ($method === $factory || $return->expr === null || !$isQuery($return->expr)) {
                        continue;
                    }
                    [, $chain] = moduleBoundaryQueryChain($return->expr);
                    if (array_filter($chain, static fn($item): bool => $item->name instanceof Node\Identifier && in_array(strtolower($item->name->toString()), $reads, true)) === []) {
                        $valid = false;
                    }
                }
                foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\Assign::class) as $assign) {
                    if ($assign->expr instanceof Node\Expr\Variable && is_string($assign->expr->name) && isset($queryVars[$assign->expr->name])) {
                        $valid = false;
                    }
                }
                foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\ClosureUse::class) as $use) {
                    if (isset($queryVars[$use->var->name])) {
                        $valid = false;
                    }
                }
                if ($method === $factory) {
                    $factoryFields = $fields;
                }
                if ($methodReads && $fields === [] && $factoryFields === []) {
                    $valid = false;
                }
                foreach ($fields as $field) {
                    foreach ($joins as $join) {
                        $joined = preg_quote($join['alias'], '/');
                        if (preg_match('/\b' . $joined . '\s*\.\s*(?:\*|[A-Za-z0-9_]*(?:password|passwd|secret|credential|token|salt|private_key)[A-Za-z0-9_]*)/i', str_replace('`', '', $field))) {
                            $valid = false;
                        }
                    }
                }
                if (!$valid) {
                    break;
                }
            }
            if ($valid && $consumers > 0) {
                foreach ($joins as $position => $join) {
                    $approved[$position] = $join;
                }
            }
        }
    }
    return $approved;
}

/** @return array<string,mixed> */
function moduleBoundaryInventory(string $root, bool $includeHost = false): array
{
    $modules = [];
    $namespaces = [];
    $tables = [];
    $findings = [];
    $moduleRoots = [];
    $root = realpath($root) ?: throw new InvalidArgumentException('MODULE_BOUNDARY_ROOT_MISSING');
    foreach (glob($root . '/server/app/modules/*/*/module.json') ?: [] as $manifestPath) {
        $directory = dirname($manifestPath);
        $moduleRoots[] = $directory;
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $composer = json_decode((string) file_get_contents($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $key = $manifest['key'];
        if (isset($modules[$key])) {
            throw new RuntimeException('MODULE_BOUNDARY_DUPLICATE_KEY: ' . $key);
        }
        $sources = [];
        foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $paths) {
            if (isset($namespaces[$prefix])) {
                throw new RuntimeException('MODULE_BOUNDARY_DUPLICATE_NAMESPACE: ' . $prefix);
            }
            $namespaces[$prefix] = $key;
            foreach ((array) $paths as $path) {
                $source = realpath($directory . '/' . $path);
                if ($source === false || !is_dir($source) || !str_starts_with($source, $directory . '/')) {
                    throw new RuntimeException('MODULE_BOUNDARY_SOURCE_OUTSIDE_MODULE: ' . $key);
                }
                $sources[$source] = true;
            }
        }
        if ($sources === []) {
            throw new RuntimeException('MODULE_BOUNDARY_EMPTY_SOURCE_MAPPING: ' . $key);
        }
        foreach ($manifest['database']['owned_tables'] ?? [] as $table) {
            $logical = str_starts_with($table, 'pa_') ? substr($table, 3) : $table;
            if (isset($tables[$logical])) {
                throw new RuntimeException('MODULE_BOUNDARY_DUPLICATE_TABLE: ' . $table);
            }
            $tables[$logical] = $key;
        }
        $modules[$key] = [
            'sources' => array_keys($sources),
            'exports' => array_fill_keys($manifest['contracts']['exports'] ?? [], true),
        ];
    }
    if ($modules === []) {
        throw new RuntimeException('MODULE_BOUNDARY_NO_MODULES');
    }
    uksort($namespaces, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
    $ownerOf = static function (string $name) use ($namespaces): ?string {
        foreach ($namespaces as $prefix => $owner) {
            if (str_starts_with($name, $prefix)) {
                return $owner;
            }
        }
        return null;
    };
    $scanGroups = $modules;
    if ($includeHost) {
        $manifest = json_decode((string) file_get_contents($root . '/server/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $hostMappings = $manifest['autoload']['psr-4']['app\\'] ?? null;
        if ($hostMappings === null || (array) $hostMappings === []) {
            throw new RuntimeException('MODULE_BOUNDARY_HOST_MAPPING_REQUIRED');
        }
        $hostSources = [];
        foreach ((array) $hostMappings as $mapping) {
            $source = is_string($mapping) ? realpath($root . '/server/' . $mapping) : false;
            if ($source === false || !is_dir($source)
                || ($source !== $root . '/server/app' && !str_starts_with($source, $root . '/server/app/'))) {
                throw new RuntimeException('MODULE_BOUNDARY_HOST_SOURCE_INVALID');
            }
            $hostSources[$source] = true;
        }
        $scanGroups['@host'] = ['sources' => array_keys($hostSources), 'exports' => []];
    }
    $parser = (new ParserFactory())->createForHostVersion();
    $files = 0;
    $references = 0;
    $literalTables = 0;
    $hostFiles = 0;
    $compositionReferences = 0;
    $readAssociations = [];
    $seenFiles = [];
    foreach ($scanGroups as $owner => $module) {
        foreach ($module['sources'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                if ($owner === '@host' && array_filter($moduleRoots, static fn(string $directory): bool => str_starts_with($path, $directory . '/')) !== []) {
                    continue;
                }
                if (isset($seenFiles[$path])) {
                    continue;
                }
                $seenFiles[$path] = true;
                $files++;
                $hostFiles += (int) ($owner === '@host');
                $relative = substr($path, strlen($root) + 1);
                $composition = $owner === '@host' && $relative === 'server/app/AppService.php';
                $nodes = $parser->parse((string) file_get_contents($path)) ?? [];
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver());
                $nodes = $traverser->traverse($nodes);
                $approvedReads = moduleBoundaryReadAssociations($nodes, $owner, $modules, $namespaces, $tables);
                $visit = function (Node $node) use (&$visit, $owner, $ownerOf, $modules, $tables, $relative, $composition, &$findings, &$references, &$literalTables, &$compositionReferences, $approvedReads, &$readAssociations): void {
                    if ($node instanceof Node\Name\FullyQualified) {
                        $target = $node->toString();
                        $targetOwner = $ownerOf($target);
                        if ($targetOwner !== null && $targetOwner !== $owner) {
                            $references++;
                            $compositionReferences += (int) $composition;
                            if (!$composition && !isset($modules[$targetOwner]['exports'][$target])) {
                                $id = $relative . ':' . $node->getStartFilePos() . ':type:' . $target;
                                $findings[$id] = ['code' => 'PRIVATE_MODULE_TYPE', 'path' => $relative, 'line' => $node->getStartLine(), 'owner' => $owner, 'target_owner' => $targetOwner, 'target' => $target];
                            }
                        }
                    }
                    $tableCall = $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
                        && in_array(strtolower($node->name->toString()), ['join', 'leftjoin', 'rightjoin', 'fulljoin', 'table'], true);
                    $facadeCall = $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->toString() === 'think\\facade\\Db'
                        && $node->name instanceof Node\Identifier && in_array(strtolower($node->name->toString()), ['name', 'table'], true);
                    if (($tableCall || $facadeCall) && isset($node->args[0]) && $node->args[0]->value instanceof Node\Scalar\String_) {
                        $value = $node->args[0]->value->value;
                        if (preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?(?:\\s|$)/', $value, $match)) {
                            $logical = str_starts_with($match[1], 'pa_') ? substr($match[1], 3) : $match[1];
                            $targetOwner = $tables[$logical] ?? null;
                            $literalTables++;
                            if ($targetOwner !== null && $targetOwner !== $owner) {
                                $id = $relative . ':' . $node->getStartFilePos() . ':table:' . $logical;
                                $finding = ['code' => 'FOREIGN_MODULE_TABLE', 'path' => $relative, 'line' => $node->getStartLine(), 'owner' => $owner, 'target_owner' => $targetOwner, 'target' => $logical];
                                if (isset($approvedReads[$node->getStartFilePos()])) {
                                    $finding['code'] = 'SAME_DATABASE_READ_ASSOCIATION';
                                    $readAssociations[$id] = $finding;
                                } else {
                                    $findings[$id] = $finding;
                                }
                            }
                        }
                    }
                    foreach ($node->getSubNodeNames() as $name) {
                        $child = $node->$name;
                        foreach (is_array($child) ? $child : [$child] as $item) {
                            if ($item instanceof Node) {
                                $visit($item);
                            }
                        }
                    }
                };
                foreach ($nodes as $node) {
                    $visit($node);
                }
            }
        }
    }
    ksort($findings, SORT_STRING);
    return [
        'status' => $findings === [] ? 'passed' : 'failed',
        'scope' => ($includeHost ? 'Current module and native app Composer sources; AppService type wiring counted separately. ' : 'Current module Composer sources. ')
            . 'Resolved static PHP names and literal table calls; not computed class names, arbitrary SQL or runtime authorization.',
        'modules' => count($modules), 'php_files' => $files,
        'host_php_files' => $hostFiles, 'composition_type_references' => $compositionReferences,
        'cross_module_type_references' => $references, 'literal_table_calls' => $literalTables,
        'declared_tables' => count($tables), 'findings' => array_values($findings),
        'read_only_associations' => array_values($readAssociations),
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $arguments = array_slice($argv, 1);
        if ($arguments !== [] && $arguments !== ['--include-host']) {
            throw new InvalidArgumentException('Usage: php scripts/check-module-boundaries.php [--include-host]');
        }
        $report = moduleBoundaryInventory(dirname(__DIR__), $arguments === ['--include-host']);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
        exit($report['status'] === 'passed' ? 0 : 1);
    } catch (Throwable $error) {
        fwrite(STDERR, 'MODULE_BOUNDARY_CHECK_FAILED: ' . $error->getMessage() . PHP_EOL);
        exit(2);
    }
}
