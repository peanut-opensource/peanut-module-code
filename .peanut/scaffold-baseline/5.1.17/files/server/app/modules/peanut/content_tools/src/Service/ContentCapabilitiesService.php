<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ContentTools\Service;

use PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext;
use PeanutAdmin\Modules\ContentTools\Contract\SurfaceAdapterRegistration;

final readonly class ContentCapabilitiesService
{
    public function __construct(private ContentToolRegistry $registry) {}

    /**
     * Explicit application IDs are authoritative; absent IDs require a unique compatible registration.
     * Selection never comes from tenant-provided executable paths or registration order.
     * Calling code must re-authorize access/save; this result is only a capability snapshot.
     * @return array{contentKind:string,verifiedMimeType:?string,viewerAdapterId:?string,editorAdapterId:?string,canView:bool,canEdit:bool,canDownloadOriginal:bool,state:string,unavailableReason:?string,capabilityRevision:string}
     */
    public function resolve(AuthorizedContentContext $context, string $intent, ?string $viewerId, ?string $editorId): array
    {
        if (!in_array($intent, ['view', 'edit'], true)) {
            throw new \InvalidArgumentException('CONTENT_INTENT_INVALID');
        }
        $viewer = $this->registry->selected($viewerId, $context, 'view');
        $editor = $this->registry->selected($editorId, $context, 'edit');
        $viewReason = $this->unavailable($viewer, $context);
        $editReason = $this->unavailable($editor, $context);
        $canView = $context->canView && $viewer !== null && $viewReason === null;
        $canEdit = $context->canEdit && $editor !== null && $editReason === null;
        $permitted = $intent === 'view' ? $context->canView : $context->canEdit;
        $selected = $intent === 'view' ? $viewer : $editor;
        $reason = $intent === 'view' ? $viewReason : $editReason;
        $state = !$permitted ? 'permission_denied'
            : ($selected === null ? ($intent === 'view' ? 'preview_unavailable' : 'edit_unsupported')
                : ($reason !== null ? 'service_unavailable'
                    : ($intent === 'view' && !$canEdit ? 'read_only' : 'available')));
        $result = [
            'contentKind' => $context->contentKind,
            'verifiedMimeType' => $context->verifiedMimeType,
            'viewerAdapterId' => $viewer?->id,
            'editorAdapterId' => $editor?->id,
            'canView' => $canView,
            'canEdit' => $canEdit,
            'canDownloadOriginal' => $context->canDownloadOriginal,
            'state' => $state,
            'unavailableReason' => !$permitted ? 'OBJECT_PERMISSION_DENIED' : ($reason ?? ($selected === null ? 'CONTENT_PROVIDER_UNAVAILABLE' : null)),
        ];
        $result['capabilityRevision'] = hash('sha256', json_encode([
            $result, $context->authorizationRevision, $context->environment,
            $viewer?->adapterVersion, $editor?->adapterVersion,
        ], JSON_THROW_ON_ERROR));
        return $result;
    }

    private function unavailable(?SurfaceAdapterRegistration $adapter, AuthorizedContentContext $context): ?string
    {
        if ($adapter === null) {
            return null;
        }
        if (!in_array($adapter->providerModuleKey, $context->enabledProviderModules, true)) {
            return 'CONTENT_PROVIDER_DISABLED';
        }
        if (!in_array($context->environment, $adapter->environments, true)) {
            return 'CONTENT_ENVIRONMENT_UNSUPPORTED';
        }
        if (array_diff($adapter->serviceDependencies, $context->healthyServices) !== []) {
            return 'CONTENT_SERVICE_UNAVAILABLE';
        }
        return $adapter->unavailableReason;
    }
}
