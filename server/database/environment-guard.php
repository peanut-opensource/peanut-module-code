<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('缺少 Composer autoload，无法校验应用数据库');
}
require_once $autoload;

// Standalone database commands need native Env before any application services.
$application = \think\Container::getInstance();
if (!$application instanceof \think\App) {
    $application = new \think\App(dirname(__DIR__));
}
require_once $application->getThinkPath() . 'helper.php';
if (!$application->initialized()) {
    $application->loadEnv((string) $application->env->get('env_name', ''));
}

function requiredEnvironment(string $name, string|false $default = false): string
{
    $value = env($name, $default);
    if ((!is_string($value) && !is_int($value)) || trim((string) $value) === '') {
        throw new RuntimeException("缺少环境配置：{$name}");
    }
    return trim((string) $value);
}

/** Maintenance selections and lease handles are process inputs, never APP .env fields. */
function requiredMaintenanceEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("缺少维护进程输入：{$name}");
    }
    return trim($value);
}

/** Only an explicitly registered schema policy replaces a port lock. */
function mysqlSchemaLeaseResource(array $database, array $endpoint, array $actual): ?string
{
    $policy = $database['lease_policy'] ?? null;
    if ($policy === null || ($policy['mode'] ?? null) === 'exclusive') { return null; }
    if (!is_array($policy) || ($policy['mode'] ?? null) !== 'schema'
        || !is_int($policy['max_executors'] ?? null)
        || $policy['max_executors'] < 1 || $policy['max_executors'] > 8
        || ($policy['physical_endpoint'] ?? null) !== (string) $endpoint['host'] . ':' . (int) $endpoint['port']
        || !is_string($policy['physical_resource_id'] ?? null) || $policy['physical_resource_id'] === ''
        || !is_array($policy['server_resources'] ?? null) || $policy['server_resources'] === []
        || preg_match('/qualification|performance|recovery/', (string) ($database['lease_gate'] ?? '')) === 1
        || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $actual['database']) !== 1) {
        throw new RuntimeException('MySQL schema lease policy does not match registered physical endpoint');
    }
    return $policy['physical_endpoint'] . '/' . $actual['database'];
}

/**
 * Consume the native policy under its mutex, including the complete transaction
 * declaration and other owners' scopes. This proves admission, not SQL confinement:
 * the executing task must cover every read/write and indirect business effect.
 *
 * @param array<string,string> $metadata
 * @param array<string,list<string>> $resources
 */
function assertNativeMysqlSchemaLease(
    string $root,
    string $proofPath,
    array $metadata,
    array $resources,
    string $resourceId,
    string $schemaResource,
    string $endpointId,
    string $registrySha256,
): ?array {
    // A generated source APP may explicitly trust a fixed central native tool.
    // Its own registry remains the only runtime registry; Project never enters PHP.
    $registry = projectResourceRegistry($actualRegistrySha);
    $database = registeredDatabase($registry, $resourceId);
    $authority = $database['lease_policy']['native_authority'] ?? null;
    if ($authority !== null) {
        $privateRegistry = $root . '/server/private/resources/project-resources.json';
        if (($registry['authority']['role'] ?? null) !== 'application'
            || !is_file($root . '/.peanut/application-manifest.json')
            || ($external = getenv('PEANUT_RESOURCE_REGISTRY')) !== false && trim($external) !== ''
            || realpath($privateRegistry) !== $privateRegistry
            || $actualRegistrySha !== $registrySha256 || hash_file('sha256', $privateRegistry) !== $registrySha256
            || !is_array($authority) || count($authority) !== 4 || array_diff(array_keys($authority), ['root', 'common_dir', 'commit', 'files']) !== []
            || !is_string($authority['root']) || realpath($authority['root']) !== $authority['root']
            || !is_array($authority['files'])
            || leaseGit(['-C', $authority['root'], 'rev-parse', 'HEAD^{commit}']) !== $authority['commit']
            || leaseGit(['-C', $authority['root'], 'rev-parse', '--path-format=absolute', '--git-common-dir']) !== $authority['common_dir']) {
            throw new RuntimeException('APP_NATIVE_LEASE_AUTHORITY_INVALID');
        }
        $files = ['scripts/project-resource-lease', 'scripts/project-resource-application.py', 'scripts/project-resource-registry'];
        $actualFiles = array_keys($authority['files']); sort($files); sort($actualFiles);
        if ($actualFiles !== $files) { throw new RuntimeException('APP_NATIVE_LEASE_AUTHORITY_INVALID'); }
        foreach ($authority['files'] as $file => $digest) {
            $path = $authority['root'] . '/' . $file;
            if (realpath($path) !== $path || !is_file($path) || !is_string($digest)
                || hash_file('sha256', $path) !== $digest
                || leaseGit(['-C', $authority['root'], 'hash-object', '--no-filters', '--', $path])
                    !== leaseGit(['-C', $authority['root'], 'rev-parse', $authority['commit'] . ':' . $file])) {
                throw new RuntimeException('APP_NATIVE_LEASE_AUTHORITY_CHANGED');
            }
        }
        $process = proc_open(['python3', '-B', $authority['root'] . '/scripts/project-resource-application.py',
            'consume', '--proof', $proofPath, '--application-root', $root, '--registry', $privateRegistry,
            '--resource-id', $resourceId, '--endpoint-id', $endpointId],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) { throw new RuntimeException('APP_NATIVE_LEASE_VERIFIER_UNAVAILABLE'); }
        $result = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
        $result = json_decode($result, true);
        if ($code !== 0 || $error !== '' || !is_int($result['binding_expires_at'] ?? null)) {
            throw new RuntimeException('APP_NATIVE_LEASE_VERIFIER_REJECTED');
        }
        return $result;
    }
    $common = leaseGit(['-C', $root, 'rev-parse', '--path-format=absolute', '--git-common-dir']);
    $candidateRoot = leaseGit(['-C', $root, 'rev-parse', '--show-toplevel']);
    $candidateHead = leaseGit(['-C', $root, 'rev-parse', 'HEAD^{commit}']);
    $expectedProof = $common . '/peanut-admin-resource-leases/leases/' . $metadata['lease'];
    if ($metadata['parent_lease'] !== ''
        || realpath($candidateRoot) !== $root
        || !hash_equals($metadata['candidate'], $candidateHead)
        || realpath($metadata['worktree']) !== $root
        || realpath($metadata['candidate_repository']) !== $root
        || realpath($proofPath) !== realpath($expectedProof)
        || !in_array($resourceId, [...($resources['database-resource'] ?? []), ...($resources['resource-id'] ?? [])], true)
        || !in_array($schemaResource, $resources['mysql-schema'] ?? [], true)) {
        throw new RuntimeException('MySQL schema lease 与当前资源/工作树不匹配');
    }
    assertLeaseResourceValues($resources, 'registry-sha256', [$registrySha256]);
    assertLeaseResourceValues($resources, 'endpoint', [$endpointId]);
    $nativeEnvironment = null;
    if (($registry['authority']['role'] ?? null) === 'application') {
        $privateRegistry = $root . '/server/private/resources/project-resources.json';
        if (realpath($privateRegistry) !== $privateRegistry || hash_file('sha256', $privateRegistry) !== $registrySha256) {
            throw new RuntimeException('APP_NATIVE_LEASE_REGISTRY_CHANGED');
        }
        $nativeEnvironment = (array) getenv();
        $nativeEnvironment['PEANUT_RESOURCE_REGISTRY'] = $privateRegistry;
    }
    $process = proc_open(
        ['/bin/bash', $root . '/scripts/project-resource-lease', 'show', '--lease', $metadata['lease'], '--verify-locks'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $nativeEnvironment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('MySQL native lease verifier 不可用');
    }
    stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('MySQL native lease proof 被拒绝: ' . trim((string) $error));
    }
    $statePath = $proofPath . '/application-operation.json';
    if (($registry['authority']['role'] ?? null) === 'application' && is_file($statePath) && !is_link($statePath)) {
        $state = json_decode((string) file_get_contents($statePath), true, 64, JSON_THROW_ON_ERROR);
        if (isset($state['recovery'])) {
            $process = proc_open(['python3', '-B', $root . '/scripts/project-resource-application.py',
                'recovery-info', '--lease', $metadata['lease']], [0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            if (!is_resource($process)) { throw new RuntimeException('SOURCE_RECOVERY_AUTHORITY_UNAVAILABLE'); }
            $bytes = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0 || $error !== '') { throw new RuntimeException('SOURCE_RECOVERY_AUTHORITY_REJECTED'); }
            return json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        }
        if (($state['phase'] ?? null) === 'source-application' && ($state['owner'] ?? null) === $metadata['owner']
            && ($state['lease'] ?? null) === $metadata['lease']
            && is_string($state['binding_expires_at'] ?? null)
            && (int) $state['binding_expires_at'] <= (int) $metadata['expires_at']
            && (int) $state['binding_expires_at'] > time()) {
            return ['binding_expires_at' => $state['binding_expires_at']];
        }
    }
    return null;
}

require_once __DIR__ . '/resource-registry.php';

/** @return array<string,string> */
function activeLeaseMetadata(string $proofPath, int $now, ?string $expectedGate): array
{
    $metadataPath = $proofPath . '/metadata.tsv';
    if (!is_dir($proofPath) || is_link($proofPath) || !is_file($metadataPath) || is_link($metadataPath)) {
        throw new RuntimeException('active lease metadata 不可用');
    }
    $lines = file($metadataPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('active lease metadata 无法读取');
    }
    $metadata = [];
    foreach ($lines as $line) {
        $fields = explode("\t", $line);
        if (count($fields) !== 2 || $fields[0] === ''
            || ($fields[1] === '' && $fields[0] !== 'parent_lease')
            || array_key_exists($fields[0], $metadata)) {
            throw new RuntimeException('active lease metadata 格式无效');
        }
        $metadata[$fields[0]] = $fields[1];
    }
    $expectedKeys = ['lease', 'owner', 'thread', 'candidate', 'candidate_repository', 'parent_lease', 'gate', 'worktree', 'created_at', 'expires_at', 'status'];
    $actualKeys = array_keys($metadata);
    sort($expectedKeys, SORT_STRING);
    sort($actualKeys, SORT_STRING);
    if ($actualKeys !== $expectedKeys
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $metadata['lease']) !== 1
        || preg_match('/^[a-f0-9]{40}$/D', $metadata['candidate']) !== 1
        || ($expectedGate !== null && $metadata['gate'] !== $expectedGate)
        || ($metadata['parent_lease'] !== '' && (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $metadata['parent_lease']) !== 1
            || $metadata['parent_lease'] === $metadata['lease']
        ))
        || !isLexicallyAbsolutePath($metadata['candidate_repository'])
        || !isLexicallyAbsolutePath($metadata['worktree'])
        || preg_match('/^[0-9]+$/D', $metadata['created_at']) !== 1
        || preg_match('/^[0-9]+$/D', $metadata['expires_at']) !== 1
        || $metadata['status'] !== 'ACTIVE'
        || (int) $metadata['created_at'] > $now
        || (int) $metadata['expires_at'] <= $now) {
        throw new RuntimeException('active lease 未激活、已过期或 metadata 合同不匹配');
    }
    return $metadata;
}

/** @return array<string,list<string>> */
function activeLeaseResources(string $proofPath): array
{
    $resourcesPath = $proofPath . '/resources.tsv';
    if (!is_file($resourcesPath) || is_link($resourcesPath)) {
        throw new RuntimeException('active lease resources 不可用');
    }
    $lines = file($resourcesPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('active lease resources 无法读取');
    }
    $resources = [];
    $pairs = [];
    foreach ($lines as $line) {
        $fields = explode("\t", $line);
        if (count($fields) !== 3 || $fields[0] === '' || $fields[1] === '' || $fields[2] === '') {
            throw new RuntimeException('P0-E active lease resources 格式无效');
        }
        [$digest, $type, $value] = $fields;
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals(hash('sha256', $type . "\t" . $value), $digest)
            || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $type) !== 1
            || isset($pairs[$type . "\t" . $value])) {
            throw new RuntimeException('active lease resource identity 无效');
        }
        $pairs[$type . "\t" . $value] = true;
        $resources[$type] ??= [];
        $resources[$type][] = $value;
    }
    foreach ($resources as &$values) {
        sort($values, SORT_STRING);
    }
    unset($values);
    ksort($resources, SORT_STRING);
    return $resources;
}

/**
 * Only the directly owned worktree may be inherited from a live parent.
 * Host proofs stay in the candidate Git registry; containers receive two
 * explicitly selected read-only mounts, never an arbitrary parent path.
 *
 * @param array<string,string> $metadata
 * @return array<string,list<string>>
 */
function resolvedP0eLeaseResources(string $proofPath, array $metadata, int $now, string $consumer): array
{
    $resources = activeLeaseResources($proofPath);
    if ($metadata['parent_lease'] === '') {
        return $resources;
    }
    if (isset($resources['worktree']) || $metadata['candidate_repository'] !== $metadata['worktree']) {
        throw new RuntimeException('child lease 不能自行声明或越界继承 worktree');
    }
    $containerMount = $proofPath === '/run/peanut-admin/resource-lease';
    if ($containerMount) {
        if ($consumer !== 'container') {
            throw new RuntimeException('P0-E child proof mount 不匹配');
        }
        $parentPath = '/run/peanut-admin/resource-lease-parent';
    } elseif (in_array($consumer, ['host', 'container'], true)) {
        // An explicit native Host proof also permits no-PDO preflight of the
        // real Container configuration, with the same Git/parent/lock checks.
        $repository = realpath($metadata['candidate_repository']);
        if ($repository !== $metadata['candidate_repository'] || !is_dir($repository)) {
            throw new RuntimeException('P0-E parent candidate repository 不是可信实体');
        }
        $common = leaseGit(['-C', $repository, 'rev-parse', '--path-format=absolute', '--git-common-dir']);
        $leases = $common . '/peanut-admin-resource-leases/leases';
        if (realpath($proofPath) !== $proofPath || $proofPath !== $leases . '/' . $metadata['lease']) {
            throw new RuntimeException('P0-E child proof 不是当前 candidate 的 active lease 坐标');
        }
        $head = leaseGit(['-C', $repository, 'rev-parse', 'HEAD^{commit}']);
        $tree = leaseGit(['-C', $repository, 'rev-parse', $metadata['candidate'] . '^{tree}']);
        if ($head !== $metadata['candidate'] || ($resources['candidate-tree'] ?? null) !== [$tree]) {
            throw new RuntimeException('P0-E parent candidate HEAD/tree 不匹配');
        }
        $parentPath = $leases . '/' . $metadata['parent_lease'];
    } else {
        throw new RuntimeException('P0-E parent proof consumer 无效');
    }
    $parent = activeLeaseMetadata($parentPath, $now, null);
    if ($parent['lease'] !== $metadata['parent_lease'] || $parent['parent_lease'] !== '') {
        throw new RuntimeException('P0-E parent proof identity 或嵌套关系无效');
    }
    foreach (['owner', 'thread', 'candidate', 'candidate_repository', 'worktree'] as $key) {
        if (!hash_equals($metadata[$key], $parent[$key])) {
            throw new RuntimeException('P0-E parent/child identity 不匹配: ' . $key);
        }
    }
    if ((int) $metadata['expires_at'] > (int) $parent['expires_at']) {
        throw new RuntimeException('P0-E child lease 不能超出 parent 有效期');
    }
    $parentResources = activeLeaseResources($parentPath);
    assertLeaseResourceValues($parentResources, 'worktree', [$metadata['worktree']]);
    if (!$containerMount) {
        $locks = dirname($leases) . '/resources';
        foreach ([$metadata['lease'] => $resources, $parent['lease'] => ['worktree' => [$metadata['worktree']]]] as $lease => $owned) {
            foreach ($owned as $type => $values) {
                foreach ($values as $value) {
                    $lock = $locks . '/' . hash('sha256', $type . "\t" . $value);
                    if (!is_dir($lock) || is_link($lock)) {
                        throw new RuntimeException('P0-E active resource lock 不可用');
                    }
                    foreach (['lease' => $lease, 'type' => $type, 'value' => $value] as $field => $expected) {
                        $file = $lock . '/' . $field;
                        if (!is_file($file) || is_link($file)
                            || rtrim((string) file_get_contents($file), "\n") !== $expected) {
                            throw new RuntimeException('P0-E active resource lock 归属不匹配');
                        }
                    }
                }
            }
        }
    }
    $resources['worktree'] = [$metadata['worktree']];
    ksort($resources, SORT_STRING);
    return $resources;
}

/** @param array<string,mixed> $database @return array{run_id:string,scenario:string} */
function templatedDatabaseIdentity(array $database, string $name): array
{
    $template = $database['database'] ?? null;
    $allowedScenarios = $database['allowed_scenarios'] ?? null;
    if (!is_string($template)
        || substr_count($template, '<run_id>') !== 1
        || substr_count($template, '<scenario>') !== 1
        || !is_array($allowedScenarios)
        || $allowedScenarios === []) {
        throw new RuntimeException('P0-E database template 登记无效');
    }
    $matcher = preg_quote($template, '/');
    $matcher = str_replace(
        [preg_quote('<run_id>', '/'), preg_quote('<scenario>', '/')],
        ['(?P<run_id>.+?)', '(?P<scenario>.+?)'],
        $matcher,
    );
    if (preg_match('/^' . $matcher . '$/D', $name, $matches) !== 1
        || !in_array($matches['scenario'], $allowedScenarios, true)) {
        throw new RuntimeException('P0-E database 不在登记的 template/scenario 集合内');
    }
    $runIdPattern = $database['run_id_pattern'] ?? null;
    if (!is_string($runIdPattern)
        || preg_match('/' . str_replace('/', '\\/', $runIdPattern) . '/D', $matches['run_id']) !== 1) {
        throw new RuntimeException('P0-E database run_id 不符合登记规则');
    }
    $maxLength = $database['database_name_max_length'] ?? null;
    if (!is_int($maxLength) || $maxLength < 1 || strlen($name) > $maxLength) {
        throw new RuntimeException('P0-E database 名称超过登记上限');
    }
    return ['run_id' => $matches['run_id'], 'scenario' => $matches['scenario']];
}

/** @param array<string,list<string>> $resources @param list<string> $expected */
function assertLeaseResourceValues(array $resources, string $type, array $expected): void
{
    sort($expected, SORT_STRING);
    if (($resources[$type] ?? null) !== $expected) {
        throw new RuntimeException("P0-E lease resource {$type} 不匹配精确合同");
    }
}

function isLexicallyAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/')
        && !str_contains($path, "\0")
        && !str_contains($path, '/./')
        && !str_contains($path, '/../')
        && !str_ends_with($path, '/.')
        && !str_ends_with($path, '/..');
}

/** @param list<string> $arguments */
function leaseGit(array $arguments): string
{
    $pipes = [];
    $process = proc_open(
        array_merge(['/usr/bin/git'], $arguments),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('consumer-upgrade candidate Git identity 不可用');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($stdout)) {
        throw new RuntimeException('consumer-upgrade candidate Git identity 无法解析: ' . trim((string) $stderr));
    }
    return trim($stdout);
}

/**
 * The Host-only consumer-upgrade guard accepts only the live lease directory
 * owned by the candidate repository's Git common directory.
 *
 * @param array<string,string> $metadata
 * @param array<string,list<string>> $resources
 */
function assertConsumerUpgradeCandidateLeaseIdentity(
    string $proofPath,
    array $metadata,
    array $resources,
): void {
    $candidateRepository = realpath($metadata['candidate_repository']);
    $worktree = realpath($metadata['worktree']);
    $actualProofPath = realpath($proofPath);
    if (!is_string($candidateRepository)
        || !is_dir($candidateRepository)
        || $candidateRepository !== $metadata['candidate_repository']
        || !is_string($worktree)
        || $worktree !== $metadata['worktree']
        || $worktree !== $candidateRepository
        || !is_string($actualProofPath)
        || $actualProofPath !== $proofPath) {
        throw new RuntimeException('consumer-upgrade candidate/proof 路径不是可信实体');
    }

    $commonDirectory = leaseGit(['-C', $candidateRepository, 'rev-parse', '--git-common-dir']);
    if (!str_starts_with($commonDirectory, '/')) {
        $commonDirectory = $candidateRepository . '/' . $commonDirectory;
    }
    $commonDirectory = realpath($commonDirectory);
    if (!is_string($commonDirectory)) {
        throw new RuntimeException('consumer-upgrade candidate Git common-dir 不可用');
    }
    $expectedProofPath = $commonDirectory . '/peanut-admin-resource-leases/leases/' . $metadata['lease'];
    if (!hash_equals($expectedProofPath, $actualProofPath)
        || ($resources['lease-proof-dir'] ?? null) !== [$actualProofPath]) {
        throw new RuntimeException('consumer-upgrade proof 不是 candidate repository 的 active lease 坐标');
    }

    $candidate = leaseGit(['-C', $candidateRepository, 'rev-parse', $metadata['candidate'] . '^{commit}']);
    $head = leaseGit(['-C', $candidateRepository, 'rev-parse', 'HEAD^{commit}']);
    $tree = leaseGit(['-C', $candidateRepository, 'rev-parse', $candidate . '^{tree}']);
    if (!hash_equals($metadata['candidate'], $candidate)
        || !hash_equals($candidate, $head)
        || ($resources['candidate-tree'] ?? null) !== [$tree]) {
        throw new RuntimeException('consumer-upgrade candidate commit/tree 与实际 Git identity 不一致');
    }
}

/**
 * Qualification APP registries are projections of the fixed maintainer file.
 * Its original bytes travel only in the qualification's read-only lease mount;
 * ordinary APP runtime never reads or requires this private proof.
 *
 * @param array<string,mixed> $registry
 * @param array<string,mixed> $database
 */
function p0eRegistryProofSha256(string $proofPath, array $registry, array $database, string $registrySha256): string
{
    if ($registry['authority']['role'] === 'maintainer') {
        return $registrySha256;
    }
    $path = $proofPath . '/registry.json';
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('P0-E application registry 缺少原始登记 proof');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException('P0-E 原始登记 proof 无法读取');
    }
    try {
        $original = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('P0-E 原始登记 proof 无效', 0, $exception);
    }
    if (!is_array($original) || ($original['schema_version'] ?? null) !== 1
        || ($original['project_id'] ?? null) !== 'peanut-admin'
        || ($original['authority']['role'] ?? null) !== 'maintainer'
        || !is_array($original['resources']['databases'] ?? null)) {
        throw new RuntimeException('P0-E 原始登记 proof 不是维护者事实源');
    }
    $expected = registeredDatabase($original, 'peanut-admin-p0e-mysql84-gate');
    unset($expected['credential_ref']);
    if ($database !== $expected) {
        throw new RuntimeException('P0-E application database 与原始登记投影不匹配');
    }
    return hash('sha256', $raw);
}

/**
 * @param array<string,string> $metadata
 * @param array<string,list<string>> $resources
 * @param array<string,mixed> $database
 * @param array<string,mixed> $endpoint
 * @param array{run_id:string,scenario:string} $identity
 */
function assertP0eLeaseContract(
    array $metadata,
    array $resources,
    array $database,
    array $endpoint,
    array $identity,
    string $resourceId,
    string $deploymentTarget,
    string $deploymentMode,
    string $registrySha256,
): void {
    $runId = $identity['run_id'];
    $allowedScenarios = $database['allowed_scenarios'] ?? null;
    if (!is_array($allowedScenarios)
        || count($allowedScenarios) < 2
        || array_filter($allowedScenarios, static fn(mixed $scenario): bool => !is_string($scenario) || $scenario === '') !== []) {
        throw new RuntimeException('P0-E database scenario 登记无效');
    }
    $allExpectedDatabases = array_map(
        static fn(string $scenario): string => str_replace(
            ['<run_id>', '<scenario>'],
            [$runId, $scenario],
            (string) $database['database'],
        ),
        $allowedScenarios,
    );
    $qualificationGroups = $resources['qualification-group'] ?? null;
    if (!is_array($qualificationGroups) || count($qualificationGroups) !== 1) {
        throw new RuntimeException('P0-E lease 缺少唯一 qualification group 身份');
    }
    $profiles = [
        'generated-application' => ['scenario_count' => 2, 'compose' => false, 'browser' => false],
        'standalone-fresh' => ['scenario_count' => 2, 'compose' => false, 'browser' => false],
        'multi-tenant-fresh' => ['scenario_count' => 2, 'compose' => false, 'browser' => false],
        'plugin-lifecycle' => ['scenario_count' => 3, 'compose' => false, 'browser' => false],
        'consumer-module-lifecycle' => ['scenario_count' => 4, 'compose' => false, 'browser' => false],
        'production-compose' => ['scenario_count' => 5, 'compose' => true, 'browser' => false],
        'standalone-browser' => ['scenario_count' => 5, 'compose' => true, 'browser' => true],
        'multi-tenant-browser' => ['scenario_count' => 6, 'compose' => true, 'browser' => true],
    ];
    $qualificationGroup = $qualificationGroups[0];
    $profile = $profiles[$qualificationGroup] ?? null;
    if (!is_array($profile) || $profile['scenario_count'] > count($allExpectedDatabases)) {
        throw new RuntimeException('P0-E lease qualification group 未登记或超出 database scenario 合同');
    }
    $selectedScenarioCount = $profile['scenario_count'];
    $actualDatabases = $resources['mysql-db'] ?? null;
    if (!is_array($actualDatabases)) {
        throw new RuntimeException('P0-E lease 缺少 database scenario 资源');
    }
    $expectedPrefix = array_slice($allExpectedDatabases, 0, $selectedScenarioCount);
    sort($expectedPrefix, SORT_STRING);
    if ($actualDatabases !== $expectedPrefix) {
        throw new RuntimeException('P0-E lease database scenario 不是已登记的资格前缀');
    }
    $hasComposeResources = $profile['compose'];
    $hasBrowserResources = $profile['browser'];

    $expectedCounts = [
        'cache-dir' => 1,
        'candidate-tree' => 1,
        'consumer' => $hasComposeResources ? 2 : 1,
        'database' => $selectedScenarioCount,
        'deployment-mode' => 2,
        'deployment-target' => 1,
        'endpoint' => $hasComposeResources ? 4 : 2,
        'environment' => 1,
        'gate' => 1,
        'mysql-db' => $selectedScenarioCount,
        'output-dir' => 1,
        'qualification-group' => 1,
        'registry-sha256' => 1,
        'resource-id' => 1,
        'resource-scope' => 1,
        'run-id' => 1,
        'port' => $hasComposeResources ? ($hasBrowserResources ? 4 : 3) : 1,
        'worktree' => 1,
    ];
    if ($hasComposeResources) {
        $expectedCounts += [
            'compose-project' => 1,
            'database-tunnel' => 1,
            'http-port' => 1,
            'lease-proof-dir' => 1,
        ];
    }
    if ($hasBrowserResources) {
        $expectedCounts += [
            'browser-host' => 3,
            'browser-session' => 1,
            'docs-port' => 1,
        ];
    }
    ksort($expectedCounts, SORT_STRING);
    $actualCounts = [];
    foreach ($resources as $type => $values) {
        $actualCounts[$type] = count($values);
    }
    ksort($actualCounts, SORT_STRING);
    if ($actualCounts !== $expectedCounts || array_sum($actualCounts) !== array_sum($expectedCounts)) {
        throw new RuntimeException('P0-E lease resource set 存在缺失、额外项或 cardinality 冲突');
    }

    assertLeaseResourceValues($resources, 'resource-id', [$resourceId]);
    assertLeaseResourceValues($resources, 'registry-sha256', [$registrySha256]);
    assertLeaseResourceValues($resources, 'resource-scope', [str_replace('<run_id>', $runId, (string) $database['namespace'])]);
    assertLeaseResourceValues($resources, 'environment', ['development']);
    assertLeaseResourceValues($resources, 'deployment-target', [$deploymentTarget]);
    assertLeaseResourceValues(
        $resources,
        'consumer',
        $hasComposeResources ? ['container', 'host'] : ['host'],
    );
    $registeredEndpoints = [];
    foreach ($hasComposeResources ? ['upstream_endpoint', 'container_endpoint'] : ['upstream_endpoint'] as $endpointKey) {
        $registered = $database[$endpointKey] ?? null;
        if (!is_array($registered)) {
            throw new RuntimeException("P0-E database {$endpointKey} 登记缺失");
        }
        $registeredEndpoints[] = (string) $registered['endpoint_id'];
        $registeredEndpoints[] = (string) $registered['host'] . ':' . (string) $registered['port'];
    }
    assertLeaseResourceValues($resources, 'endpoint', $registeredEndpoints);
    assertLeaseResourceValues($resources, 'run-id', [$runId]);
    assertLeaseResourceValues($resources, 'mysql-db', array_slice($allExpectedDatabases, 0, $selectedScenarioCount));
    assertLeaseResourceValues($resources, 'database', array_slice($allExpectedDatabases, 0, $selectedScenarioCount));
    assertLeaseResourceValues($resources, 'qualification-group', [$qualificationGroup]);
    assertLeaseResourceValues($resources, 'deployment-mode', ['multi-tenant', 'standalone']);
    $ports = [(string) $database['upstream_endpoint']['port']];
    if ($hasComposeResources) {
        $ports = [...$ports, (string) $database['container_endpoint']['port'], '20190'];
    }
    if ($hasBrowserResources) {
        $ports[] = '20186';
    }
    assertLeaseResourceValues($resources, 'port', $ports);
    if ($hasComposeResources) {
        assertLeaseResourceValues($resources, 'http-port', ['20190']);
        assertLeaseResourceValues(
            $resources,
            'database-tunnel',
            ['peanut-admin-p0e-mysql84-container-tunnel'],
        );
        assertLeaseResourceValues($resources, 'compose-project', ['peanut-p0e-' . $runId]);
    }
    if ($hasBrowserResources) {
        assertLeaseResourceValues($resources, 'docs-port', ['20186']);
        assertLeaseResourceValues($resources, 'browser-session', ['p0e-' . $runId]);
        assertLeaseResourceValues($resources, 'browser-host', ['admin.p0e.localhost', 'beta.p0e.localhost', 'platform.p0e.localhost']);
    }
    assertLeaseResourceValues($resources, 'gate', [$metadata['gate']]);
    assertLeaseResourceValues($resources, 'worktree', [$metadata['worktree']]);
    if ($metadata['lease'] !== 'p0e-runtime-' . $runId
        || $metadata['candidate_repository'] !== $metadata['worktree']
        || !isLexicallyAbsolutePath($metadata['worktree'])
        || preg_match('/^[a-f0-9]{40}$/D', $resources['candidate-tree'][0]) !== 1) {
        throw new RuntimeException('P0-E lease candidate/run_id/worktree identity 不匹配');
    }

    $outputDir = $resources['output-dir'][0];
    $cacheDir = $resources['cache-dir'][0];
    if (!isLexicallyAbsolutePath($outputDir)
        || !isLexicallyAbsolutePath($cacheDir)
        || $outputDir !== rtrim($metadata['worktree'], '/') . '/output/p0e-' . $runId
        || basename($cacheDir) !== 'p0e-' . $runId
        || !str_ends_with($cacheDir, '/.cache/peanut-admin/p0e-' . $runId)) {
        throw new RuntimeException('P0-E lease path identity 不匹配精确 run_id 合同');
    }
    if ($hasComposeResources) {
        $proofDir = $resources['lease-proof-dir'][0];
        if (!isLexicallyAbsolutePath($proofDir)
            || !str_ends_with($proofDir, '/peanut-admin-resource-leases/leases/' . $metadata['lease'])) {
            throw new RuntimeException('P0-E lease proof path 不匹配精确 run_id 合同');
        }
    }

    $multiTenantScenarios = ['multi_tenant_fresh', 'plugin_lifecycle', 'multi_tenant_browser'];
    $expectedMode = in_array($identity['scenario'], $multiTenantScenarios, true)
        ? 'multi-tenant'
        : 'standalone';
    if (!hash_equals($expectedMode, $deploymentMode)) {
        throw new RuntimeException('P0-E deployment mode 与 database scenario 不匹配');
    }
}

/**
 * @param array<string,string> $metadata
 * @param array<string,list<string>> $resources
 * @param array<string,mixed> $database
 * @param array{run_id:string,scenario:string} $identity
 */
function assertConsumerUpgradeLeaseContract(
    string $proofPath,
    array $metadata,
    array $resources,
    array $database,
    array $identity,
    string $resourceId,
    string $deploymentTarget,
    string $deploymentMode,
): void {
    $expectedCounts = [
        'backup-root' => 2,
        'cache-dir' => 1,
        'candidate-tree' => 1,
        'consumer' => 1,
        'deployment-target' => 1,
        'endpoint' => 1,
        'environment' => 1,
        'gate' => 1,
        'http-port' => 1,
        'instance-root' => 2,
        'lease-proof-dir' => 1,
        'listener-resource-id' => 1,
        'mysql-db' => count($database['allowed_scenarios']),
        'object-prefix' => count($database['allowed_scenarios']),
        'object-storage-resource-id' => 1,
        'output-dir' => 1,
        'port' => 1,
        'resource-id' => 1,
        'run-id' => 1,
        'tooling-resource-id' => 1,
        'worktree' => 1,
    ];
    $actualCounts = [];
    foreach ($resources as $type => $values) {
        $actualCounts[$type] = count($values);
    }
    ksort($actualCounts, SORT_STRING);
    if ($actualCounts !== $expectedCounts || array_sum($actualCounts) !== array_sum($expectedCounts)) {
        throw new RuntimeException('consumer-upgrade lease resource set 存在缺失、额外项或 cardinality 冲突');
    }

    $runId = $identity['run_id'];
    $scenarios = $database['allowed_scenarios'];
    $expectedDatabases = array_map(
        static fn(string $scenario): string => str_replace(['<run_id>', '<scenario>'], [$runId, $scenario], (string) $database['database']),
        $scenarios,
    );
    assertLeaseResourceValues($resources, 'resource-id', [$resourceId]);
    assertLeaseResourceValues($resources, 'environment', ['development']);
    assertLeaseResourceValues($resources, 'deployment-target', [$deploymentTarget]);
    assertLeaseResourceValues($resources, 'consumer', ['host']);
    assertLeaseResourceValues($resources, 'endpoint', [(string) $database['upstream_endpoint']['host'] . ':' . (string) $database['upstream_endpoint']['port']]);
    assertLeaseResourceValues($resources, 'run-id', [$runId]);
    assertLeaseResourceValues($resources, 'mysql-db', $expectedDatabases);
    assertLeaseResourceValues($resources, 'tooling-resource-id', [(string) $database['administrative_tooling_resource_id']]);
    assertLeaseResourceValues($resources, 'listener-resource-id', [(string) $database['http_listener_resource_id']]);
    assertLeaseResourceValues($resources, 'object-storage-resource-id', [(string) $database['local_object_storage_resource_id']]);
    assertLeaseResourceValues($resources, 'port', ['20190']);
    assertLeaseResourceValues($resources, 'http-port', ['20190']);
    assertLeaseResourceValues(
        $resources,
        'object-prefix',
        array_map(static fn(string $scenario): string => 'cr03/' . $runId . '/' . $scenario . '/', $scenarios),
    );
    assertLeaseResourceValues($resources, 'gate', [$metadata['gate']]);
    assertLeaseResourceValues($resources, 'worktree', [$metadata['worktree']]);
    if ($metadata['lease'] !== 'consumer-upgrade-' . $runId
        || $metadata['candidate_repository'] !== $metadata['worktree']
        || !isLexicallyAbsolutePath($metadata['worktree'])
        || preg_match('/^[a-f0-9]{40}$/D', $resources['candidate-tree'][0]) !== 1) {
        throw new RuntimeException('consumer-upgrade lease candidate/run_id/worktree identity 不匹配');
    }
    assertConsumerUpgradeCandidateLeaseIdentity($proofPath, $metadata, $resources);

    $cacheDir = $resources['cache-dir'][0];
    $outputDir = $resources['output-dir'][0];
    $proofDir = $resources['lease-proof-dir'][0];
    $expectedRoots = array_map(static fn(string $scenario): string => $cacheDir . '/instances/' . $scenario, $scenarios);
    $expectedBackups = array_map(static fn(string $scenario): string => $cacheDir . '/backups/' . $scenario, $scenarios);
    if (!isLexicallyAbsolutePath($cacheDir)
        || !isLexicallyAbsolutePath($outputDir)
        || !isLexicallyAbsolutePath($proofDir)
        || $outputDir !== rtrim($metadata['worktree'], '/') . '/output/cr03-upgrade-' . $runId
        || !str_ends_with($cacheDir, '/.cache/peanut-admin/cr03-upgrade-' . $runId)
        || !str_ends_with($proofDir, '/peanut-admin-resource-leases/leases/' . $metadata['lease'])) {
        throw new RuntimeException('consumer-upgrade lease path identity 不匹配精确 run_id 合同');
    }
    assertLeaseResourceValues($resources, 'instance-root', $expectedRoots);
    assertLeaseResourceValues($resources, 'backup-root', $expectedBackups);
    $expectedMode = $identity['scenario'] === 'multi_tenant_upgrade' ? 'multi-tenant' : 'standalone';
    if (!hash_equals($expectedMode, $deploymentMode)) {
        throw new RuntimeException('consumer-upgrade deployment mode 与 database scenario 不匹配');
    }
}

/** @return array{environment:string,deployment_target:string,resource_id:string,endpoint_id:string,consumer:string,host:string,port:string,database:string,user:string,password:string} */
/** 源码与生成 APP 共用此门禁；资源路径只由严格的项目／实例身份读取器选择。 */
function guardedDatabaseConfig(?string $leaseProofPath = null, ?int $now = null): array
{
    $registry = projectResourceRegistry($registrySha256);
    $environment = requiredEnvironment('APP_ENV');
    $deploymentTarget = requiredMaintenanceEnvironment('PEANUT_DEPLOYMENT_TARGET');
    $target = deploymentTargetContract($deploymentTarget);
    if (!hash_equals($target['app_environment'], $environment)) {
        throw new RuntimeException("APP_ENV={$environment} 与部署目标 {$deploymentTarget} 不匹配");
    }

    $resourceId = requiredMaintenanceEnvironment('PEANUT_DATABASE_RESOURCE_ID');
    $database = registeredDatabase($registry, $resourceId);
    $registeredName = $database['database'] ?? null;
    $isTemplated = is_string($registeredName)
        && (str_contains($registeredName, '<run_id>') || str_contains($registeredName, '<scenario>'));
    $environments = $database['environments'] ?? [$database['environment'] ?? null];
    $resourceEnvironment = $isTemplated ? 'development' : $target['resource_environment'];
    if (!is_array($environments) || !in_array($resourceEnvironment, $environments, true)) {
        throw new RuntimeException("数据库资源 {$resourceId} 未登记为 {$target['resource_environment']} 环境");
    }
    if (($database['service_type'] ?? null) !== 'mysql' || ($database['fallback'] ?? null) !== 'none') {
        throw new RuntimeException("数据库资源 {$resourceId} 的服务类型或 fallback 不符合门禁");
    }

    $consumer = requiredMaintenanceEnvironment('PEANUT_DATABASE_CONSUMER');
    $endpoint = registeredDatabaseEndpoint($database, $consumer);
    $configuredEndpointId = getenv('PEANUT_DATABASE_ENDPOINT_ID');
    if ($configuredEndpointId !== false && trim($configuredEndpointId) !== ''
        && !hash_equals((string) $endpoint['endpoint_id'], trim($configuredEndpointId))) {
        throw new RuntimeException("数据库资源 {$resourceId} 的 endpoint identity 不匹配登记值");
    }

    $actual = [
        'host' => requiredEnvironment('DB_HOST', '127.0.0.1'),
        'port' => requiredEnvironment('DB_PORT', '3306'),
        'database' => requiredEnvironment('DB_NAME'),
    ];
    if (!hash_equals((string) $endpoint['host'], $actual['host'])
        || !hash_equals((string) $endpoint['port'], $actual['port'])) {
        throw new RuntimeException("数据库资源 {$resourceId} 的地址不匹配登记值");
    }
    $deploymentMode = requiredEnvironment('DEPLOYMENT_MODE');
    if ($isTemplated) {
        if (($database['lease_policy']['mode'] ?? null) === 'schema') {
            throw new RuntimeException('qualification database retains server exclusivity');
        }
        if (($database['application_runtime'] ?? null) !== false
            || ($database['lifecycle'] ?? null) !== 'ephemeral') {
            throw new RuntimeException('templated database 必须是登记的 ephemeral qualification resource');
        }
        $identity = templatedDatabaseIdentity($database, $actual['database']);
        if ($resourceId === 'peanut-admin-p0e-mysql84-gate') {
            if ($deploymentTarget !== 'local-production-preview' || !in_array($consumer, ['host', 'container'], true)) {
                throw new RuntimeException('P0-E 只允许固定 production-preview 容器 lease proof');
            }
            if ($leaseProofPath === null) {
                $leaseProofPath = requiredMaintenanceEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
                if ($leaseProofPath !== '/run/peanut-admin/resource-lease') {
                    throw new RuntimeException('P0-E 容器必须使用固定只读 active-lease proof mount');
                }
            }
            $metadata = activeLeaseMetadata($leaseProofPath, $now ?? time(), 'p0e-runtime-qualification');
            $resources = resolvedP0eLeaseResources($leaseProofPath, $metadata, $now ?? time(), $consumer);
            $registrySha256 = p0eRegistryProofSha256($leaseProofPath, $registry, $database, $registrySha256);
            assertP0eLeaseContract($metadata, $resources, $database, $endpoint, $identity, $resourceId, $deploymentTarget, $deploymentMode, $registrySha256);
        } elseif ($resourceId === 'peanut-admin-consumer-upgrade-mysql84-gate') {
            if ($deploymentTarget !== 'local-development' || $consumer !== 'host') {
                throw new RuntimeException('consumer-upgrade 只允许 lease-bound local-development Host execution');
            }
            $leaseProofPath ??= requiredMaintenanceEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
            $metadata = activeLeaseMetadata($leaseProofPath, $now ?? time(), 'consumer-upgrade-qualification');
            if ($metadata['parent_lease'] !== '') {
                throw new RuntimeException('consumer-upgrade 不允许继承 parent lease');
            }
            $resources = activeLeaseResources($leaseProofPath);
            assertConsumerUpgradeLeaseContract($leaseProofPath, $metadata, $resources, $database, $identity, $resourceId, $deploymentTarget, $deploymentMode);
        } else {
            throw new RuntimeException('templated database resource 未获 qualification guard 授权');
        }
    } elseif ($deploymentTarget === 'development-test') {
        if ($consumer !== 'host' || ($database['application_runtime'] ?? null) !== false
            || ($database['lifecycle'] ?? null) !== 'ephemeral'
            || !is_string($database['lease_gate'] ?? null)) {
            throw new RuntimeException('development-test 必须使用已登记的临时 Host 资源');
        }
        $names = [$registeredName];
        foreach (($database['synthetic_databases'] ?? []) as $group) {
            if (!is_array($group) || !array_is_list($group)) {
                throw new RuntimeException('synthetic database 登记格式无效');
            }
            $names = [...$names, ...$group];
        }
        foreach ($names as $name) {
            if (!is_string($name) || preg_match('/^[a-z0-9_]{1,64}$/D', $name) !== 1) {
                throw new RuntimeException('synthetic database 必须登记精确名称');
            }
        }
        if (!in_array($actual['database'], $names, true)
            || !in_array($deploymentMode, $database['deployment_modes'] ?? [], true)) {
            throw new RuntimeException('development-test database 或 deployment mode 未登记');
        }
        $leaseProofPath ??= requiredMaintenanceEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
        $metadata = activeLeaseMetadata($leaseProofPath, $now ?? time(), $database['lease_gate']);
        if ($metadata['parent_lease'] !== '') {
            throw new RuntimeException('development-test 不允许继承 parent lease');
        }
        $resources = activeLeaseResources($leaseProofPath);
        $root = realpath(dirname(__DIR__, 2));
        $common = leaseGit(['-C', (string) $root, 'rev-parse', '--path-format=absolute', '--git-common-dir']);
        $expectedProof = $common . '/peanut-admin-resource-leases/leases/' . $metadata['lease'];
        $schemaResource = mysqlSchemaLeaseResource($database, $endpoint, $actual);
        if ($schemaResource !== null) {
            assertNativeMysqlSchemaLease(
                (string) $root,
                $leaseProofPath,
                $metadata,
                $resources,
                $resourceId,
                $schemaResource,
                (string) $endpoint['endpoint_id'],
                (string) $registrySha256,
            );
        }
        if ($metadata['owner'] !== ($database['owner'] ?? null)
            || realpath($metadata['worktree']) !== $root
            || realpath($metadata['candidate_repository']) !== $root
            || realpath($leaseProofPath) !== realpath($expectedProof)
            || !in_array($resourceId, $resources['database-resource'] ?? [], true)
            || !in_array($actual['database'], $resources['mysql-db'] ?? [], true)
            || ($schemaResource === null
                ? !in_array($actual['port'], $resources['port'] ?? [], true)
                : !in_array($schemaResource, $resources['mysql-schema'] ?? [], true))) {
            throw new RuntimeException('development-test active lease 与当前资源/工作树不匹配');
        }
    } else {
        if (!is_string($registeredName) || !hash_equals($registeredName, $actual['database'])) {
            throw new RuntimeException("数据库资源 {$resourceId} 的 database 不匹配固定登记值");
        }
        $deploymentModes = $database['deployment_modes'] ?? ['standalone'];
        if (!is_array($deploymentModes)
            || $deploymentModes === []
            || array_filter($deploymentModes, static fn(mixed $mode): bool => !is_string($mode) || $mode === '') !== []) {
            throw new RuntimeException("数据库资源 {$resourceId} 的 deployment_modes 登记无效");
        }
        if (!in_array($deploymentMode, $deploymentModes, true)) {
            throw new RuntimeException("数据库资源 {$resourceId} 不允许 DEPLOYMENT_MODE={$deploymentMode}");
        }
        if (isset($database['lease_policy']['shared_access'])) {
            if ($deploymentTarget !== 'local-development' || $consumer !== 'host') {
                throw new RuntimeException('shared schema runtime 只允许登记的 local-development Host');
            }
        }
    }

    return [
        'environment' => $environment,
        'deployment_target' => $deploymentTarget,
        'resource_id' => $resourceId,
        'endpoint_id' => (string) $endpoint['endpoint_id'],
        'consumer' => $consumer,
        ...$actual,
        'user' => requiredEnvironment('DB_USER'),
        'password' => requiredEnvironment('DB_PASS'),
    ];
}

/**
 * Closed acceptance observation of persisted collaboration metadata, not a runtime
 * deployment target or a general SQL interface. The caller must bind independently
 * known identities in its own native observation role; a discovered instance cannot
 * authorize room queries. The real same-subject runtime parent retains its original
 * candidate and physical locks; the child separately owns the reviewed source.
 * No connection, SQL callback or transaction escapes.
 *
 * @param array{owner:string,thread:string,candidate:string,instance_id:?string,service_instance_id:?string,receipt_path:string} $actor
 * @return array<string,mixed>
 */
function observeRegisteredCollaborationRuntime(array $actor): array
{
    $keys = array_keys($actor);
    sort($keys, SORT_STRING);
    if (PHP_SAPI !== 'cli'
        || $keys !== ['candidate', 'instance_id', 'owner', 'receipt_path', 'service_instance_id', 'thread']
        || !is_string($actor['owner']) || $actor['owner'] === ''
        || !is_string($actor['thread']) || $actor['thread'] === ''
        || !is_string($actor['candidate']) || preg_match('/^[a-f0-9]{40}$/D', $actor['candidate']) !== 1
        || !is_string($actor['receipt_path']) || !isLexicallyAbsolutePath($actor['receipt_path'])
        || realpath(dirname($actor['receipt_path'])) !== dirname($actor['receipt_path'])
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.json$/D', basename($actor['receipt_path'])) !== 1
        || file_exists($actor['receipt_path']) || is_link($actor['receipt_path'])) {
        throw new RuntimeException('COLLABORATION_OBSERVATION_ACTOR_INVALID');
    }
    foreach (['pcntl_alarm','pcntl_signal','pcntl_signal_get_handler','pcntl_async_signals','posix_geteuid','fsync'] as $capability) {
        if (!function_exists($capability)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_WINDOW_CAPABILITY_REQUIRED');
        }
    }
    $confirmed = $actor['instance_id'] !== null && $actor['service_instance_id'] !== null;
    foreach (['instance_id', 'service_instance_id'] as $key) {
        if (($actor[$key] !== null && (!is_string($actor[$key])
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $actor[$key]) !== 1))
            || (($actor[$key] !== null) !== $confirmed)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_INSTANCE_BINDING_INVALID');
        }
    }
    $root = (string) realpath(dirname(__DIR__, 2));
    $registry = projectResourceRegistry($registrySha256);
    $resourceId = requiredMaintenanceEnvironment('PEANUT_DATABASE_RESOURCE_ID');
    $database = registeredDatabase($registry, $resourceId);
    $endpoint = registeredDatabaseEndpoint($database, 'host');
    $name = requiredEnvironment('DB_NAME');
    if (($registry['authority']['role'] ?? null) !== 'maintainer'
        || !in_array('acceptance', $database['environments'] ?? [], true)
        || ($database['application_runtime'] ?? null) !== true
        || ($database['service_type'] ?? null) !== 'mysql' || ($database['fallback'] ?? null) !== 'none'
        || ($database['version'] ?? null) !== '8.4.10'
        || !is_string($database['credential_ref'] ?? null) || $database['credential_ref'] === ''
        || !in_array($name, $database['synthetic_databases']['standalone'] ?? [], true)
        || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1
        || !is_string($database['resource_scope'] ?? null) || $database['resource_scope'] === ''
        || !is_string($database['lease_gate'] ?? null) || $database['lease_gate'] === ''
        || isset($database['lease_policy']['shared_access'])
        || requiredEnvironment('APP_ENV') !== 'development'
        || requiredMaintenanceEnvironment('PEANUT_DEPLOYMENT_TARGET') !== 'local-development'
        || requiredEnvironment('DEPLOYMENT_MODE') !== 'standalone'
        || requiredMaintenanceEnvironment('PEANUT_DATABASE_CONSUMER') !== 'host'
        || requiredMaintenanceEnvironment('PEANUT_DATABASE_ENDPOINT_ID') !== (string) $endpoint['endpoint_id']
        || requiredEnvironment('DB_HOST', '127.0.0.1') !== (string) $endpoint['host']
        || requiredEnvironment('DB_PORT', '3306') !== (string) $endpoint['port']) {
        throw new RuntimeException('COLLABORATION_OBSERVATION_REGISTERED_TARGET_INVALID');
    }
    $config = ['host' => (string) $endpoint['host'], 'port' => (string) $endpoint['port'],
        'database' => $name, 'user' => requiredEnvironment('DB_USER'), 'password' => requiredEnvironment('DB_PASS')];
    $schema = mysqlSchemaLeaseResource($database, $endpoint, $config);
    if ($schema === null) {
        throw new RuntimeException('COLLABORATION_OBSERVATION_SCHEMA_POLICY_REQUIRED');
    }
    $proof = requiredMaintenanceEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
    $windowHandle = null;
    $windowDeadline = null;
    $authorize = static function () use ($root, $proof, $actor, $database, $endpoint, $resourceId, $schema, $registrySha256, $name, &$windowHandle, &$windowDeadline): array {
        $metadata = activeLeaseMetadata($proof, time(), 'collaboration-runtime-observation');
        $resources = activeLeaseResources($proof);
        $common = leaseGit(['-C', $root, 'rev-parse', '--path-format=absolute', '--git-common-dir']);
        if ($metadata['parent_lease'] === '' || realpath($root) !== $root
            || realpath($metadata['candidate_repository']) !== $root || realpath($metadata['worktree']) !== $root
            || leaseGit(['-C', $root, 'rev-parse', '--show-toplevel']) !== $root
            || leaseGit(['-C', $root, 'rev-parse', 'HEAD']) !== $metadata['candidate']
            || realpath($proof) !== $common . '/peanut-admin-resource-leases/leases/' . $metadata['lease']) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_SOURCE_PROOF_MISMATCH');
        }
        foreach (['owner', 'thread', 'candidate'] as $key) {
            if ($metadata[$key] !== $actor[$key]) {
                throw new RuntimeException('COLLABORATION_OBSERVATION_NATIVE_ACTOR_MISMATCH');
            }
        }
        if (array_keys($resources) !== ['gate','lease-role','operation-contract','worktree']) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_ROLE_SCOPE_INVALID');
        }
        $contract = ['protocol' => 'peanut.collaboration-observation-authorization.v1',
            'operation' => 'collaboration-runtime-read-existing', 'resource_id' => $resourceId,
            'endpoint_id' => $endpoint['endpoint_id'], 'database' => $name, 'environment' => 'acceptance', 'consumer' => 'host',
            'resource_scope' => $database['resource_scope'], 'gate' => $database['lease_gate'],
            'registry_sha256' => $registrySha256, 'credential_ref' => $database['credential_ref'],
            'instance_id' => $actor['instance_id'], 'service_instance_id' => $actor['service_instance_id'],
            'source_tree' => leaseGit(['-C', $root, 'rev-parse', 'HEAD^{tree}']), 'receipt_path' => $actor['receipt_path']];
        ksort($contract, SORT_STRING);
        assertLeaseResourceValues($resources, 'gate', ['collaboration-runtime-observation']);
        assertLeaseResourceValues($resources, 'worktree', [$root]);
        assertLeaseResourceValues($resources, 'lease-role', ['collaboration-read-existing']);
        assertLeaseResourceValues($resources, 'operation-contract', [json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        $parentProof = $common . '/peanut-admin-resource-leases/leases/' . $metadata['parent_lease'];
        $parent = activeLeaseMetadata($parentProof, time(), $database['lease_gate']);
        $parentResources = activeLeaseResources($parentProof);
        if ($parent['parent_lease'] !== '' || $parent['owner'] !== $actor['owner'] || $parent['thread'] !== $actor['thread']
            || (int) $metadata['expires_at'] > (int) $parent['expires_at']
            || realpath($parent['candidate_repository']) !== $parent['candidate_repository']
            || $parent['worktree'] !== $parent['candidate_repository']
            || leaseGit(['-C', $parent['worktree'], 'rev-parse', '--show-toplevel']) !== $parent['worktree']
            || leaseGit(['-C', $parent['worktree'], 'rev-parse', '--path-format=absolute', '--git-common-dir']) !== $common
            || leaseGit(['-C', $parent['worktree'], 'rev-parse', 'HEAD']) !== $parent['candidate']
            || !in_array($resourceId, $parentResources['resource-id'] ?? [], true)
            || !in_array($endpoint['endpoint_id'], $parentResources['endpoint'] ?? [], true)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RUNTIME_AUTHORITY_MISMATCH');
        }
        foreach (['database' => $name, 'mysql-db' => $name, 'mysql-schema' => $schema,
            'environment' => 'acceptance', 'registry-sha256' => $registrySha256,
            'resource-scope' => $database['resource_scope']] as $type => $value) {
            assertLeaseResourceValues($parentResources, $type, [$value]);
        }
        $outputs = $parentResources['output-dir'] ?? [];
        if (count($outputs) !== 1 || realpath($outputs[0]) !== $outputs[0]
            || !str_starts_with($actor['receipt_path'], rtrim($outputs[0], '/') . '/')) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_SCOPE_INVALID');
        }
        $requiredUntil = $windowDeadline ?? microtime(true) + 45;
        if ((int) $metadata['expires_at'] <= $requiredUntil || (int) $parent['expires_at'] <= $requiredUntil) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_WINDOW_EXCEEDS_AUTHORITY');
        }
        if (leaseGit(['-C', $root, 'status', '--porcelain=v1', '--untracked-files=no']) !== '') {
            throw new RuntimeException('COLLABORATION_OBSERVATION_SOURCE_CHANGED');
        }
        // Native producer rechecks both identities and every parent/child lock in
        // one mutex. Ordinary assertNativeMysqlSchemaLease remains parentless.
        $command = ['/bin/bash', $root . '/scripts/project-resource-lease', 'show', '--lease', $metadata['lease'], '--verify-locks'];
        $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
        if (is_resource($windowHandle)) {
            $command[] = '--observer-window';
            $descriptors[3] = $windowHandle;
        }
        $process = proc_open($command, $descriptors, $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_NATIVE_VERIFIER_UNAVAILABLE');
        }
        try {
            stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
        } catch (Throwable $error) {
            proc_terminate($process);
            throw $error;
        } finally {
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
        }
        if ($exit !== 0) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_NATIVE_PROOF_REJECTED');
        }
        return $metadata + ['runtime_candidate' => $parent['candidate'], 'runtime_worktree' => $parent['worktree']];
    };
    $metadata = $authorize(); // Complete admission before opening any database connection.
    $common = leaseGit(['-C', $root, 'rev-parse', '--path-format=absolute', '--git-common-dir']);
    $mutex = $common . '/peanut-admin-resource-leases/mutex';
    if (realpath(dirname($mutex)) !== dirname($mutex)) {
        throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_NAMESPACE_INVALID');
    }
    $previousAlarm = pcntl_alarm(0);
    if ($previousAlarm > 0) {
        pcntl_alarm($previousAlarm);
        throw new RuntimeException('COLLABORATION_OBSERVATION_EXISTING_ALARM');
    }
    $pdo = null;
    $mutexIdentity = null;
    $receiptHandle = null;
    $receiptDirectoryHandle = null;
    $receiptIdentity = null;
    $signalHandlers = [];
    $previousAsync = pcntl_async_signals();
    $previousReadTimeout = null;
    $observation = null;
    $failure = null;
    $stage = 'acquire_mutex';
    $selectAttempts = 0;
    $selectCompleted = 0;
    $connected = false;
    $receiptHash = null;
    $closing = false;
    $interruptDelivered = false;
    $ownsMutex = static function () use ($mutex, &$windowHandle, &$mutexIdentity): bool {
        clearstatcache(true, $mutex); clearstatcache(true, $mutex . '/pid');
        $directory = @lstat($mutex); $named = @lstat($mutex . '/pid');
        $open = is_resource($windowHandle) ? fstat($windowHandle) : false;
        return is_array($mutexIdentity) && is_array($directory) && is_array($named) && is_array($open)
            && !is_link($mutex) && !is_link($mutex . '/pid') && $open['nlink'] === 1
            && $directory['dev'] === $mutexIdentity['dev'] && $directory['ino'] === $mutexIdentity['ino']
            && $open['dev'] === $named['dev'] && $open['ino'] === $named['ino']
            && $open['uid'] === posix_geteuid() && ($open['mode'] & 0777) === 0600
            && trim((string) @file_get_contents($mutex . '/pid')) === (string) getmypid();
    };
    $checkWindow = static function () use (&$windowDeadline, $ownsMutex): void {
        if ($windowDeadline === null || microtime(true) >= $windowDeadline || !$ownsMutex()) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_WINDOW_EXPIRED_OR_CHANGED');
        }
    };
    try {
        // Same mkdir/PID protocol and namespace as every historical producer.
        // Only this PHP process executes SQL; there is no surviving worker child.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $mask = umask(0077);
            try { $created = @mkdir($mutex, 0700); }
            finally { umask($mask); }
            if ($created) {
                $mutexIdentity = lstat($mutex);
                $mask = umask(0077);
                try { $windowHandle = @fopen($mutex . '/pid', 'x+b'); }
                finally { umask($mask); }
                if (!is_resource($windowHandle)) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_PID_OPEN_FAILED');
                }
                $pid = getmypid() . "\n";
                if (fwrite($windowHandle, $pid) !== strlen($pid) || !fflush($windowHandle) || !$ownsMutex()) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_PID_INVALID');
                }
                break;
            }
            // Do not remove an unknown/stale holder here. Normal native preflight
            // owns stale-lock recovery; a failed acquisition never cleans others.
            usleep(50000);
        }
        if (!is_resource($windowHandle)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_BUSY_OR_UNAVAILABLE');
        }
        $windowDeadline = microtime(true) + 30;
        foreach ([SIGALRM, SIGTERM, SIGINT, SIGHUP] as $signal) {
            $signalHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $received) use (&$closing, &$interruptDelivered, &$failure): void {
                if ($closing || $interruptDelivered) {
                    $failure ??= new RuntimeException('COLLABORATION_OBSERVATION_INTERRUPTED_DURING_CLOSE');
                    return;
                }
                $interruptDelivered = true;
                throw new RuntimeException($received === SIGALRM
                    ? 'COLLABORATION_OBSERVATION_WINDOW_TIMEOUT' : 'COLLABORATION_OBSERVATION_INTERRUPTED');
            }, false);
        }
        pcntl_async_signals(true);
        pcntl_alarm(30);
        $stage = 'locked_authorization';
        $metadata = $authorize();
        $checkWindow();
        $stage = 'open_receipt';
        $receiptDirectoryHandle = @fopen(dirname($actor['receipt_path']), 'r');
        if (!is_resource($receiptDirectoryHandle)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_DIRECTORY_UNAVAILABLE');
        }
        $mask = umask(0077);
        try { $receiptHandle = @fopen($actor['receipt_path'], 'x+b'); }
        finally { umask($mask); }
        if (!is_resource($receiptHandle)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_OPEN_FAILED');
        }
        $receiptIdentity = fstat($receiptHandle);
        $namedReceipt = lstat($actor['receipt_path']);
        if (is_link($actor['receipt_path']) || $receiptIdentity['nlink'] !== 1
            || $receiptIdentity['dev'] !== $namedReceipt['dev'] || $receiptIdentity['ino'] !== $namedReceipt['ino']
            || $receiptIdentity['uid'] !== posix_geteuid() || ($receiptIdentity['mode'] & 0777) !== 0600) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_ENTITY_INVALID');
        }
        $receiptDirectory = fstat($receiptDirectoryHandle);
        $namedDirectory = lstat(dirname($actor['receipt_path']));
        if ($receiptDirectory['dev'] !== $namedDirectory['dev'] || $receiptDirectory['ino'] !== $namedDirectory['ino']
            || realpath(dirname($actor['receipt_path'])) !== dirname($actor['receipt_path'])
            || !fflush($receiptHandle) || !fsync($receiptHandle) || !fsync($receiptDirectoryHandle)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_DIRECTORY_CHANGED');
        }
        // These bound connection/read/query limits complement the process alarm;
        // alarm availability alone is not a hard bound on blocking PDO I/O.
        $previousReadTimeout = ini_set('mysqlnd.net_read_timeout', '3');
        if ($previousReadTimeout === false) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_READ_TIMEOUT_UNAVAILABLE');
        }
        $stage = 'connect';
        $checkWindow();
        $pdo = guardedConnection($config, 3);
        $connected = true;
        $checkWindow();
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME=2000');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $stage = 'database_identity';
        $checkWindow(); $selectAttempts++;
        $identity = $pdo->query('SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,@@version AS version,UTC_TIMESTAMP(3) AS observed_at')->fetch(PDO::FETCH_ASSOC);
        $selectCompleted++; $checkWindow();
        if (!is_array($identity) || $identity['database_name'] !== $name
            || !is_string($identity['server_uuid'])
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $identity['server_uuid']) !== 1
            || $identity['version'] !== $database['version']) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_DATABASE_IDENTITY_MISMATCH');
        }
        // All identifiers and expressions below are source constants; only values bind.
        $columns = [
            'pa_collaboration_runtime' => ['instance_id','edition','phase','config_revision','config_sha256','drain_id','updated_at','plan_json','receipt_json'],
            'pa_collaboration_room' => ['id','tenant_id','document_id','instance_id','edition','protocol_version','room_key','service_instance_id','holder_key','lease_epoch','lease_expires_at','status','accepted_through','persisted_through','pending_update_count','persisted_state_seq','baseline_sha256','last_target_progress','last_store_id','last_store_sha256','updated_at'],
            'pa_collaboration_barrier' => ['room_id','tenant_id','barrier_key','lease_epoch','status','created_at','expires_at','released_at','committed_state_seq','business_sha256','business_plan_json','drained_receipt_json','business_result_json'],
            'pa_document_operation' => ['tenant_id','document_id','operation_id','action','phase','input_sha256','business_sha256','created_at','released_at','result_json'],
        ];
        $schemaEvidence = [];
        $checkColumns = static function (string $table) use ($pdo, $columns, $name, &$schemaEvidence, $checkWindow, &$stage, &$selectAttempts, &$selectCompleted): void {
            $stage = 'columns_' . $table;
            $checkWindow();
            $wanted = $columns[$table];
            $query = $pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME IN (' . implode(',', array_fill(0, count($wanted), '?')) . ') ORDER BY ORDINAL_POSITION');
            $checkWindow(); $selectAttempts++;
            $query->execute([$name, $table, ...$wanted]);
            $actual = $query->fetchAll(PDO::FETCH_ASSOC);
            $query->closeCursor();
            $selectCompleted++; $checkWindow();
            if (array_diff($wanted, array_column($actual, 'COLUMN_NAME')) !== []) {
                throw new RuntimeException('COLLABORATION_OBSERVATION_SCHEMA_GAP: ' . $table);
            }
            foreach ($actual as $column) {
                if (str_ends_with($column['COLUMN_NAME'], '_json') && $column['DATA_TYPE'] !== 'json') {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_SCHEMA_TYPE_GAP: ' . $table);
                }
            }
            $schemaEvidence[$table] = $actual;
        };
        $checkColumns('pa_collaboration_runtime');
        $runtimeSql = <<<'SQL'
SELECT instance_id,edition,phase,config_revision,config_sha256,drain_id,updated_at,
 JSON_VALUE(plan_json,'$.candidate.service_instance_id' ERROR ON ERROR) AS service_instance_id,
 JSON_VALUE(plan_json,'$.candidate.holder_key' ERROR ON ERROR) AS holder_key,
 JSON_VALUE(plan_json,'$.candidate.probe_id' ERROR ON ERROR) AS probe_id,
 JSON_VALUE(plan_json,'$.activation.activation_id' ERROR ON ERROR) AS activation_id,
 JSON_VALUE(plan_json,'$.process_lease_expires_at_ms' RETURNING UNSIGNED ERROR ON ERROR) AS process_lease_expires_at_ms,
 JSON_VALUE(receipt_json,'$.protocol' ERROR ON ERROR) AS receipt_protocol,
 JSON_VALUE(receipt_json,'$.drain_id' ERROR ON ERROR) AS receipt_drain_id,
 JSON_VALUE(receipt_json,'$.plan_sha256' ERROR ON ERROR) AS receipt_plan_sha256,
 JSON_VALUE(receipt_json,'$.targets_sha256' ERROR ON ERROR) AS receipt_targets_sha256,
 JSON_VALUE(receipt_json,'$.durably_drained' ERROR ON ERROR) AS durably_drained,
 JSON_VALUE(receipt_json,'$.room_count' RETURNING UNSIGNED ERROR ON ERROR) AS receipt_room_count
FROM pa_collaboration_runtime WHERE edition='standalone'
SQL;
        $runtimeQuery = $pdo->prepare($runtimeSql . ($confirmed ? ' AND instance_id=?' : '') . ' ORDER BY instance_id LIMIT 2');
        $stage = 'runtime'; $checkWindow(); $selectAttempts++;
        $runtimeQuery->execute($confirmed ? [$actor['instance_id']] : []);
        $runtime = $runtimeQuery->fetchAll(PDO::FETCH_ASSOC);
        $runtimeQuery->closeCursor();
        $selectCompleted++; $checkWindow();
        $result = ['protocol' => 'peanut.collaboration-runtime-observation.v1', 'operation_environment' => 'acceptance',
            'resource_id' => $resourceId, 'endpoint_id' => $endpoint['endpoint_id'], 'scope' => $database['resource_scope'],
            'candidate' => $actor['candidate'], 'lease' => $metadata['lease'], 'owner' => $actor['owner'], 'thread' => $actor['thread'],
            'runtime_candidate' => $metadata['runtime_candidate'], 'runtime_worktree' => $metadata['runtime_worktree'],
            'registry_sha256' => $registrySha256, 'identity' => $identity, 'runtime' => $runtime,
            'expected_server_uuid' => null, 'server_uuid_verification' => 'observed_only_no_expected_identity_asserted',
            'credential_permissions' => 'Not asserted SELECT-only; this closed operation executes fixed reads only.',
            'runtime_observed_count' => count($runtime), 'runtime_limit' => 2,
            'status' => 'identity_confirmation_required', 'associated_queries' => 'not_run',
            'proof_limit' => 'Persisted metadata only; Node memory, loaded code, captures and original failure/operation budgets remain unknown.'];
        if ($confirmed) {
            if (count($runtime) !== 1 || $runtime[0]['instance_id'] !== $actor['instance_id']
                || $runtime[0]['service_instance_id'] !== $actor['service_instance_id']) {
                throw new RuntimeException('COLLABORATION_OBSERVATION_EXPECTED_INSTANCE_MISMATCH');
            }
            foreach (['pa_collaboration_room','pa_collaboration_barrier','pa_document_operation'] as $table) {
                $checkColumns($table);
            }
            $queries = [
                'rooms' => <<<'SQL'
SELECT id,tenant_id,document_id,room_key,service_instance_id,holder_key,lease_epoch,lease_expires_at,status,
 accepted_through,persisted_through,pending_update_count,persisted_state_seq,baseline_sha256,last_target_progress,
 last_store_id,last_store_sha256,updated_at
FROM pa_collaboration_room WHERE instance_id=? AND edition='standalone' AND protocol_version=2 ORDER BY id LIMIT 10001
SQL,
                'unreleased_barriers' => <<<'SQL'
SELECT B.barrier_key,B.lease_epoch,B.status,B.created_at,B.expires_at,B.released_at,B.committed_state_seq,B.business_sha256,
 JSON_VALUE(B.business_plan_json,'$.operation_id' ERROR ON ERROR) AS operation_id,
 JSON_VALUE(B.business_plan_json,'$.input_sha256' ERROR ON ERROR) AS input_sha256,
 JSON_VALUE(B.business_plan_json,'$.holder_key' ERROR ON ERROR) AS holder_key,
 JSON_VALUE(B.business_plan_json,'$.lease_epoch' RETURNING UNSIGNED ERROR ON ERROR) AS business_lease_epoch,
 JSON_VALUE(B.business_plan_json,'$.deadline' ERROR ON ERROR) AS deadline,
 SHA2(CAST(B.drained_receipt_json AS CHAR),256) AS drained_receipt_sql_sha256,
 SHA2(CAST(B.business_result_json AS CHAR),256) AS business_result_sql_sha256
FROM pa_collaboration_barrier B JOIN pa_collaboration_room R ON B.room_id=R.id AND B.tenant_id=R.tenant_id
WHERE R.instance_id=? AND R.edition='standalone' AND R.protocol_version=2
 AND B.business_plan_json IS NOT NULL AND B.released_at IS NULL ORDER BY B.tenant_id,B.room_id,B.barrier_key LIMIT 10001
SQL,
                'pending_operations' => <<<'SQL'
SELECT O.operation_id,O.action,O.phase,O.input_sha256,O.business_sha256,O.created_at,O.released_at,
 SHA2(CAST(O.result_json AS CHAR),256) AS result_sql_sha256,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.store_id' ERROR ON ERROR) END AS store_id,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.lease_epoch' RETURNING UNSIGNED ERROR ON ERROR) END AS lease_epoch,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.state_seq' RETURNING UNSIGNED ERROR ON ERROR) END AS state_seq,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.target_progress' RETURNING UNSIGNED ERROR ON ERROR) END AS target_progress,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.binary_sha256' ERROR ON ERROR) END AS binary_sha256
FROM pa_document_operation O WHERE O.action<>'collaboration.target' AND O.phase<>'completed'
 AND EXISTS (SELECT 1 FROM pa_collaboration_room R WHERE R.tenant_id=O.tenant_id AND R.document_id=O.document_id
 AND R.instance_id=? AND R.edition='standalone' AND R.protocol_version=2)
ORDER BY O.tenant_id,O.document_id,O.operation_id LIMIT 10001
SQL,
            ];
            foreach ($queries as $key => $sql) {
                $stage = $key; $checkWindow();
                $query = $pdo->prepare($sql);
                $checkWindow(); $selectAttempts++;
                $query->execute([$actor['instance_id']]);
                $rows = $query->fetchAll(PDO::FETCH_ASSOC);
                $query->closeCursor();
                $selectCompleted++; $checkWindow();
                if (count($rows) >= 10001) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RESULT_LIMIT: ' . $key);
                }
                $result[$key] = ['count' => count($rows), 'rows_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'rows' => $rows];
            }
            $result['room_service_mismatch_count'] = count(array_filter($result['rooms']['rows'],
                static fn(array $row): bool => $row['service_instance_id'] !== $actor['service_instance_id']));
            $result['status'] = 'persisted_metadata_observed';
            $result['associated_queries'] = 'fixed_projection_only';
        }
        $result['schema_columns'] = $schemaEvidence;
        $stage = 'final_authorization'; $checkWindow();
        $authorize(); // Expiry or source/lease drift invalidates the observation.
        $checkWindow();
        $observation = $result;
        $closing = true;
    } catch (Throwable $error) {
        $closing = true;
        $failure = $error;
    } finally {
        // During cleanup, signals mark failure without escaping the close/receipt/
        // unlock sequence. A SIGKILL leaves the native PID lock for stale recovery
        // only after this actual SQL client process has terminated.
        pcntl_async_signals(false);
        pcntl_alarm(0);
        foreach (array_keys($signalHandlers) as $signal) {
            pcntl_signal($signal, static function () use (&$failure): void {
                $failure ??= new RuntimeException('COLLABORATION_OBSERVATION_INTERRUPTED_DURING_CLOSE');
            }, false);
        }
        pcntl_async_signals(true);
        foreach ([$runtimeQuery ?? null, $query ?? null] as $statement) {
            try { if ($statement instanceof PDOStatement) { $statement->closeCursor(); } }
            catch (Throwable $closeError) { $failure ??= $closeError; }
        }
        unset($runtimeQuery, $query, $statement, $checkColumns);
        try {
            if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        } catch (Throwable $closeError) { $failure ??= $closeError; }
        finally { $pdo = null; }
        if ($previousReadTimeout !== null && $previousReadTimeout !== false) {
            ini_set('mysqlnd.net_read_timeout', $previousReadTimeout);
        }
        if (is_resource($windowHandle)) {
            try { $checkWindow(); } catch (Throwable $deadlineError) { $failure ??= $deadlineError; }
        }
        try {
            if (is_resource($receiptHandle)) {
                $payload = ['protocol' => 'peanut.collaboration-observation-receipt.v1',
                    'status' => $failure === null ? 'metadata_recorded' : 'failed', 'candidate' => $actor['candidate'],
                    'runtime_candidate' => $metadata['runtime_candidate'], 'owner' => $actor['owner'], 'thread' => $actor['thread'],
                    'lease' => $metadata['lease'], 'SQL_client_pid' => getmypid(), 'database_connected' => $connected,
                    'window_release' => 'not_yet_released_at_durable_record',
                    'this_invocation_select_attempts' => $selectAttempts, 'this_invocation_select_completed' => $selectCompleted,
                    'failed_stage' => $failure === null ? null : $stage,
                    'error_class' => $failure === null ? null : get_class($failure),
                    'error_code' => $failure === null ? null : (string) $failure->getCode(),
                    'error_reason' => $failure instanceof RuntimeException && str_starts_with($failure->getMessage(), 'COLLABORATION_OBSERVATION_')
                        ? $failure->getMessage() : null,
                    'observation' => $failure === null ? $observation : null];
                $bytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($receiptHandle, substr($bytes, $offset));
                    if ($written === false || $written === 0) { throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_WRITE_FAILED'); }
                    $offset += $written;
                }
                if (!fflush($receiptHandle) || !fsync($receiptHandle)) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_SYNC_FAILED');
                }
                clearstatcache(true, $actor['receipt_path']);
                $named = lstat($actor['receipt_path']); $open = fstat($receiptHandle);
                if (is_link($actor['receipt_path']) || $open['nlink'] !== 1
                    || $open['dev'] !== $receiptIdentity['dev'] || $open['ino'] !== $receiptIdentity['ino']
                    || $open['dev'] !== $named['dev'] || $open['ino'] !== $named['ino'] || $open['size'] !== strlen($bytes)
                    || $open['uid'] !== posix_geteuid() || ($open['mode'] & 0777) !== 0600) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_CHANGED');
                }
                rewind($receiptHandle);
                $receiptHash = hash('sha256', $bytes);
                if (!hash_equals($receiptHash, hash('sha256', (string) stream_get_contents($receiptHandle)))) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_READBACK_FAILED');
                }
                clearstatcache(true, dirname($actor['receipt_path']));
                $namedDirectory = lstat(dirname($actor['receipt_path']));
                $openDirectory = fstat($receiptDirectoryHandle);
                if ($openDirectory['dev'] !== $namedDirectory['dev'] || $openDirectory['ino'] !== $namedDirectory['ino']
                    || $openDirectory['dev'] !== $receiptDirectory['dev'] || $openDirectory['ino'] !== $receiptDirectory['ino']
                    || realpath(dirname($actor['receipt_path'])) !== dirname($actor['receipt_path'])
                    || !fsync($receiptDirectoryHandle)) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_DIRECTORY_SYNC_FAILED');
                }
            }
        } catch (Throwable $receiptError) { $failure = $receiptError; $receiptHash = null; }
        finally {
            if (is_resource($windowHandle)) {
                try { $checkWindow(); } catch (Throwable $deadlineError) { $failure ??= $deadlineError; }
            }
            if (is_resource($receiptHandle)) {
                try { fclose($receiptHandle); } catch (Throwable $closeError) { $failure ??= $closeError; }
            }
            if (is_resource($receiptDirectoryHandle)) {
                try { fclose($receiptDirectoryHandle); } catch (Throwable $closeError) { $failure ??= $closeError; }
            }
            if (is_resource($windowHandle)) {
                if ($ownsMutex()) {
                    $releasedPid = false;
                    try {
                        $releasedPid = unlink($mutex . '/pid');
                        if (!$releasedPid) { throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_PID_RELEASE_FAILED'); }
                    } catch (Throwable $releaseError) { $failure ??= $releaseError; }
                    try { fclose($windowHandle); } catch (Throwable $closeError) { $failure ??= $closeError; }
                    $windowHandle = null;
                    clearstatcache(true, $mutex);
                    $directory = @lstat($mutex);
                    if ($releasedPid && is_array($directory) && !is_link($mutex)
                        && $directory['dev'] === $mutexIdentity['dev'] && $directory['ino'] === $mutexIdentity['ino']
                        && !file_exists($mutex . '/pid')) {
                        try {
                            if (!rmdir($mutex)) { throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_RELEASE_FAILED'); }
                        } catch (Throwable $releaseError) { $failure ??= $releaseError; }
                    } else { $failure ??= new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_ENTITY_CHANGED'); }
                } else {
                    try { fclose($windowHandle); } catch (Throwable $closeError) { $failure ??= $closeError; }
                    $windowHandle = null;
                    $failure ??= new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_ENTITY_CHANGED');
                }
            } elseif ($mutexIdentity !== null) {
                // Only an empty directory just created by this process may be
                // removed after PID-file creation failed.
                clearstatcache(true, $mutex); $directory = @lstat($mutex);
                if (is_array($directory) && !is_link($mutex) && $directory['dev'] === $mutexIdentity['dev']
                    && $directory['ino'] === $mutexIdentity['ino'] && !file_exists($mutex . '/pid')) { @rmdir($mutex); }
            }
            foreach ($signalHandlers as $signal => $handler) { pcntl_signal($signal, $handler, false); }
            pcntl_async_signals($previousAsync);
        }
    }
    if ($failure !== null || $observation === null || $receiptHash === null) {
        throw new RuntimeException('COLLABORATION_OBSERVATION_FAILED; receipt is not a successful observation', 0, $failure);
    }
    return $observation + ['receipt_path' => $actor['receipt_path'], 'receipt_sha256' => $receiptHash];
}

function guardedConnection(array $config, ?int $connectTimeout = null): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if ($connectTimeout !== null) {
        if ($connectTimeout < 1 || $connectTimeout > 5) { throw new RuntimeException('Invalid bounded connection timeout'); }
        $options[PDO::ATTR_TIMEOUT] = $connectTimeout;
    }
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database'],
        ),
        $config['user'],
        $config['password'],
        $options,
    );
}

function waitForDatabase(array $config, int $seconds): PDO
{
    $deadline = time() + $seconds;
    do {
        try {
            return guardedConnection($config);
        } catch (PDOException $exception) {
            if (time() >= $deadline) {
                throw new RuntimeException('数据库资源在等待窗口内不可连接', 0, $exception);
            }
            sleep(2);
        }
    } while (true);
}

/** @return list<string> */
function canonicalBaselineTables(): array
{
    require_once __DIR__ . '/InitialInstallationSource.php';
    $serverRoot = dirname(__DIR__);
    $names = InitialInstallationSource::tables($serverRoot, InitialInstallationSource::installedModules($serverRoot));
    sort($names, SORT_STRING);
    return $names;
}

/** @param array<string,list<string>> $requirements */
function assertRequiredColumns(PDO $pdo, array $requirements): void
{
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
    );
    foreach ($requirements as $table => $expected) {
        $statement->execute([$table]);
        $actual = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($expected, $actual));
        if ($missing !== []) {
            throw new RuntimeException("数据库关键列缺失：{$table}." . implode(', ', $missing));
        }
    }
}

/** @param array<string,list<string>> $requirements */
function assertRequiredIndexes(PDO $pdo, array $requirements): void
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
    );
    foreach ($requirements as $table => $expected) {
        $statement->execute([$table]);
        $actual = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($expected, $actual));
        if ($missing !== []) {
            throw new RuntimeException("数据库关键索引缺失：{$table}." . implode(', ', $missing));
        }
    }
}

/** @return array{baseline_table_count:int,installed_table_count:int,application_migration_count:int,management_member_count:int,menu_count:int,config_count:int,permission_count:int,tenant_count:int,owner_count:int,operator_count:int} */
function assertCurrentDatabase(PDO $pdo): array
{
    $expected = canonicalBaselineTables();
    // The native command defaults to "migrations"; this application's database
    // configuration sets only DB_PREFIX and does not override migration_table.
    $migrationTable = requiredEnvironment('DB_PREFIX', 'pa_') . 'migrations';
    $expected[] = $migrationTable;
    $actual = array_map('strval', $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
    )->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($expected, $actual));
    if ($missing !== []) {
        throw new RuntimeException('数据库缺少初始应用表：' . implode(', ', $missing));
    }

    assertRequiredColumns($pdo, [
        'pa_account' => ['id', 'status', 'security_revision'],
        'pa_credential' => ['account_id', 'identifier_normalized', 'secret_hash', 'status'],
        'pa_tenant' => ['id', 'code', 'status', 'authorization_revision'],
        'pa_tenant_member' => ['tenant_id', 'account_id', 'status', 'authorization_revision'],
        'pa_permission' => ['key', 'module_key', 'status'],
        'pa_system_menu' => ['id', 'type', 'perms', 'component'],
        'pa_config' => ['type', 'name', 'value'],
        $migrationTable => ['version', 'migration_name', 'start_time', 'end_time', 'breakpoint'],
    ]);
    assertRequiredIndexes($pdo, [
        'pa_tenant' => ['PRIMARY', 'uk_tenant_code'],
        'pa_tenant_member' => ['PRIMARY', 'uk_tenant_member_account'],
        'pa_permission' => ['PRIMARY', 'uk_permission_key'],
        'pa_system_menu' => ['PRIMARY'],
        'pa_config' => ['PRIMARY', 'uk_type_name'],
        $migrationTable => ['PRIMARY'],
    ]);

    $menuCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_system_menu')->fetchColumn();
    $configCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_config')->fetchColumn();
    $permissionCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM pa_permission WHERE status = 'active'",
    )->fetchColumn();
    $quotedMigrationTable = '`' . str_replace('`', '``', $migrationTable) . '`';
    $appliedVersions = array_map('strval', $pdo->query('SELECT version FROM ' . $quotedMigrationTable)->fetchAll(PDO::FETCH_COLUMN));
    $expectedVersions = [];
    foreach (\app\common\module\ModuleMigrationRun::sources([], dirname(__DIR__), true) as $source) {
        $expectedVersions[] = $source['migration_key'];
    }
    if ($expectedVersions === [] || array_diff($expectedVersions, $appliedVersions) !== []) {
        throw new RuntimeException('数据库原生迁移尚未完成');
    }
    $applicationMigrationCount = count($appliedVersions);
    $tenantCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM pa_tenant WHERE code = 'default' AND status = 'active'",
    )->fetchColumn();
    $ownerCount = (int) $pdo->query(<<<'SQL'
SELECT COUNT(DISTINCT tm.id)
FROM pa_tenant t
JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.status = 'active'
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE t.code = 'default' AND t.status = 'active' AND r.`key` = 'core.tenant-owner'
SQL)->fetchColumn();
    $operatorCount = (int) $pdo->query(<<<'SQL'
SELECT COUNT(DISTINCT po.id)
FROM pa_platform_operator po
JOIN pa_account a ON a.id = po.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
JOIN pa_platform_role pr ON pr.id = por.platform_role_id
WHERE po.status = 'active'
  AND pr.`key` = 'platform.bootstrap-owner'
  AND pr.is_builtin = 1
  AND pr.status = 'active'
SQL)->fetchColumn();
    $deploymentMode = requiredEnvironment('DEPLOYMENT_MODE');
    if (!in_array($deploymentMode, ['standalone', 'multi-tenant'], true)) {
        throw new RuntimeException('DEPLOYMENT_MODE 必须是 standalone 或 multi-tenant');
    }
    if ($menuCount < 1
        || $configCount < 1
        || $permissionCount < 1
        || $tenantCount !== 1
        || $ownerCount < 1
        || ($deploymentMode === 'multi-tenant' && $operatorCount < 1)) {
        throw new RuntimeException('数据库基线数据不完整');
    }

    return [
        'baseline_table_count' => count($expected),
        'installed_table_count' => count($actual),
        'application_migration_count' => $applicationMigrationCount,
        'management_member_count' => $ownerCount,
        'menu_count' => $menuCount,
        'config_count' => $configCount,
        'permission_count' => $permissionCount,
        'tenant_count' => $tenantCount,
        'owner_count' => $ownerCount,
        'operator_count' => $operatorCount,
    ];
}

/** @param list<string> $arguments */
function environmentGuardMain(array $arguments): int
{
    try {
        $config = guardedDatabaseConfig();
        $validateConfigOnly = in_array('--validate-config', $arguments, true);
        $wait = 0;
        foreach ($arguments as $argument) {
            if (preg_match('/^--wait=(\d+)$/D', (string) $argument, $matches) === 1) {
                $wait = min(300, max(0, (int) $matches[1]));
            }
        }
        $result = [
            'environment' => $config['environment'],
            'deployment_target' => $config['deployment_target'],
            'resource_id' => $config['resource_id'],
            'endpoint_id' => $config['endpoint_id'],
            'consumer' => $config['consumer'],
            'host' => $config['host'],
            'port' => (int) $config['port'],
            'database' => $config['database'],
            'status' => 'validated',
        ];
        if (!$validateConfigOnly) {
            $pdo = $wait > 0 ? waitForDatabase($config, $wait) : guardedConnection($config);
            $result['status'] = 'connected';
            if (in_array('--current', $arguments, true)) {
                $result += assertCurrentDatabase($pdo);
                $result['status'] = 'current';
            }
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, '数据库环境门禁失败：' . $exception->getMessage() . PHP_EOL);
        return 1;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(environmentGuardMain($_SERVER['argv'] ?? []));
}
