<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ContentTools\Contract;

/**
 * Created by the owning Document/File service AFTER current object authorization.
 * This value is not an authorization mechanism and must never be hydrated from HTTP input.
 * MIME is the File service's verified byte type, not a client filename/type hint.
 */
final readonly class AuthorizedContentContext
{
    /** @param list<string> $enabledProviderModules @param list<string> $healthyServices */
    public function __construct(
        public string $contentKind,
        public ?string $verifiedMimeType,
        public bool $canView,
        public bool $canEdit,
        public bool $canDownloadOriginal,
        public string $authorizationRevision,
        public string $environment,
        public array $enabledProviderModules,
        public array $healthyServices = [],
    ) {
        if (!in_array($contentKind, ['rich_text', 'file_resource'], true)
            || $authorizationRevision === '' || $environment === ''
            || ($contentKind === 'file_resource' && ($verifiedMimeType === null
                || preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~D', $verifiedMimeType) !== 1))) {
            throw new \InvalidArgumentException('CONTENT_CONTEXT_INVALID');
        }
        foreach ([$enabledProviderModules, $healthyServices] as $identities) {
            if (!array_is_list($identities)) {
                throw new \InvalidArgumentException('CONTENT_CONTEXT_INVALID');
            }
            foreach ($identities as $identity) {
                if (!is_string($identity) || $identity === '') {
                    throw new \InvalidArgumentException('CONTENT_CONTEXT_INVALID');
                }
            }
        }
    }
}
