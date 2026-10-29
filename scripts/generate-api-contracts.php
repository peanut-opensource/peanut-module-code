#!/usr/bin/env php
<?php

declare(strict_types=1);

const API_CONTRACT_GENERATOR_VERSION = '3';

$toolRoot = dirname(__DIR__);
$repositoryRoot = $toolRoot;
$applicationRoot = null;
$projectRoot = null;
$checkOnly = false;
$catalogOutput = null;
$outputRootArgument = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--application-root=')) {
        $applicationRoot = substr($argument, strlen('--application-root='));
    } elseif (str_starts_with($argument, '--project-root=')) {
        $projectRoot = substr($argument, strlen('--project-root='));
    } elseif ($argument === '--check') {
        $checkOnly = true;
    } elseif (str_starts_with($argument, '--catalog-output=')) {
        $catalogOutput = substr($argument, strlen('--catalog-output='));
    } elseif (str_starts_with($argument, '--output-root=')) {
        $outputRootArgument = substr($argument, strlen('--output-root='));
    } else {
        fwrite(STDERR, "generate-api-contracts: unknown argument: {$argument}\n");
        exit(2);
    }
}
if ($applicationRoot !== null) {
    $resolved = str_starts_with($applicationRoot, '/') ? realpath($applicationRoot) : false;
    if ($resolved === false || !is_dir($resolved) || is_link($applicationRoot)) {
        fwrite(STDERR, "generate-api-contracts: --application-root must be an existing absolute application directory\n");
        exit(2);
    }
    $repositoryRoot = $resolved;
}
$outputRoot = $repositoryRoot;
if ($outputRootArgument !== null) {
    if ($checkOnly) {
        fwrite(STDERR, "generate-api-contracts: --output-root cannot be combined with --check\n");
        exit(2);
    }
    try {
        $outputRoot = resolveIsolatedOutputRoot($outputRootArgument, $repositoryRoot);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'generate-api-contracts: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
$resolvedProjectRoot = null;
// A generated application owns its generated API artifacts and does not need
// access to the maintainer's private Project repository. Mirroring is explicit.
if (!$checkOnly && $projectRoot !== null) {
    if ($projectRoot === '') {
        fwrite(STDERR, "generate-api-contracts: --project-root must not be empty\n");
        exit(1);
    }
    $resolvedProjectRoot = realpath($projectRoot);
    if ($resolvedProjectRoot === false) {
        fwrite(STDERR, "generate-api-contracts: Project root is unavailable: {$projectRoot}\n");
        exit(1);
    }
}

require_once $repositoryRoot . '/server/route/registry_source.php';

try {
    $inventory = peanut_route_endpoint_inventory($repositoryRoot . '/server');
    $declarations = loadContract($repositoryRoot, $inventory['modules']);
    $routes = indexRoutes($inventory['endpoints']);
    // Types retain each selected Module's complete declaration, including optional
    // integrations. Runtime documentation still describes only selected routes.
    $modulePaths = array_column(array_merge(...array_values($declarations['module_operations'])), 'path');
    $typeContract = applyRouteMetadata(selectContractModules($declarations['contract'], array_keys($inventory['modules']), $modulePaths), $routes);
    $contract = selectContractModules($typeContract, array_keys($inventory['modules']));
    validateContract($contract, $routes);
    $catalog = buildCatalog($repositoryRoot, $contract, $inventory);

    if ($checkOnly) {
        if ($catalogOutput !== null) {
            if ($catalogOutput === '' || !str_starts_with($catalogOutput, '/')) {
                throw new RuntimeException('--catalog-output must be an absolute path');
            }
            writeJson($catalogOutput, $catalog);
        }
        printf(
            "API-CONTRACT-CHECK-001 passed: %d runtime routes, %d generated operations (%d complete, %d partial), %d route-only gaps, %d owners\n",
            $catalog['summary']['route_count'],
            $catalog['summary']['generated_api_operations'],
            $catalog['summary']['complete_operations'],
            $catalog['summary']['partial_operations'],
            $catalog['summary']['route_only_operations'],
            $catalog['summary']['owners'],
        );
        exit(0);
    }

    $typeGenerator = $toolRoot . '/web/node_modules/openapi-typescript/bin/cli.js';
    if (!is_file($typeGenerator)) {
        throw new RuntimeException('Install the web package lock before generating API types; openapi-typescript is unavailable.');
    }
    $generatedContract = generatedArtifactPath($outputRoot, 'server/generated/openapi.json');
    writeJson($generatedContract, $contract);
    if ($resolvedProjectRoot !== null) {
        writeJson(generatedArtifactPath($resolvedProjectRoot, 'docs/api/openapi.yaml'), $contract);
    }
    writeJson(generatedArtifactPath($outputRoot, 'server/generated/api-catalog.json'), $catalog);
    writeModuleTypes($repositoryRoot, $outputRoot, $typeContract, $routes, $declarations['module_operations']);
    $generatedTypes = generatedArtifactPath($outputRoot, 'web/src/generated/openapi.d.ts');

    // Execute the already installed, locked tool; no global package manager,
    // dependency download, shell interpolation, or private checkout is needed.
    $process = proc_open([
        'node', $typeGenerator,
        '--output', $generatedTypes,
    ], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $repositoryRoot . '/web');
    if (!is_resource($process)) {
        throw new RuntimeException('openapi-typescript process could not be started');
    }
    fwrite($pipes[0], json_encode($typeContract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException("openapi-typescript failed with exit {$status}");
    }

    printf(
        "API-GENERATION-001 passed: %d runtime routes, %d documented operations, %d owners\n",
        $catalog['summary']['runtime_routes'],
        $catalog['summary']['documented_operations'],
        $catalog['summary']['owners'],
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'generate-api-contracts: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** @return array{contract:array<string,mixed>,module_operations:array<string,list<array{method:string,path:string,operationId:string}>>} */
function loadContract(string $repositoryRoot, array $modules): array
{
    $source = $repositoryRoot . '/server/app/api/metadata/contracts/openapi.json';
    $contract = json_decode((string) file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($contract)) {
        throw new RuntimeException('Structured OpenAPI source must be an object');
    }

    $releaseSource = $repositoryRoot . '/release-versions.json';
    if (!is_file($releaseSource) || is_link($releaseSource)) {
        throw new RuntimeException('Release version source must be a regular non-symlink file');
    }
    $release = json_decode((string) file_get_contents($releaseSource), true, 512, JSON_THROW_ON_ERROR);
    $productVersion = $release['source_product_version'] ?? null;
    if (!is_string($productVersion) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $productVersion) !== 1) {
        throw new RuntimeException('release-versions.json source_product_version is invalid');
    }
    $contract['info']['version'] = $productVersion;
    $contract['info']['x-peanut-contract-generator'] = [
        'name' => 'scripts/generate-api-contracts.php',
        'version' => API_CONTRACT_GENERATOR_VERSION,
        'release_source' => 'release-versions.json#source_product_version',
    ];

    foreach (hostMetadataFiles($repositoryRoot) as $fragmentPath) {
        $contract = mergeContractFragment($contract, $fragmentPath, true);
    }
    $moduleOperations = [];
    foreach ($modules as $moduleKey => $root) {
        $fragmentPath = $repositoryRoot . '/' . $root . '/api/metadata/openapi.php';
        if (is_file($fragmentPath)) {
            $previousPaths = $contract['paths'];
            $contract = mergeContractFragment($contract, $fragmentPath, false);
            foreach ($contract['paths'] as $path => $pathItem) {
                foreach ($pathItem as $method => $operation) {
                    if (!is_array($operation) || !isset($operation['operationId'])
                        || isset($previousPaths[$path][$method])) {
                        continue;
                    }
                    $moduleOperations[$moduleKey][] = [
                        'method' => strtolower((string) $method),
                        'path' => openApiPath((string) $path),
                        'operationId' => (string) $operation['operationId'],
                    ];
                }
            }
        }
    }
    return ['contract' => $contract, 'module_operations' => $moduleOperations];
}

/** @param list<string> $moduleKeys @param list<string> $retainedPaths @return array<string,mixed> */
function selectContractModules(array $contract, array $moduleKeys, array $retainedPaths = []): array
{
    foreach ($contract['paths'] as $path => $pathItem) {
        $required = $pathItem['x-peanut-required-modules'] ?? [];
        if (!is_array($required) || !array_is_list($required)) {
            throw new RuntimeException('OpenAPI required Modules must be a list: ' . $path);
        }
        foreach ($required as $moduleKey) {
            if (!is_string($moduleKey) || preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_.-]*$/D', $moduleKey) !== 1) {
                throw new RuntimeException('OpenAPI required Module key is invalid: ' . $path);
            }
        }
        if (array_diff($required, $moduleKeys) !== [] && !in_array(openApiPath((string) $path), $retainedPaths, true)) {
            unset($contract['paths'][$path]);
        }
    }
    return $contract;
}

/** @return list<string> */
function hostMetadataFiles(string $repositoryRoot): array
{
    return array_map(
        static fn(string $path): string => $repositoryRoot . '/' . $path,
        [
            'server/app/api/metadata/application/shared.php',
            'server/app/api/metadata/application/adminapi.php',
            'server/app/api/metadata/application/platform.php',
            'server/app/api/metadata/application/installation.php',
        ],
    );
}

/** @param array<string,mixed> $contract @return array<string,mixed> */
function mergeContractFragment(array $contract, string $fragmentPath, bool $allowOverrides): array
{
    if (!is_file($fragmentPath) || is_link($fragmentPath)) {
        throw new RuntimeException('OpenAPI fragment must be a regular non-symlink file: ' . $fragmentPath);
    }
    $fragment = require $fragmentPath;
    if (!is_array($fragment)) {
        throw new RuntimeException('OpenAPI fragment must return an array: ' . $fragmentPath);
    }
    $paths = isset($fragment['paths']) && is_array($fragment['paths'])
        ? $fragment['paths']
        : (isset($fragment['components']) || isset($fragment['overrides']) ? [] : $fragment);
    foreach ($paths as $path => $pathItem) {
        if (!is_string($path) || !is_array($pathItem)) {
            throw new RuntimeException('OpenAPI fragment path is invalid: ' . $fragmentPath);
        }
        foreach ($pathItem as $method => $operation) {
            $method = strtolower((string) $method);
            if (!is_array($operation) || isset($contract['paths'][$path][$method])) {
                throw new RuntimeException("Duplicate or invalid OpenAPI operation: {$method} {$path}");
            }
            $contract['paths'][$path][$method] = $operation;
        }
    }
    foreach (($fragment['overrides'] ?? []) as $path => $pathItem) {
        if (!$allowOverrides || !is_string($path) || !is_array($pathItem)) {
            throw new RuntimeException('OpenAPI fragment override is not allowed or invalid: ' . $fragmentPath);
        }
        foreach ($pathItem as $method => $operation) {
            $method = strtolower((string) $method);
            if (!is_array($operation) || !isset($contract['paths'][$path][$method])) {
                throw new RuntimeException("OpenAPI override has no existing operation: {$method} {$path}");
            }
            $contract['paths'][$path][$method] = $operation;
        }
    }
    foreach (['schemas', 'responses', 'parameters', 'requestBodies', 'securitySchemes'] as $componentType) {
        foreach (($fragment['components'][$componentType] ?? []) as $name => $component) {
            if (!is_string($name) || !is_array($component) || isset($contract['components'][$componentType][$name])) {
                throw new RuntimeException('OpenAPI component is invalid or duplicated: ' . $fragmentPath . '#' . $componentType . '/' . (string) $name);
            }
            $contract['components'][$componentType][$name] = $component;
        }
    }
    return $contract;
}

/** @param list<array<string,mixed>> $endpoints @return array<string,array<string,mixed>> */
function indexRoutes(array $endpoints): array
{
    $routes = [];
    foreach ($endpoints as $endpoint) {
        $key = routeKey((string) $endpoint['method'], (string) $endpoint['path']);
        if (isset($routes[$key])) {
            throw new RuntimeException('Duplicate runtime route: ' . $key);
        }
        $routes[$key] = $endpoint;
    }
    return $routes;
}

/** @param array<string,mixed> $contract @param array<string,array<string,mixed>> $routes @return array<string,mixed> */
function applyRouteMetadata(array $contract, array $routes): array
{
    $tagNames = [];
    foreach ($contract['tags'] ?? [] as $tag) {
        if (is_array($tag) && is_string($tag['name'] ?? null)) {
            $tagNames[$tag['name']] = true;
        }
    }
    foreach ($contract['paths'] as $path => &$pathItem) {
        foreach ($pathItem as $method => &$operation) {
            if (!is_array($operation) || !isset($operation['operationId'])) {
                continue;
            }
            $key = routeKey((string) $method, (string) $path);
            if (!isset($routes[$key])) {
                continue;
            }
            $route = $routes[$key];
            [$auth, $permissions] = accessMetadata($route, null);
            $operation['security'] ??= securityRequirements($auth);
            $operation['x-peanut-route'] = [
                'application' => $route['application'],
                'owner' => $route['owner'],
                'controller' => $route['controller'],
                'action' => $route['action'],
                'permission' => $route['permission'] ?? null,
                'source' => ['file' => $route['source'], 'line' => $route['line']],
            ];
            $operation['x-peanut-permissions'] = $permissions;
            foreach ($operation['tags'] ?? [] as $tagName) {
                if (is_string($tagName) && !isset($tagNames[$tagName])) {
                    $contract['tags'][] = ['name' => $tagName];
                    $tagNames[$tagName] = true;
                }
            }
        }
        unset($operation);
    }
    unset($pathItem);
    return $contract;
}

/** @param array<string,mixed> $contract @param array<string,array<string,mixed>> $routes */
function validateContract(array $contract, array $routes): void
{
    if (($contract['openapi'] ?? null) !== '3.0.3') {
        throw new RuntimeException('OpenAPI target must be exactly 3.0.3');
    }
    $operationIds = [];
    foreach (($contract['paths'] ?? []) as $path => $pathItem) {
        if (!is_array($pathItem)) {
            throw new RuntimeException('OpenAPI path item must be an object: ' . (string) $path);
        }
        foreach ($pathItem as $method => $operation) {
            if (!in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            $key = routeKey((string) $method, (string) $path);
            if (!isset($routes[$key])) {
                throw new RuntimeException('OpenAPI operation has no runtime route: ' . $key);
            }
            $operationId = $operation['operationId'] ?? null;
            if (!is_string($operationId)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $operationId) !== 1
                || isset($operationIds[$operationId])) {
                throw new RuntimeException('OpenAPI operationId is missing or duplicated: ' . $key);
            }
            $operationIds[$operationId] = true;
            if (!is_array($operation['responses'] ?? null) || $operation['responses'] === []) {
                throw new RuntimeException('OpenAPI responses are missing: ' . $key);
            }
        }
    }
    validateSchemas($contract['components']['schemas'] ?? []);
    validateReferences($contract, $contract);
}

/** @param mixed $schemas */
function validateSchemas(mixed $schemas): void
{
    if (!is_array($schemas) || $schemas === []) {
        throw new RuntimeException('OpenAPI components.schemas must not be empty');
    }
    foreach ($schemas as $name => $schema) {
        if (!is_array($schema) || $schema === []) {
            throw new RuntimeException('OpenAPI schema must not be empty: ' . (string) $name);
        }
        validateSchemaNode($schema, '#/components/schemas/' . (string) $name);
        validateSealedAllOf($schema, $schemas, '#/components/schemas/' . (string) $name);
    }
}

/** @param array<string|int,mixed> $node @param array<string,mixed> $schemas */
function validateSealedAllOf(array $node, array $schemas, string $pointer): void
{
    $branches = $node['allOf'] ?? null;
    if (is_array($branches)) {
        foreach ($branches as $index => $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $sealed = $branch;
            $ref = $branch['$ref'] ?? null;
            if (is_string($ref)
                && preg_match('~^#/components/schemas/([^/]+)$~D', $ref, $match) === 1
                && is_array($schemas[$match[1]] ?? null)
            ) {
                $sealed = $schemas[$match[1]];
            }
            if (($sealed['additionalProperties'] ?? null) !== false) {
                continue;
            }
            $allowed = array_keys(is_array($sealed['properties'] ?? null) ? $sealed['properties'] : []);
            foreach ($branches as $otherIndex => $other) {
                if ($otherIndex === $index || !is_array($other)) {
                    continue;
                }
                $otherProperties = array_keys(is_array($other['properties'] ?? null) ? $other['properties'] : []);
                if (array_diff($otherProperties, $allowed) !== []) {
                    throw new RuntimeException('OpenAPI allOf extends a sealed object: ' . $pointer);
                }
            }
        }
    }
    foreach ($node as $key => $value) {
        if (is_array($value) && $value !== []) {
            validateSealedAllOf($value, $schemas, $pointer . '/' . (string) $key);
        }
    }
}

/** @param array<string|int,mixed> $node */
function validateSchemaNode(array $node, string $pointer): void
{
    foreach ($node as $key => $value) {
        if (!is_array($value)) {
            continue;
        }
        $child = $pointer . '/' . (string) $key;
        if ($value === []) {
            throw new RuntimeException('OpenAPI contains an empty schema node: ' . $child);
        }
        validateSchemaNode($value, $child);
    }
}

/** @param mixed $node @param array<string,mixed> $root */
function validateReferences(mixed $node, array $root): void
{
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/')) {
            $cursor = $root;
            foreach (explode('/', substr($value, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    throw new RuntimeException('Unresolved OpenAPI reference: ' . $value);
                }
                $cursor = $cursor[$segment];
            }
        }
        validateReferences($value, $root);
    }
}

/** @return array<string,mixed> */
function buildCatalog(string $repositoryRoot, array $contract, array $inventory): array
{
    $documented = [];
    foreach ($contract['paths'] as $path => $pathItem) {
        foreach ($pathItem as $method => $operation) {
            if (is_array($operation) && isset($operation['operationId'])) {
                $documented[routeKey((string) $method, (string) $path)] = $operation;
            }
        }
    }

    $access = require $repositoryRoot . '/server/config/admin_api_access.php';
    $exceptions = [];
    foreach (['public', 'authenticated', 'platform_public', 'platform_authenticated'] as $class) {
        foreach ($access[$class] ?? [] as $route) {
            $parts = preg_split('/\s+/', trim((string) $route), 2);
            if (count($parts) === 2) {
                $exceptions[routeKey($parts[0], $parts[1])] = $class;
            }
        }
    }

    $endpoints = [];
    $owners = [];
    $documentationGaps = [];
    foreach ($inventory['endpoints'] as $endpoint) {
        $key = routeKey((string) $endpoint['method'], (string) $endpoint['path']);
        $operation = $documented[$key] ?? null;
        [$auth, $permissions] = accessMetadata($endpoint, $exceptions[$key] ?? null);
        $errors = inferredErrors($auth, $permissions);
        if (is_array($operation)) {
            foreach (array_keys($operation['responses'] ?? []) as $status) {
                if (ctype_digit((string) $status) && (int) $status >= 400) {
                    $errors[] = (int) $status;
                }
            }
        }
        $errors = array_values(array_unique($errors));
        sort($errors);
        $owner = (string) $endpoint['owner']['type'] . ':' . (string) $endpoint['owner']['key'];
        $owners[$owner] = ($owners[$owner] ?? 0) + 1;
        $documentation = $operation !== null
            ? [
                'status' => 'documented',
                'automatic_metadata' => ['route', 'owner', 'controller', 'middleware', 'auth', 'permissions', 'source'],
                'requires_owner_contract' => operationContractGaps($operation, $endpoint, $auth, $permissions),
            ]
            : [
                'status' => 'route_only',
                'automatic_metadata' => ['route', 'owner', 'controller', 'middleware', 'auth', 'permissions', 'source'],
                'requires_owner_contract' => ['operationId', 'parameters_or_request_body', 'response_schemas', 'business_errors'],
            ];
        if ($operation === null) {
            $moduleFragment = null;
            if (($endpoint['owner']['type'] ?? null) === 'module'
                && preg_match('#^(server/app/modules/[^/]+/[^/]+)/#', (string) $endpoint['source'], $moduleRoot) === 1) {
                $moduleFragment = $moduleRoot[1] . '/api/metadata/openapi.php';
            }
            $documentationGaps[] = [
                'method' => $endpoint['method'],
                'path' => openApiPath((string) $endpoint['path']),
                'owner' => $endpoint['owner'],
                'source' => ['file' => $endpoint['source'], 'line' => $endpoint['line']],
                'reason' => 'Runtime metadata is known; request, response and business-error contracts require the route owner.',
                'module_fragment' => $moduleFragment,
            ];
        }
        $endpoints[] = [
            'method' => $endpoint['method'],
            'path' => openApiPath((string) $endpoint['path']),
            'runtime_path' => $endpoint['path'],
            'application' => $endpoint['application'],
            'owner' => $endpoint['owner'],
            'controller' => $endpoint['controller'],
            'action' => $endpoint['action'],
            'auth' => $auth,
            'permissions' => $permissions,
            'errors' => $errors,
            'middleware' => $endpoint['middleware'],
            'source' => ['file' => $endpoint['source'], 'line' => $endpoint['line']],
            'documented' => $operation !== null,
            'openapi_operation_id' => $operation['operationId'] ?? null,
            'contract_quality' => $operation === null
                ? 'route_only'
                : ($documentation['requires_owner_contract'] === [] ? 'complete' : 'partial'),
            'sdk_consumers' => $operation === null ? [] : sdkConsumers($repositoryRoot, $endpoint),
            'documentation' => $documentation,
        ];
    }
    ksort($owners);

    $complete = count(array_filter(
        $endpoints,
        static fn(array $endpoint): bool => ($endpoint['contract_quality'] ?? null) === 'complete',
    ));
    $partial = count(array_filter(
        $endpoints,
        static fn(array $endpoint): bool => ($endpoint['contract_quality'] ?? null) === 'partial',
    ));

    return [
        'schema_version' => 1,
        'openapi_version' => $contract['openapi'],
        'inputs' => catalogInputs($repositoryRoot, $inventory),
        'generated_from' => [
            'routes' => 'server/route/registry_source.php::peanut_route_endpoint_inventory',
            'contracts' => [
                'server/app/api/metadata/contracts/openapi.json',
                'server/app/api/metadata/application/*.php',
                'server/app/modules/*/*/api/metadata/openapi.php',
            ],
        ],
        'summary' => [
            'route_count' => count($endpoints),
            'generated_api_operations' => count($documented),
            'route_only_operations' => count($documentationGaps),
            'complete_operations' => $complete,
            'partial_operations' => $partial,
            'runtime_routes' => count($endpoints),
            'documented_operations' => count($documented),
            'undocumented_routes' => count($documentationGaps),
            'automatic_route_metadata' => count($endpoints),
            'requires_owner_contract' => count($documentationGaps),
            'owners' => count($owners),
            'runtime_inventory' => $inventory['summary'],
        ],
        'owners' => $owners,
        'documentation_gaps' => $documentationGaps,
        'endpoints' => $endpoints,
    ];
}

/** @return list<string> */
function operationContractGaps(array $operation, array $endpoint, array $auth, array $permissions): array
{
    $gaps = [];
    $path = openApiPath((string) $endpoint['path']);
    preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);
    $declaredPathParameters = [];
    foreach ($operation['parameters'] ?? [] as $parameter) {
        if (is_array($parameter)
            && ($parameter['in'] ?? null) === 'path'
            && is_string($parameter['name'] ?? null)
            && ($parameter['required'] ?? false) === true
        ) {
            $declaredPathParameters[] = $parameter['name'];
        }
    }
    sort($declaredPathParameters, SORT_STRING);
    $expectedPathParameters = $matches[1] ?? [];
    sort($expectedPathParameters, SORT_STRING);
    if ($declaredPathParameters !== $expectedPathParameters) {
        $gaps[] = 'path_parameters';
    }

    if (!securityMatches($operation['security'] ?? null, securityRequirements($auth))) {
        $gaps[] = in_array($auth['mode'], ['public', 'public_tenant_module'], true)
            ? 'security_domain'
            : 'security';
    }

    $responses = $operation['responses'] ?? null;
    if (!is_array($responses) || $responses === []) {
        $gaps[] = 'response_schemas';
    } else {
        foreach ($responses as $status => $response) {
            if (!is_array($response)
                || (!responseHasSchema($response) && !responseMayOmitSchema((string) $status, $response))
            ) {
                $gaps[] = 'response_schema:' . (string) $status;
                continue;
            }
            if (genericResponseContract($response)) {
                $gaps[] = 'generic_response:' . (string) $status;
            }
        }
    }

    if (isset($operation['requestBody'])
        && (!is_array($operation['requestBody']) || !responseHasSchema($operation['requestBody']))
    ) {
        $gaps[] = 'request_schema';
    }
    foreach ($operation['x-peanut-errors'] ?? [] as $errorCode) {
        if (!is_string($errorCode) || preg_match('/^[A-Z][A-Z0-9_]{2,127}$/D', $errorCode) !== 1) {
            $gaps[] = 'business_errors';
            break;
        }
    }
    if ($permissions !== [] && !array_key_exists('403', $responses)) {
        $gaps[] = 'permission_error_response';
    }

    return array_values(array_unique($gaps));
}

/** @param array<string,mixed> $auth @return list<array<string,list<string>>> */
function securityRequirements(array $auth): array
{
    return match ($auth['mode']) {
        'public', 'public_tenant_module' => [],
        'tenant_host', 'tenant_selection' => [['tenantHost' => []]],
        'tenant_refresh' => [['tenantHost' => [], 'tenantRefreshCookie' => []]],
        'tenant_bearer' => [['tenantHost' => [], 'bearerAuth' => []]],
        'platform_host' => [['platformHost' => []]],
        'platform_refresh' => [['platformHost' => [], 'platformRefreshCookie' => []]],
        'installation_setup' => [['installationSetupToken' => []]],
        'collaboration_service_hmac' => [['CollaborationServiceHmac' => []]],
        default => [['bearerAuth' => []]],
    };
}

function securityMatches(mixed $actual, array $expected): bool
{
    if (!is_array($actual) || count($actual) !== count($expected)) {
        return false;
    }
    $normalize = static function (array $requirements): array {
        $normalized = [];
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                return [];
            }
            ksort($requirement, SORT_STRING);
            $normalized[] = $requirement;
        }
        usort($normalized, static fn(array $left, array $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR),
            json_encode($right, JSON_THROW_ON_ERROR),
        ));
        return $normalized;
    };
    return $normalize($actual) === $normalize($expected);
}

function responseHasSchema(array $response): bool
{
    if (is_string($response['$ref'] ?? null)) {
        return true;
    }
    foreach (($response['content'] ?? []) as $media) {
        if (is_array($media) && is_array($media['schema'] ?? null) && $media['schema'] !== []) {
            return true;
        }
    }
    return false;
}

function responseMayOmitSchema(string $status, array $response): bool
{
    if (in_array($status, ['204', '304'], true)) {
        return !isset($response['content']);
    }
    if (!in_array($status, ['301', '302', '303', '307', '308'], true)) {
        return false;
    }
    $location = $response['headers']['Location'] ?? null;
    return !isset($response['content'])
        && is_array($location)
        && ($location['required'] ?? false) === true
        && is_array($location['schema'] ?? null)
        && ($location['schema']['type'] ?? null) === 'string';
}

function genericResponseContract(array $response): bool
{
    $refs = [];
    if (is_string($response['$ref'] ?? null)) {
        $refs[] = $response['$ref'];
    }
    foreach (($response['content'] ?? []) as $media) {
        if (is_array($media) && is_string($media['schema']['$ref'] ?? null)) {
            $refs[] = $media['schema']['$ref'];
        }
    }
    foreach ($refs as $ref) {
        if (in_array($ref, [
            '#/components/responses/ApiResponse',
            '#/components/schemas/ApiResponse',
            '#/components/schemas/JsonValue',
        ], true)) {
            return true;
        }
    }
    return false;
}

/** @return list<string> */
function sdkConsumers(string $repositoryRoot, array $endpoint): array
{
    $consumers = ['web/src/generated/openapi.d.ts'];
    if (($endpoint['owner']['type'] ?? null) !== 'module') {
        return $consumers;
    }
    $moduleKey = (string) ($endpoint['owner']['key'] ?? '');
    $moduleDirectory = str_replace('.', '-', $moduleKey);
    if (is_dir($repositoryRoot . '/web/src/modules/' . $moduleDirectory)) {
        $consumers[] = 'web/src/modules/' . $moduleDirectory . '/generated/openapi.ts';
    }
    return $consumers;
}

/** @return list<array{path:string,sha256:string}> */
function catalogInputs(string $repositoryRoot, array $inventory): array
{
    $paths = [
        'release-versions.json' => true,
        'plugins.lock' => true,
        'server/app/api/metadata/contracts/openapi.json' => true,
        'server/app/api/metadata/application/common.php' => true,
        'server/config/admin_api_access.php' => true,
        'server/route/registry_source.php' => true,
    ];
    foreach (hostMetadataFiles($repositoryRoot) as $absolutePath) {
        $paths[substr($absolutePath, strlen($repositoryRoot) + 1)] = true;
    }
    foreach ($inventory['endpoints'] as $endpoint) {
        $paths[(string) $endpoint['source']] = true;
    }
    foreach ($inventory['modules'] as $root) {
        $relativePath = $root . '/api/metadata/openapi.php';
        if (is_file($repositoryRoot . '/' . $relativePath)) {
            $paths[$relativePath] = true;
        }
    }
    ksort($paths, SORT_STRING);

    $inputs = [];
    foreach (array_keys($paths) as $relativePath) {
        if ($relativePath === ''
            || str_starts_with($relativePath, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $relativePath) === 1) {
            throw new RuntimeException('Catalog input must be repository-relative: ' . $relativePath);
        }
        $absolutePath = $repositoryRoot . '/' . $relativePath;
        if (!is_file($absolutePath) || is_link($absolutePath)) {
            throw new RuntimeException('Catalog input must be a regular non-symlink file: ' . $relativePath);
        }
        $sha256 = hash_file('sha256', $absolutePath);
        if ($sha256 === false) {
            throw new RuntimeException('Cannot hash catalog input: ' . $relativePath);
        }
        $inputs[] = ['path' => $relativePath, 'sha256' => $sha256];
    }
    return $inputs;
}

/** @return array{array<string,mixed>,list<array<string,string>>} */
function accessMetadata(array $endpoint, ?string $exception): array
{
    $classes = [];
    $permissions = [];
    foreach ($endpoint['middleware'] as $middleware) {
        $class = (string) $middleware['class'];
        $classes[$class] = $middleware['arguments'];
        if (str_ends_with($class, '\\PlatformPermissionMiddleware') && isset($middleware['arguments'][0])) {
            $permissions[] = ['kind' => 'platform_action', 'key' => (string) $middleware['arguments'][0]];
        }
        if (str_ends_with($class, '\\OfficialModuleMiddleware') && isset($middleware['arguments'][0])) {
            $permissions[] = ['kind' => 'module_capability', 'key' => (string) $middleware['arguments'][0]];
        }
        if (str_ends_with($class, '\\PublicTenantModuleMiddleware')) {
            if (isset($middleware['arguments'][0])) {
                $permissions[] = ['kind' => 'public_capability', 'key' => (string) $middleware['arguments'][0]];
            }
            if (isset($middleware['arguments'][1])) {
                $permissions[] = ['kind' => 'module_capability', 'key' => (string) $middleware['arguments'][1]];
            }
        }
    }

    $mode = match ($exception) {
        'public', 'platform_public' => 'public',
        'authenticated', 'platform_authenticated' => 'authenticated',
        default => 'public',
    };
    if (array_key_exists(\PeanutAdmin\Modules\Collaboration\Middleware\ServiceAuthenticationMiddleware::class, $classes)) {
        // This native service envelope rejects human bearer/cookie credentials.
        $mode = 'collaboration_service_hmac';
    } elseif (hasMiddlewareSuffix($classes, '\\PlatformPermissionMiddleware')) {
        $mode = 'platform_permission';
    } elseif (hasMiddlewareSuffix($classes, '\\PlatformLoginMiddleware')) {
        $mode = 'platform_session';
    } elseif (hasMiddlewareSuffix($classes, '\\AuthMiddleware')) {
        $mode = 'tenant_permission';
        $permissions[] = [
            'kind' => 'tenant_action',
            'key' => is_string($endpoint['permission'] ?? null) && $endpoint['permission'] !== ''
                ? $endpoint['permission']
                : preg_replace('#^/adminapi/#', '', (string) $endpoint['path']),
        ];
    } elseif (hasMiddlewareSuffix($classes, '\\LoginMiddleware')) {
        $mode = 'tenant_session';
    } elseif (hasMiddlewareSuffix($classes, '\\CheckTokenMiddleware')) {
        $mode = 'member_session';
    } elseif (hasMiddlewareSuffix($classes, '\\PublicTenantModuleMiddleware')) {
        $mode = 'public_tenant_module';
    }

    $controller = (string) ($endpoint['controller'] ?? '');
    $action = (string) ($endpoint['action'] ?? '');
    if (str_ends_with($controller, '\\PlatformSessionController')) {
        $mode = match ($action) {
            'login', 'logout' => 'platform_host',
            'refresh' => 'platform_refresh',
            default => $mode,
        };
    } elseif (str_ends_with($controller, '\\TenantOwnerInvitationPublicController')) {
        $mode = 'platform_host';
    } elseif (str_ends_with($controller, '\\TenantSessionController')) {
        $mode = match ($action) {
            'login' => 'tenant_host',
            'select' => 'tenant_selection',
            'switchChallenge', 'logout' => 'tenant_bearer',
            'refresh' => 'tenant_refresh',
            default => $mode,
        };
    } elseif (str_ends_with($controller, '\\LoginController') && $action === 'login') {
        $mode = 'tenant_host';
    } elseif (str_ends_with($controller, '\\InstallationController') && in_array($action, ['configure', 'execute'], true)) {
        $mode = 'installation_setup';
    }

    return [[
        'mode' => $mode,
        'exception_registration' => $exception,
    ], $permissions];
}

/** @param array<string,mixed> $classes */
function hasMiddlewareSuffix(array $classes, string $suffix): bool
{
    foreach (array_keys($classes) as $class) {
        if (str_ends_with($class, $suffix)) {
            return true;
        }
    }
    return false;
}

/** @param array<string,mixed> $auth @param list<array<string,string>> $permissions @return list<int> */
function inferredErrors(array $auth, array $permissions): array
{
    $errors = [];
    if (in_array($auth['mode'], [
        'authenticated', 'tenant_session', 'tenant_permission', 'member_session',
        'tenant_bearer', 'tenant_refresh', 'platform_session', 'platform_permission',
        'platform_refresh', 'installation_setup', 'collaboration_service_hmac',
    ], true)) {
        $errors[] = 401;
    }
    if ($permissions !== [] || in_array($auth['mode'], [
        'tenant_permission', 'platform_permission', 'public_tenant_module',
        'tenant_selection', 'platform_host', 'platform_refresh', 'installation_setup', 'collaboration_service_hmac',
    ], true)) {
        $errors[] = 403;
    }
    return $errors;
}

/** @param array<string,list<array{method:string,path:string,operationId:string}>> $declarations */
function writeModuleTypes(string $repositoryRoot, string $outputRoot, array $contract, array $routes, array $declarations): void
{
    $declaredOwners = [];
    foreach ($declarations as $moduleKey => $operations) {
        foreach ($operations as $operation) {
            $declaredOwners[routeKey($operation['method'], $operation['path'])] = $moduleKey;
        }
    }
    $modules = [];
    $expectedTargets = [];
    foreach ($contract['paths'] as $path => $pathItem) {
        foreach ($pathItem as $method => $operation) {
            if (!is_array($operation) || !isset($operation['operationId'])) {
                continue;
            }
            $key = routeKey((string) $method, (string) $path);
            // Module fragments own optional declarations even when their runtime
            // integration is absent. Host declarations retain native route ownership.
            $owner = $declaredOwners[$key]
                ?? (($routes[$key]['owner']['type'] ?? null) === 'module' ? $routes[$key]['owner']['key'] : null);
            if ($owner === null) {
                continue;
            }
            $modules[$owner][] = [
                'method' => strtolower((string) $method),
                'path' => openApiPath((string) $path),
                'operationId' => (string) $operation['operationId'],
            ];
        }
    }
    foreach ($modules as $moduleKey => $operations) {
        $moduleDirectory = str_replace('.', '-', $moduleKey);
        $sourceModuleDirectory = $repositoryRoot . '/web/src/modules/' . $moduleDirectory;
        if (!is_dir($sourceModuleDirectory)) {
            continue;
        }
        $target = generatedArtifactPath(
            $outputRoot,
            'web/src/modules/' . $moduleDirectory . '/generated/openapi.ts',
        );
        $expectedTargets[$target] = true;
        $lines = [
            "// Generated by scripts/generate-api-contracts.php. Do not edit.",
            "import createClient, { wrapAsPathBasedClient } from 'openapi-fetch';",
            "import type { Client, ClientOptions } from 'openapi-fetch';",
            "import type { paths } from '@/generated/openapi';",
            '',
            'export interface ModuleApiOperations {',
        ];
        foreach ($operations as $operation) {
            $lines[] = sprintf(
                "  %s: paths[%s][%s];",
                $operation['operationId'],
                var_export($operation['path'], true),
                var_export($operation['method'], true),
            );
        }
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export function createModuleApi(client: Client<paths>) {';
        $lines[] = '  const pathClient = wrapAsPathBasedClient(client);';
        $lines[] = '  return {';
        foreach ($operations as $operation) {
            $lines[] = sprintf(
                "    %s: pathClient[%s].%s,",
                $operation['operationId'],
                var_export($operation['path'], true),
                strtoupper($operation['method']),
            );
        }
        $lines[] = '  };';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export function createModuleClient(options?: ClientOptions) {';
        $lines[] = '  return createModuleApi(createClient<paths>(options));';
        $lines[] = '}';
        $lines[] = '';
        if (file_put_contents($target, implode("\n", $lines)) === false) {
            throw new RuntimeException('Cannot write generated file: ' . $target);
        }
    }

    foreach (glob($outputRoot . '/web/src/modules/*/generated/openapi.ts') ?: [] as $existing) {
        if (isset($expectedTargets[$existing])) {
            continue;
        }
        if (is_link($existing) || !is_file($existing)) {
            throw new RuntimeException('Retired generated module API artifact is unsafe to remove: ' . $existing);
        }
        if (!unlink($existing)) {
            throw new RuntimeException('Cannot remove retired generated module API artifact: ' . $existing);
        }
    }
}

function routeKey(string $method, string $path): string
{
    return strtoupper($method) . ' ' . openApiPath($path);
}

function openApiPath(string $path): string
{
    return preg_replace('/:([A-Za-z_][A-Za-z0-9_]*)/', '{$1}', $path) ?? $path;
}

function resolveIsolatedOutputRoot(string $requestedRoot, string $repositoryRoot): string
{
    if ($requestedRoot === '' || !str_starts_with($requestedRoot, '/')) {
        throw new RuntimeException('--output-root must be an absolute path');
    }
    $requestedRoot = rtrim($requestedRoot, DIRECTORY_SEPARATOR);
    if ($requestedRoot === '' || is_link($requestedRoot) || !is_dir($requestedRoot)) {
        throw new RuntimeException('--output-root must be an existing regular directory, not a symlink');
    }
    $resolvedOutputRoot = realpath($requestedRoot);
    $resolvedRepositoryRoot = realpath($repositoryRoot);
    if ($resolvedOutputRoot === false || $resolvedRepositoryRoot === false) {
        throw new RuntimeException('--output-root or repository root cannot be resolved');
    }
    if (pathsOverlap($resolvedOutputRoot, $resolvedRepositoryRoot)) {
        throw new RuntimeException('--output-root must be separate from the source repository');
    }
    $entries = scandir($resolvedOutputRoot);
    if ($entries === false || array_values(array_diff($entries, ['.', '..'])) !== []) {
        throw new RuntimeException('--output-root must be an empty, exclusive directory');
    }
    return $resolvedOutputRoot;
}

function pathsOverlap(string $left, string $right): bool
{
    return $left === $right
        || str_starts_with($left, rtrim($right, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        || str_starts_with($right, rtrim($left, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

function generatedArtifactPath(string $outputRoot, string $relativePath): string
{
    if ($relativePath === ''
        || str_starts_with($relativePath, '/')
        || preg_match('#(^|/)(?:\.|\.\.)(?:/|$)#D', $relativePath) === 1) {
        throw new RuntimeException('Generated artifact path must remain relative to its output root: ' . $relativePath);
    }
    $resolvedRoot = realpath($outputRoot);
    if ($resolvedRoot === false || !is_dir($resolvedRoot) || is_link($outputRoot)) {
        throw new RuntimeException('Generated artifact output root is unavailable or a symlink: ' . $outputRoot);
    }

    $segments = explode('/', $relativePath);
    $filename = array_pop($segments);
    $directory = $resolvedRoot;
    foreach ($segments as $segment) {
        if ($segment === '') {
            throw new RuntimeException('Generated artifact path contains an empty segment: ' . $relativePath);
        }
        $directory .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($directory)) {
            throw new RuntimeException('Generated artifact path refuses symlink directory: ' . $directory);
        }
        if (file_exists($directory)) {
            if (!is_dir($directory)) {
                throw new RuntimeException('Generated artifact parent is not a directory: ' . $directory);
            }
        } elseif (!mkdir($directory, 0775) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create generated directory: ' . $directory);
        }
    }

    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    if (is_link($target)) {
        throw new RuntimeException('Generated artifact path refuses symlink target: ' . $target);
    }
    if (file_exists($target) && !is_file($target)) {
        throw new RuntimeException('Generated artifact target is not a regular file: ' . $target);
    }
    return $target;
}

/** @param array<string,mixed> $value */
function writeJson(string $path, array $value): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create generated directory: ' . $directory);
    }
    $json = json_encode(
        preserveJsonObjects($value),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Cannot write generated file: ' . $path);
    }
}

/**
 * PHP associative decoding cannot distinguish `{}` from `[]`. OpenAPI security
 * requirement values are deliberately empty objects, so restore that shape.
 */
function preserveJsonObjects(mixed $value, ?string $key = null): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if ($value === [] && $key === 'bearerAuth') {
        return (object) [];
    }
    $normalized = [];
    foreach ($value as $childKey => $childValue) {
        $normalized[$childKey] = preserveJsonObjects($childValue, (string) $childKey);
    }
    return $normalized;
}
