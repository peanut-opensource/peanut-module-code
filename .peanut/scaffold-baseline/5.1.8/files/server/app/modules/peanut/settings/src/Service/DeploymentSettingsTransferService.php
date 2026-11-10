<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Service;

use PeanutAdmin\Modules\Settings\Application\SettingAdminService;
use PeanutAdmin\Modules\Settings\Application\SettingException;
use PeanutAdmin\Modules\Settings\Contract\DeploymentSettingsTransfer;
use PeanutAdmin\Modules\Settings\Contract\DeploymentSettingsTransferException;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinition;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Model\DeploymentSettingValue;
use PeanutAdmin\Modules\Settings\Model\SettingDefinitionRecord;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Context\PlatformContext;

final readonly class DeploymentSettingsTransferService implements DeploymentSettingsTransfer
{
    public function __construct(private SettingDefinitionRegistry $definitions, private SettingAdminService $settings) {}

    public function snapshot(PlatformContext $context): array
    {
        $this->assertContext($context);
        $items = [];
        foreach ($this->definitions->all() as $definition) {
            if ($definition->allows('deployment')) {
                $items[] = $this->state($definition);
            }
        }
        return $items;
    }

    public function current(PlatformContext $context, string $key): array
    {
        $this->assertContext($context);
        return $this->state($this->definition($key));
    }

    public function apply(PlatformContext $context, string $key, mixed $value, bool $unset, ?int $revision): void
    {
        $this->assertContext($context);
        $definition = $this->definition($key);
        $current = $this->state($definition);
        if ($unset || $value === null) {
            if (!$current['exists'] || !is_int($revision)) {
                return;
            }
            try {
                $this->settings->unsetDeployment($definition, $context->operatorId, $this->now(), self::etag($revision));
            } catch (SettingException $exception) {
                throw self::transferException($exception);
            }
            return;
        }
        try {
            $definition->assertValue($value);
            $this->settings->replaceDeployment(
                $definition,
                $value,
                $context->operatorId,
                $this->now(),
                null,
                $current['exists'] && is_int($revision) ? self::etag($revision) : null,
                $current['exists'] ? null : '*',
            );
        } catch (SettingException $exception) {
            throw self::transferException($exception);
        }
    }

    /** @return array{key:string,exists:bool,secret:bool,configured:bool,value:mixed,revision:?int} */
    private function state(SettingDefinition $definition): array
    {
        $record = SettingDefinitionRecord::where('module_key', $definition->moduleKey)
            ->where('setting_key', $definition->key)->where('status', 'active')->find();
        if (!$record instanceof SettingDefinitionRecord
            || !hash_equals((string) $record->getAttr('definition_digest'), $definition->digest)) {
            throw new \RuntimeException('TRANSFER_CORE_SETTING_NOT_FOUND');
        }
        $row = DeploymentSettingValue::where('definition_id', (int) $record->getAttr('id'))->find()?->getData();
        $exists = is_array($row);
        $configured = $exists && ($row['value_state'] ?? null) === 'set';
        if ($exists && !in_array($row['value_state'] ?? null, ['set', 'unset'], true)) {
            throw new \RuntimeException('TRANSFER_CORE_SETTING_INVALID');
        }
        return [
            'key' => $definition->qualifiedKey(),
            'exists' => $exists,
            'secret' => $definition->secret,
            'configured' => $configured,
            'value' => !$definition->secret && $configured ? $this->decode($row['value_json'] ?? null) : null,
            'revision' => $exists ? (int) ($row['revision'] ?? 0) : null,
        ];
    }

    private function definition(string $key): SettingDefinition
    {
        $parts = explode(':', $key, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('TRANSFER_CORE_SETTING_INVALID');
        }
        $definition = $this->definitions->require($parts[0], $parts[1]);
        if (!$definition->allows('deployment')) {
            throw new \RuntimeException('TRANSFER_CORE_SETTING_SCOPE_INVALID');
        }
        return $definition;
    }

    private function assertContext(PlatformContext $context): void
    {
        if ($context->accountId < 1 || $context->operatorId < 1 || $context->sessionKey === ''
            || $context->clientKey === '' || $context->requestId === '') {
            throw new \RuntimeException('TRANSFER_DEPLOYMENT_CONTEXT_INVALID');
        }
    }

    private function decode(mixed $value): mixed
    {
        try {
            return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('TRANSFER_CORE_SETTING_INVALID');
        }
    }

    private function now(): DateTimeImmutable
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $microseconds = (int) $now->format('u');
        return $microseconds % 1000 === 0 ? $now : $now->modify('-' . ($microseconds % 1000) . ' microseconds');
    }

    private static function etag(int $revision): string
    {
        return '"rev-' . $revision . '"';
    }

    private static function transferException(SettingException $exception): DeploymentSettingsTransferException
    {
        return new DeploymentSettingsTransferException(
            $exception->errorCode === 'SETTING_SECRET_UNAVAILABLE'
                ? 'TRANSFER_SECRET_PROTECTOR_UNAVAILABLE'
                : $exception->errorCode,
            $exception,
        );
    }
}
