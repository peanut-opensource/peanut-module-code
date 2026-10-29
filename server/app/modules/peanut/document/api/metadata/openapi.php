<?php

declare(strict_types=1);

$id = ['type' => 'integer','minimum' => 1];
$seq = ['type' => 'integer','minimum' => 0];
$text = ['type' => 'string'];
$key = ['type' => 'string','pattern' => '^file_[0-9a-f]{32}$'];
$digest = ['type' => 'string','pattern' => '^[0-9a-f]{64}$'];
$request = ['type' => 'string','pattern' => '^[A-Za-z0-9:_-]{1,128}$'];
$ref = static fn(string $name) => ['$ref' => '#/components/schemas/' . $name];
$object = static fn(array $properties, array $required) => ['type' => 'object','additionalProperties' => false,'properties' => $properties,'required' => $required];
$envelope = static fn(array $data) => $object(['code' => ['type' => 'integer','enum' => [20000]],'msg' => ['type' => 'string'],'data' => $data], ['code','msg','data']);
$structured = $object(['schemaVersion' => ['type' => 'string','enum' => ['peanut.richtext/1']],'editorModel' => ['type' => 'string','enum' => ['tiptap-prosemirror']],
    'content' => ['type' => 'object','additionalProperties' => true],'annotations' => ['type' => 'array','maxItems' => 0]], ['schemaVersion','editorModel','content','annotations']);
$metadata = $object(['document_id' => $id,'content_kind' => ['type' => 'string','enum' => ['rich_text','file_resource']],'schema_version' => $text,
    'status' => ['type' => 'string','enum' => ['active','archiving','archived','blocked_recovery']],'state_seq' => $seq,'auth_revision' => $id,
    'created' => ['type' => 'boolean']], ['document_id','content_kind','schema_version','status','state_seq','auth_revision']);
$reference = $object(['file_key' => $key,'variant_key' => ['type' => 'string','nullable' => true]], ['file_key','variant_key']);
$detail = $metadata;
$detail['properties'] += ['tenant_id' => $id,'owner' => $object(['module_key' => $text,'object_type' => $text,'object_id' => $text,'slot' => $text], ['module_key','object_type','object_id','slot']),
    'authorization_revision' => $digest,'structured_content' => ['allOf' => [$ref('DocumentStructuredContent')],'nullable' => true],
    'file_key' => ['type' => 'string','nullable' => true],'file_sha256' => ['type' => 'string','nullable' => true],'verified_mime_type' => ['type' => 'string','nullable' => true],
    'digest' => $digest,'persisted_at' => $text,'revision_id' => $id,'revision_no' => $id,'source_state_seq' => $seq,'confirmed' => ['type' => 'boolean','enum' => [true]],
    'references' => ['type' => 'array','items' => $reference],'file_keys' => ['type' => 'array','items' => $key]];
$version = $object(['document_id' => $id,'revision_id' => $id,'revision_no' => $id,'source_state_seq' => $seq,'digest' => $digest], ['document_id','revision_id','revision_no','source_state_seq','digest']);
$versionSummary = $object(['revision_id' => $id,'revision_no' => $id,'created_at' => $text,'source_state_seq' => $seq,
    'content_kind' => ['type' => 'string','enum' => ['rich_text','file_resource']]], ['revision_id','revision_no','created_at','source_state_seq','content_kind']);
$versionPage = $object(['document_id' => $id,'owner' => $detail['properties']['owner'],
    'items' => ['type' => 'array','maxItems' => 100,'items' => $versionSummary],
    'next_before_revision_no' => $id + ['nullable' => true]], ['document_id','owner','items','next_before_revision_no']);
$businessFields = ['operation_id' => $request,'phase' => ['type' => 'string','enum' => ['completed']],
    'lease_epoch' => $id,'binary_sha256' => $digest,'store_id' => $request + ['nullable' => true],'target_progress' => $seq];
$metadata['properties'] += $businessFields;
$version['properties'] += $metadata['properties'];
$businessOperation = $object(
    ['document_id' => $id,'operation_id' => $request,'action' => ['type' => 'string','enum' => ['version.create','version.restore','archive','restore']],
        'input_sha256' => $digest,'phase' => ['type' => 'string','enum' => ['prepared','attention','completed']],
        'target_progress' => $seq + ['nullable' => true],'store_id' => $request + ['nullable' => true],
        'state_seq' => $seq + ['nullable' => true],'binary_sha256' => $digest + ['nullable' => true],
        'released' => ['type' => 'boolean'],'result' => ['type' => 'object','additionalProperties' => false,'nullable' => true,
            'properties' => $version['properties'],'required' => ['document_id','state_seq','auth_revision','status','operation_id','phase','lease_epoch','binary_sha256','store_id','target_progress']]],
    ['document_id','operation_id','action','input_sha256','phase','target_progress','store_id','state_seq','binary_sha256','released','result'],
);
$businessResult = $businessOperation['properties']['result'];
unset($businessResult['nullable']);
$save = $object(['document_id' => $id,'state_seq' => $seq,'persisted_at' => $text,'digest' => $digest,'file_key' => $key], ['document_id','state_seq','digest']);
$capability = $object(['contentKind' => ['type' => 'string','enum' => ['rich_text','file_resource']],'verifiedMimeType' => ['type' => 'string','nullable' => true],
    'viewerAdapterId' => ['type' => 'string','nullable' => true],'editorAdapterId' => ['type' => 'string','nullable' => true],'canView' => ['type' => 'boolean'],
    'canEdit' => ['type' => 'boolean'],'canDownloadOriginal' => ['type' => 'boolean'],'state' => ['type' => 'string','enum' => ['permission_denied','preview_unavailable','edit_unsupported','service_unavailable','read_only','available']],
    'unavailableReason' => ['type' => 'string','nullable' => true],'capabilityRevision' => $digest], ['contentKind','verifiedMimeType','viewerAdapterId','editorAdapterId','canView','canEdit','canDownloadOriginal','state','unavailableReason','capabilityRevision']);
$capabilities = $object(['document_id' => $id,'can_view' => ['type' => 'boolean'],'can_edit' => ['type' => 'boolean'],'can_download_original' => ['type' => 'boolean'],
    'collaboration_enabled' => ['type' => 'boolean'],'capabilities' => $capability], ['document_id','can_view','can_edit','can_download_original','collaboration_enabled','capabilities']);
$operation = static function (string $name, string $response, ?array $input = null, array $parameters = []) use ($ref): array {
    $operation = ['tags' => ['Document'],'operationId' => $name,'parameters' => $parameters,'responses' => [
        '200' => ['description' => 'Current owner-authorized document result. Confirmed versions are immutable and committed, not business approval.',
            'content' => ['application/json' => ['schema' => $ref($response)]]]]];
    foreach ([401,403,404,409,422] as $status) {
        $operation['responses'][(string) $status] = ['$ref' => '#/components/responses/ErrorResponse'];
    }
    if ($input !== null) {
        $operation['requestBody'] = ['required' => true,'content' => ['application/json' => ['schema' => $input]]];
    }
    return $operation;
};
$parameters = [['name' => 'document_id','in' => 'query','required' => true,'schema' => $id],['name' => 'revision_id','in' => 'query','schema' => $id]];
$base = ['document_id' => $id,'expected_state_seq' => $seq,'request_id' => $request];
return ['paths' => [
    '/adminapi/peanut.document.target' => ['get' => $operation(
        'getDocumentTarget',
        'DocumentTargetResponse',
        null,
        [['name' => 'document_id','in' => 'query','required' => true,'schema' => $id],['name' => 'request_id','in' => 'query','required' => true,'schema' => $request]],
    )],
    '/adminapi/peanut.document.target.cancel' => ['post' => $operation(
        'cancelDocumentTarget',
        'DocumentTargetCancelResponse',
        $object(['document_id' => $id,'request_id' => $request], ['document_id','request_id']),
    )],
    '/adminapi/peanut.document.operation' => ['get' => $operation(
        'queryDocumentBusinessOperation',
        'DocumentBusinessOperationResponse',
        null,
        [['name' => 'document_id','in' => 'query','required' => true,'schema' => $id],['name' => 'operation_id','in' => 'query','required' => true,'schema' => $request]],
    )],
    '/adminapi/peanut.document.detail' => ['get' => $operation('documentDetail', 'DocumentDetailResponse', null, $parameters)],
    '/adminapi/peanut.document.versions' => ['get' => $operation(
        'documentVersions',
        'DocumentVersionsResponse',
        null,
        [['name' => 'document_id','in' => 'query','required' => true,'schema' => $id],
            ['name' => 'limit','in' => 'query','schema' => $id + ['maximum' => 100,'default' => 20]],
            ['name' => 'before_revision_no','in' => 'query','schema' => $id]],
    )],
    '/adminapi/peanut.document.capabilities' => ['get' => $operation('documentCapabilities', 'DocumentCapabilitiesResponse', null, [...$parameters,['name' => 'intent','in' => 'query','required' => true,'schema' => ['type' => 'string','enum' => ['view','edit']]]])],
    '/adminapi/peanut.document.solo.save' => ['post' => $operation('saveSoloDocument', 'DocumentSaveResponse', $object($base + ['structured_content' => $ref('DocumentStructuredContent')], ['document_id','expected_state_seq','request_id','structured_content']))],
    '/adminapi/peanut.document.resource.bind' => ['post' => $operation('bindDocumentResource', 'DocumentSaveResponse', $object($base + ['file_key' => $key], ['document_id','expected_state_seq','request_id','file_key']))],
    '/adminapi/peanut.document.version.create' => ['post' => $operation('createDocumentVersion', 'DocumentVersionResponse', $object(['document_id' => $id,'expected_state_seq' => $seq,'reason' => ['type' => 'string','minLength' => 1,'maxLength' => 255],'idempotency_key' => $request], ['document_id','expected_state_seq','reason','idempotency_key']))],
    '/adminapi/peanut.document.version.restore' => ['post' => $operation('restoreDocumentVersion', 'DocumentVersionResponse', $object($base + ['revision_id' => $id], ['document_id','expected_state_seq','request_id','revision_id']))],
    '/adminapi/peanut.document.archive' => ['post' => $operation('archiveDocument', 'DocumentMetadataResponse', $object(['document_id' => $id,'expected_auth_revision' => $id,'request_id' => $request], ['document_id','expected_auth_revision','request_id']))],
    '/adminapi/peanut.document.restore' => ['post' => $operation('restoreDocument', 'DocumentMetadataResponse', $object(['document_id' => $id,'expected_auth_revision' => $id,'request_id' => $request], ['document_id','expected_auth_revision','request_id']))],
], 'components' => ['schemas' => [
    'DocumentStructuredContent' => $structured,'DocumentMetadataResponse' => $envelope($metadata),'DocumentDetailResponse' => $envelope($detail),
    'DocumentVersionResponse' => $envelope($version),'DocumentSaveResponse' => $envelope($save),'DocumentCapabilitiesResponse' => $envelope($capabilities),
    'DocumentVersionsResponse' => $envelope($versionPage),
    'DocumentBusinessOperationResponse' => $envelope($businessOperation),
    'DocumentTargetResponse' => $envelope($object(
        ['document_id' => $id,'request_id' => $request,
            'phase' => ['type' => 'string','enum' => ['not_prepared','prepared','confirmed','consumed','canceled']],
            'target_binary_sha256' => $digest + ['nullable' => true],'target_projection_sha256' => $digest + ['nullable' => true],'target_progress' => $seq + ['nullable' => true],
            'store_id' => $request + ['nullable' => true],'state_seq' => $seq + ['nullable' => true],
            'committed_binary_sha256' => $digest + ['nullable' => true],'revision_id' => $id + ['nullable' => true]],
        ['document_id','request_id','phase','target_binary_sha256','target_projection_sha256','target_progress','store_id','state_seq','committed_binary_sha256','revision_id'],
    )),
    'DocumentTargetCancelResponse' => $envelope($object(['document_id' => $id,'request_id' => $request,
        'phase' => ['type' => 'string','enum' => ['canceled']]], ['document_id','request_id','phase'])),
    'DocumentBusinessResult' => $businessResult,
    'DocumentContentCapabilities' => $capability,
]]];
