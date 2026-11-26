<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformAccessAdminService;
use PeanutAdmin\Modules\Identity\Platform\Authorization\PlatformRoleDirectGrantStore;
use think\facade\Db;

/**
 * Source-only HTTP application contract, not a route or independent grant
 * writer. The future Code controller injects the current native
 * PlatformRoleNodeSource::authoritativeCandidate resolver; neither client
 * metadata nor a second host-owned authorization source is trusted.
 */
final readonly class PlatformRoleNodeWriteContract
{
    public function __construct(
        private PlatformAuthorizationEvaluator $authorization,
        private PlatformAccessAdminService $writer,
        private PlatformRoleDirectGrantStore $direct,
    ) {}

    /**
     * Compute the revision and exact node/API + instance directory precondition.
     * This is a read-only contract. No HTTP route is enabled by this method.
     *
     * @param array<string,mixed> $payload
     * @param callable(array<string,mixed>):array<string,mixed> $trustedSource
     * @return array{role_id:string,expected_revision:string,node_keys:list<string>,expected_catalog_sha256:string,source_mode:string}
     */
    public function prepare(PlatformContext $actor, array $payload, callable $trustedSource): array
    {
        $this->assertPermissions($actor, false);
        $this->direct->assertReady();
        $this->fields($payload, ['role_id', 'node_keys']);
        $roleId = $this->positive($payload['role_id']);
        $keys = $this->nodeKeys($payload['node_keys']);

        return Db::transaction(function () use ($roleId, $keys, $trustedSource): array {
            $row = Db::name('platform_role')->where('id', $roleId)->field('revision,status')->find();
            if ($row === null || $row['status'] !== 'active') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_INACTIVE', 'A current active Platform role is required.');
            }
            $revision = (int) $row['revision'];
            $request = $this->candidate($roleId, $revision, $keys);
            $resolved = $trustedSource($request);
            $this->direct->selectedNodes($resolved, $roleId, $revision, $keys);
            return [
                'role_id' => (string) $roleId,
                'expected_revision' => (string) $revision,
                'node_keys' => $keys,
                'expected_catalog_sha256' => $this->direct->catalogDigest($resolved['nodes']),
                'source_mode' => $this->direct->source(),
            ];
        });
    }

    /**
     * Validate the future HTTP request without authorizing or publishing a
     * route. The native writer repeats revision, catalog, actor and lock checks
     * inside its own transaction and emits the native audited response.
     *
     * @param array<string,mixed> $payload
     * @param callable(array<string,mixed>):array<string,mixed> $trustedSource
     * @return array<string,mixed>
     */
    public function save(PlatformContext $actor, array $payload, callable $trustedSource): array
    {
        $this->assertPermissions($actor, true);
        $this->direct->assertReady();
        $this->fields($payload, [
            'role_id', 'node_keys', 'expected_revision', 'expected_catalog_sha256', 'change_reason',
        ]);
        $roleId = $this->positive($payload['role_id']);
        $revision = $this->positive($payload['expected_revision']);
        $nodeKeys = $this->nodeKeys($payload['node_keys']);
        $digest = $payload['expected_catalog_sha256'];
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_SOURCE_INVALID', 'Expected source digest must be a SHA-256 hex string.');
        }
        $reason = $payload['change_reason'];
        if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 500) {
            throw AdminAccessException::invalid('CHANGE_REASON_INVALID', 'A bounded change reason is required.');
        }
        return $this->writer->replaceRoleNodes(
            $actor, $roleId, $nodeKeys, $revision, $digest, trim($reason), $trustedSource,
        );
    }

    private function assertPermissions(PlatformContext $actor, bool $write): void
    {
        foreach (['platform.role.read', 'platform.permission.api.read', 'platform.operator.read'] as $permission) {
            $this->authorization->assertAllowed($actor, $permission);
        }
        if ($write) {
            $this->authorization->assertAllowed($actor, 'platform.role.permission.assign');
        }
    }

    /** @param array<string,mixed> $payload
     * @param list<string> $fields
     */
    private function fields(array $payload, array $fields): void
    {
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($fields, SORT_STRING);
        if ($actual !== $fields) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_REQUEST_INVALID', 'Role request fields do not match the native contract.');
        }
    }

    private function positive(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) ||
            preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1 ||
            filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_REQUEST_INVALID', 'Role identity and revision must be positive integers.');
        }
        return (int) $value;
    }

    /** @return list<string> */
    private function nodeKeys(mixed $keys): array
    {
        if (!is_array($keys) || !array_is_list($keys) || count($keys) > 200) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_NODES_INVALID', 'Role selection must be a bounded ordered list.');
        }
        $unique = [];
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match('/^platform\.[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)*$/D', $key) !== 1 ||
                isset($unique[$key])) {
                throw AdminAccessException::invalid('PLATFORM_ROLE_NODES_INVALID', 'Role selection contains a duplicate or invalid node.');
            }
            $unique[$key] = true;
        }
        return $keys;
    }

    /** @param list<string> $nodeKeys
     * @return array<string,mixed>
     */
    private function candidate(int $roleId, int $revision, array $nodeKeys): array
    {
        return ['role_ids' => [$roleId], 'role_assignments' => [[
            'role_id' => $roleId, 'expected_revision' => $revision, 'node_keys' => $nodeKeys,
        ]]];
    }
}
