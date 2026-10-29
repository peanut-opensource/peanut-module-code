<?php

declare(strict_types=1);

namespace app\platform\services\provider;

use app\platform\contract\provider\ProviderQualificationContributor;
use app\common\model\provider\ProviderQualificationEvidence;
use app\platform\value\provider\ProviderQualificationSubject;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Status\OpsStatusService;

/** Secret-free, read-only Platform projection. Contributors never run probes here. */
final class PlatformProviderQualificationService
{
    /** @var list<ProviderQualificationContributor> */
    private array $contributors;
    private \Closure $clock;

    /**
     * @param list<ProviderQualificationContributor> $contributors
     * @param callable():DateTimeImmutable|null $clock
     */
    public function __construct(
        private readonly PlatformPermissionChecker $permissions,
        array $contributors,
        private readonly string $scopeDigestKey,
        ?callable $clock = null,
    ) {
        if (strlen($scopeDigestKey) < 32) {
            throw new \InvalidArgumentException('PROVIDER_QUALIFICATION_SCOPE_KEY_INVALID');
        }
        foreach ($contributors as $contributor) {
            if (!$contributor instanceof ProviderQualificationContributor) {
                throw new \InvalidArgumentException('PROVIDER_QUALIFICATION_CONTRIBUTOR_INVALID');
            }
        }
        $this->contributors = array_values($contributors);
        $this->clock = $clock === null
            ? static fn(): DateTimeImmutable => new DateTimeImmutable('now')
            : \Closure::fromCallable($clock);
    }

    /** @return array{schema_version:int,generated_at:string,providers:list<array<string,mixed>>,page:int,page_size:int,total:int} */
    public function snapshot(PlatformContext $context, int $page = 1, int $pageSize = 20): array
    {
        if (!$this->permissions->allows($context, OpsStatusService::READ_PERMISSION)) {
            throw OpsConsoleException::denied();
        }
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw OpsConsoleException::invalid();
        }
        $subjects = [];
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->subjects() as $subject) {
                if (isset($subjects[$subject->internalKey()])) {
                    throw new \RuntimeException('PROVIDER_QUALIFICATION_SUBJECT_DUPLICATE');
                }
                $subjects[$subject->internalKey()] = $subject;
            }
        }
        $subjects = array_values($subjects);
        $rows = $this->evidenceFor($subjects);
        $now = ($this->clock)();
        $providers = array_map(
            fn(ProviderQualificationSubject $subject): array => $this->project($subject, $rows, $now),
            $subjects,
        );
        usort($providers, static fn(array $left, array $right): int => [
            $left['category'], $left['provider_key'], $left['scope']['key'],
        ] <=> [
            $right['category'], $right['provider_key'], $right['scope']['key'],
        ]);
        return [
            'schema_version' => 1,
            'generated_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'providers' => array_slice($providers, ($page - 1) * $pageSize, $pageSize),
            'page' => $page, 'page_size' => $pageSize, 'total' => count($providers),
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function project(ProviderQualificationSubject $subject, array $rows, DateTimeImmutable $now): array
    {
        $matching = array_values(array_filter(
            $rows,
            static fn(array $row): bool =>
            (string) ($row['provider_key'] ?? '') === $subject->providerKey
            && (string) ($row['scope_type'] ?? '') === $subject->scopeType
            && (($row['tenant_id'] ?? null) === null ? null : (int) $row['tenant_id']) === $subject->tenantId
            && (string) ($row['scope_reference'] ?? '') === $subject->scopeReference,
        ));
        usort(
            $matching,
            static fn(array $left, array $right): int =>
            strcmp((string) ($right['observed_at'] ?? ''), (string) ($left['observed_at'] ?? '')),
        );
        $valid = array_values(array_filter($matching, static function (array $row) use ($subject, $now): bool {
            $expiresAt = new DateTimeImmutable((string) $row['expires_at']);
            return hash_equals($subject->configDigest, (string) ($row['config_digest'] ?? ''))
                && $expiresAt > $now;
        }));
        $latestByType = [];
        foreach ($valid as $row) {
            $type = (string) $row['evidence_type'];
            $latestByType[$type] ??= $row;
        }
        $passed = static fn(string $type): bool => isset($latestByType[$type])
            && (string) $latestByType[$type]['outcome'] === 'passed';
        $connected = $subject->implemented && $passed('connectivity');
        $callbackVerified = $subject->implemented && $passed('callback');
        $production = $subject->implemented && $passed('production');
        $qualified = $subject->implemented && $subject->configured && $connected && $production
            && (!$subject->callbackRequired || $callbackVerified);
        $recentFailure = null;
        foreach ($matching as $row) {
            $observed = new DateTimeImmutable((string) $row['observed_at']);
            if ((string) ($row['outcome'] ?? '') === 'failed'
                && hash_equals($subject->configDigest, (string) ($row['config_digest'] ?? ''))
                && $observed >= $now->modify('-30 days')
            ) {
                $recentFailure = [
                    'code' => (string) $row['status_code'],
                    'observed_at' => $this->iso((string) $row['observed_at']),
                ];
                break;
            }
        }
        $hasStale = $matching !== [] && count($valid) < count($matching);
        $statusCode = match (true) {
            !$subject->implemented => 'NOT_IMPLEMENTED',
            !$subject->configured => 'CONFIGURATION_REQUIRED',
            $recentFailure !== null => 'PROVIDER_RECENT_FAILURE',
            $hasStale && $valid === [] => 'PROVIDER_EVIDENCE_STALE',
            !$connected => 'CONNECTIVITY_EVIDENCE_REQUIRED',
            $subject->callbackRequired && !$callbackVerified => 'CALLBACK_EVIDENCE_REQUIRED',
            !$production => 'PRODUCTION_QUALIFICATION_REQUIRED',
            default => 'PROVIDER_PRODUCTION_QUALIFIED',
        };
        $validObserved = array_column($valid, 'observed_at');
        $validExpires = array_column($valid, 'expires_at');
        $scopeKey = 'scope_' . hash_hmac('sha256', $subject->internalKey(), $this->scopeDigestKey);
        $evidenceKeys = array_map(static fn(array $row): string => (string) $row['evidence_key'], $matching);
        sort($evidenceKeys, SORT_STRING);
        return [
            'provider_key' => $subject->providerKey,
            'category' => $subject->category,
            'scope' => ['type' => $subject->scopeType, 'key' => $scopeKey],
            'configured' => $subject->configured,
            'connected' => $connected,
            'callback_verified' => $callbackVerified,
            'credential_rotated_at' => $subject->credentialRotatedAt,
            'observed_at' => $validObserved === [] ? null : $this->iso(max($validObserved)),
            'expires_at' => $validExpires === [] ? null : $this->iso(min($validExpires)),
            'qualified' => $qualified,
            'status_code' => $statusCode,
            'recent_failure' => $recentFailure,
            'evidence_digest' => hash('sha256', implode("\0", $evidenceKeys)),
        ];
    }

    private function iso(string $value): string
    {
        return (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /** @param list<ProviderQualificationSubject> $subjects @return list<array<string,mixed>> */
    private function evidenceFor(array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }
        $providerKeys = array_values(array_unique(array_map(
            static fn(ProviderQualificationSubject $subject): string => $subject->providerKey,
            $subjects,
        )));

        return ProviderQualificationEvidence::whereIn('provider_key', $providerKeys)
            ->field('evidence_key,provider_key,scope_type,tenant_id,scope_reference,evidence_type,outcome,config_digest,status_code,observed_at,expires_at')
            ->order('observed_at', 'desc')->order('id', 'desc')->select()->toArray();
    }
}
