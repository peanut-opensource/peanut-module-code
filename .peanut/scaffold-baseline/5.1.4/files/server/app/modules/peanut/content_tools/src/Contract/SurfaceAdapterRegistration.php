<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ContentTools\Contract;

/** Trusted provider code registers capabilities; registrations never grant object access. */
final readonly class SurfaceAdapterRegistration
{
    /** @param list<string> $contentKinds @param list<string> $mimeTypes @param list<string> $intents @param list<string> $environments @param list<string> $serviceDependencies */
    public function __construct(
        public string $id,
        public string $providerModuleKey,
        public string $adapterVersion,
        public array $contentKinds,
        public array $mimeTypes,
        public array $intents,
        public array $environments = ['browser'],
        public array $serviceDependencies = [],
        public string $protocolVersion = 'content-surface/1',
        public ?string $unavailableReason = null,
        public bool $supportsCollaboration = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9.-]+$/D', $id) !== 1
            || preg_match('/^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*$/D', $providerModuleKey) !== 1
            || preg_match('/^\d+\.\d+\.\d+$/D', $adapterVersion) !== 1
            || $protocolVersion !== 'content-surface/1') {
            throw new \InvalidArgumentException('CONTENT_ADAPTER_INVALID');
        }
        foreach ([$contentKinds, $mimeTypes, $intents, $environments, $serviceDependencies] as $values) {
            if (!array_is_list($values) || count($values) !== count(array_unique($values))) {
                throw new \InvalidArgumentException('CONTENT_ADAPTER_INVALID');
            }
            foreach ($values as $value) {
                if (!is_string($value) || $value === '') {
                    throw new \InvalidArgumentException('CONTENT_ADAPTER_INVALID');
                }
            }
        }
        if ($contentKinds === [] || $intents === [] || $environments === []
            || array_diff($contentKinds, ['rich_text', 'file_resource']) !== []
            || array_diff($intents, ['view', 'edit']) !== []) {
            throw new \InvalidArgumentException('CONTENT_ADAPTER_INVALID');
        }
        foreach ($mimeTypes as $mime) {
            if (preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~D', $mime) !== 1) {
                throw new \InvalidArgumentException('CONTENT_ADAPTER_INVALID');
            }
        }
        if (in_array('file_resource', $contentKinds, true) && $mimeTypes === []) {
            throw new \InvalidArgumentException('CONTENT_ADAPTER_INVALID');
        }
    }
}
