<?php

declare(strict_types=1);

use PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext;
use PeanutAdmin\Modules\ContentTools\Contract\SurfaceAdapterRegistration;
use PeanutAdmin\Modules\ContentTools\Service\ContentToolRegistry;
use PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService;

$root = dirname(__DIR__, 5);
require $root . '/server/vendor/autoload.php';
$assertions = 0;
function contentExpect(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function contentReject(callable $operation, string $code): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | LogicException $exception) {
        contentExpect($exception->getMessage() === $code, 'wrong rejection: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('expected rejection ' . $code);
}
function contentContext(string $mime = 'image/png', bool $view = true, bool $edit = true, bool $download = false, array $providers = ['peanut.content-tools'], array $services = [], string $revision = 'auth-1'): AuthorizedContentContext
{
    return new AuthorizedContentContext('file_resource', $mime, $view, $edit, $download, $revision, 'browser', $providers, $services);
}

$registry = ContentToolRegistry::withBuiltins();
$service = new ContentCapabilitiesService($registry);
foreach (['image/png' => 'image', 'application/pdf' => 'pdf', 'audio/mpeg' => 'audio', 'video/mp4' => 'video'] as $mime => $kind) {
    $result = $service->resolve(contentContext($mime), 'view', 'builtin.' . $kind, null);
    contentExpect($result['canView'] && !$result['canEdit'] && $result['state'] === 'read_only', 'builtin must be a real view-only capability');
    contentExpect(!$result['canDownloadOriginal'], 'view must not imply download');
}
$denied = $service->resolve(contentContext(view: false, download: true), 'view', 'builtin.image', null);
contentExpect($denied['state'] === 'permission_denied' && !$denied['canView'] && $denied['canDownloadOriginal'], 'independent permissions');
$unsupported = $service->resolve(contentContext(), 'edit', 'builtin.image', 'builtin.image');
contentExpect($unsupported['state'] === 'edit_unsupported' && !$unsupported['canEdit'], 'builtin image is not an editor');
foreach (['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream', 'image/svg+xml'] as $mime) {
    $result = $service->resolve(contentContext($mime), 'view', 'builtin.image', null);
    contentExpect($result['state'] === 'preview_unavailable' && !$result['canView'], 'no MIME spoofing/Office/SVG fallback');
}
$disabled = $service->resolve(contentContext(providers: []), 'view', 'builtin.image', null);
contentExpect($disabled['state'] === 'service_unavailable' && $disabled['unavailableReason'] === 'CONTENT_PROVIDER_DISABLED', 'module disabled');
$registry->register(new SurfaceAdapterRegistration('office.reviewed', 'example.office', '1.0.0', ['file_resource'], ['application/pdf'], ['view', 'edit'], serviceDependencies: ['office-service']));
$office = $service->resolve(contentContext('application/pdf', providers: ['example.office']), 'edit', 'office.reviewed', 'office.reviewed');
contentExpect($office['state'] === 'service_unavailable' && !$office['canEdit'], 'missing service is fail-closed');
$healthy = $service->resolve(contentContext('application/pdf', providers: ['example.office'], services: ['office-service']), 'edit', 'office.reviewed', 'office.reviewed');
contentExpect($healthy['canEdit'] && $healthy['state'] === 'available', 'explicit healthy provider');
$other = $service->resolve(contentContext('application/pdf', providers: ['example.office'], services: ['office-service'], revision: 'auth-2'), 'edit', 'office.reviewed', 'office.reviewed');
contentExpect($other['capabilityRevision'] !== $healthy['capabilityRevision'], 'authorization revision binds capabilities');
$registry->register(new SurfaceAdapterRegistration('other.pdf', 'example.office', '1.0.0', ['file_resource'], ['application/pdf'], ['view']));
contentExpect($service->resolve(contentContext('application/pdf'), 'view', null, null)['state'] === 'preview_unavailable', 'never choose by registration order');
contentReject(fn() => $registry->register($registry->registrations()[0]), 'CONTENT_ADAPTER_DUPLICATE');
contentReject(fn() => new SurfaceAdapterRegistration('bad.type', 'official.content-tools', '1.0.0', ['file_resource'], ['image/*'], ['view']), 'CONTENT_ADAPTER_INVALID');
contentReject(fn() => new SurfaceAdapterRegistration('bad.protocol', 'official.content-tools', '1.0.0', ['rich_text'], [], ['view'], protocolVersion: 'other/1'), 'CONTENT_ADAPTER_INVALID');
contentReject(fn() => contentContext('IMAGE/PNG'), 'CONTENT_CONTEXT_INVALID');
contentReject(fn() => $service->resolve(contentContext(), 'download', null, null), 'CONTENT_INTENT_INVALID');
$rich = new AuthorizedContentContext('rich_text', null, true, true, false, 'auth-rich', 'browser', ['official.rich-text']);
contentExpect($service->resolve($rich, 'edit', null, null)['state'] === 'edit_unsupported', 'missing rich-text provider is honest');

echo 'CONTENT-CAPABILITIES-001 passed; assertions=' . $assertions . PHP_EOL;
