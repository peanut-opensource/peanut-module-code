<?php

declare(strict_types=1);

$id = ['type' => 'integer','minimum' => 1];
$seq = ['type' => 'integer','minimum' => 0];
$digest = ['type' => 'string','pattern' => '^[0-9a-f]{64}$'];
$key = ['type' => 'string','pattern' => '^[A-Za-z0-9_.:-]{1,128}$'];
$text = ['type' => 'string'];
$bool = ['type' => 'boolean'];
$protocol = ['type' => 'integer','enum' => [2]];
$holder = ['type' => 'string','pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'];
$ref = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
$object = static fn(array $properties, ?array $required = null): array => ['type' => 'object','additionalProperties' => false,
    'properties' => $properties,'required' => $required ?? array_keys($properties)];
$envelope = static fn(array $data): array => $object(['code' => ['type' => 'integer','enum' => [20000]],'msg' => $text,'data' => $data]);
$mode = ['type' => 'string','enum' => ['read','write']];
$owner = $object(['module_key' => $text,'object_type' => $text,'object_id' => $text,'slot' => $text]);
$projector = ['type' => 'string','pattern' => '^[A-Za-z0-9_.:/-]{1,64}$'];
$binary = ['type' => 'string','maxLength' => 5592408,'description' => 'Canonical base64, at most 4 MiB decoded. PHP verifies bytes/hash; native Yjs owns merging and coverage.'];
$room = ['room_key' => $digest,'lease_epoch' => $id];
$binding = $object(['instance_id' => $key,'edition' => ['type' => 'string','enum' => ['engine','standalone','multi-tenant']],
    'tenant_id' => $id,'document_id' => $id,'owner' => $owner]);
$connection = $object(['protocol' => $protocol,'holder_key' => $holder,'connection_key' => $digest] + $room + ['mode' => $mode,'member_id' => $id,
    'nickname' => ['type' => 'string','minLength' => 1,'maxLength' => 160],'document_id' => $id,'binding' => $binding,
    'authorization_revision' => $digest,'lease_expires_at' => $id]);
$receipt = $object(['document_id' => $id,'store_id' => $key,'lease_epoch' => $id,'state_seq' => $seq,
    'binary_sha256' => $digest,'projection_sha256' => $digest,'persisted_at' => $text,'target_progress' => $seq]);
$baseline = ['document_id' => $id,'lease_epoch' => $id,'state_seq' => $seq,'state_sha256' => $digest,
    'binary_base64' => $binary + ['nullable' => true],'initialization_required' => $bool,
    'structured_content' => $ref('DocumentStructuredContent'),'store_id' => $key + ['nullable' => true]];
$load = $object($baseline + ['binding' => $binding,'holder_key' => $holder,'schema_version' => $text]);
$participant = $object(['connection_key' => $digest,'authorization_revision' => $digest,'authorized_at_ms' => $id]);
$store = $object(['protocol' => $protocol] + $binding['properties'] + $room +
    ['service_instance_id' => $key,'base_state_seq' => $seq,'store_id' => $key,'target_progress' => $seq,
    'baseline_sha256' => $digest,'binary_sha256' => $digest,'projector_version' => $projector,
    'projection_sha256' => $digest,'reference_digest' => $digest,
    'participants' => ['type' => 'array','maxItems' => 1000,'items' => $participant],
    'binary_base64' => $binary,'structured_content' => $ref('DocumentStructuredContent')]);
$fileKeys = ['type' => 'array','maxItems' => 1000,'uniqueItems' => true,'items' => ['type' => 'string','pattern' => '^file_[0-9a-f]{32}$']];
$frozen = ['protocol' => $protocol,'connection_key' => $digest,'request_id' => $key,
    'target_binary_sha256' => $digest,'target_projection_sha256' => $digest,'binary_base64' => $binary,
    'structured_content' => $ref('DocumentStructuredContent'),'projector_version' => $projector,'reference_digest' => $digest,'target_progress' => $seq];
$targetReply = ['document_id' => $id,'request_id' => $key,'target_binary_sha256' => $digest,'target_projection_sha256' => $digest,'target_progress' => $seq];
$coverage = $object(['algorithm' => ['type' => 'string','enum' => ['yjs.snapshotContainsUpdate/13.6.32']],
    'covers_target' => ['type' => 'boolean','enum' => [true]],'committed_snapshot_base64' => $binary,'target_snapshot_base64' => $binary]);
$target = $object($frozen + ['store_id' => $key,'state_seq' => $seq,'committed_binary_sha256' => $digest,'coverage' => $coverage]);
$operation = static function (string $name, array $input, string $response, bool $internal = true) use ($ref): array {
    $out = ['tags' => ['Collaboration'],'operationId' => $name,'requestBody' => ['required' => true,
        'content' => ['application/json' => ['schema' => $input]]],'responses' => ['200' => [
        'description' => 'Native protocol 2 result. A merged broadcast is not a committed database receipt.',
        'content' => ['application/json' => ['schema' => $ref($response)]]]]];
    foreach ([401,403,404,409,422,429,500,503] as $status) {
        $out['responses'][(string) $status] = ['$ref' => '#/components/responses/ErrorResponse'];
    }
    if ($internal) {
        $out['security'] = [['CollaborationServiceHmac' => []]];
        $out['description'] = 'Exact-body transport envelope v2 HMAC/nonce/holder and TLS; business payload protocol 2. Human identity is separately revalidated where required.';
        $out['parameters'] = [];
        foreach (['X-Collaboration-Protocol','X-Collaboration-Service-Instance','X-Collaboration-Key-Id','X-Collaboration-Holder-Key','X-Collaboration-Timestamp','X-Collaboration-Nonce'] as $header) {
            $out['parameters'][] = ['name' => $header,'in' => 'header','required' => true,'schema' => ['type' => 'string']];
        }
    }
    return $out;
};
return ['paths' => [
    '/adminapi/peanut.collaboration.transport' => ['post' => $operation('resolveCollaborationTransport', $object(['document_id' => $id,'mode' => $mode]), 'CollaborationTransportResponse', false)],
    '/adminapi/internal/collaboration/v1/authorize' => ['post' => $operation('authorizeCollaborationConnection', $object(['token' => $text,'document_name' => $text]), 'CollaborationConnectionResponse')],
    '/adminapi/internal/collaboration/v1/check' => ['post' => $operation('checkCollaborationConnection', $object(['connection_key' => $digest,'mode' => $mode]), 'CollaborationConnectionResponse')],
    '/adminapi/internal/collaboration/v1/load' => ['post' => $operation('loadCollaborationDocument', $object($room + ['connection_key' => $digest]), 'CollaborationLoadResponse')],
    '/adminapi/internal/collaboration/v1/store' => ['post' => $operation('storeCollaborationCapture', $store, 'CollaborationStoreResponse')],
    '/adminapi/internal/collaboration/v1/receipt' => ['post' => $operation('queryCollaborationReceipt', $object($room + ['store_id' => $key,'capture_sha256' => $digest]), 'CollaborationReceiptResponse')],
    '/adminapi/internal/collaboration/v1/references' => ['post' => $operation('reserveCollaborationReferences', $object($room + ['connection_key' => $digest,'file_keys' => $fileKeys,'request_id' => $key]), 'CollaborationReferenceResponse')],
    '/adminapi/internal/collaboration/v1/target/prepare' => ['post' => $operation('prepareCollaborationTarget', $object($frozen), 'CollaborationTargetPreparationResponse')],
    '/adminapi/internal/collaboration/v1/target' => ['post' => $operation('confirmCollaborationTarget', $target, 'CollaborationTargetResponse')],
    '/adminapi/internal/collaboration/v1/recovery' => ['post' => $operation('recoverCollaborationRoom', $object(['protocol' => $protocol,'request_id' => $key] + $room), 'CollaborationRecoveryResponse')],
    '/adminapi/internal/collaboration/v1/revoke' => ['post' => $operation('revokeCollaborationConnection', $object(['connection_key' => $digest]), 'CollaborationRevokeResponse')],
], 'components' => ['securitySchemes' => ['CollaborationServiceHmac' => ['type' => 'apiKey','in' => 'header','name' => 'X-Collaboration-Signature',
    'description' => 'Exact transport envelope v2 HMAC binds the real process holder. Service custody is not a human capability.']], 'schemas' => [
    'CollaborationTransportResponse' => $envelope($object(['document_name' => $text,'document_id' => $id,'mode' => $mode,'protocol' => $protocol,
        'wss_url' => ['type' => 'string','format' => 'uri','pattern' => '^wss?://[^/?#@]+:[0-9]+/collaboration$']])),
    'CollaborationConnectionResponse' => $envelope($connection),'CollaborationLoadResponse' => $envelope($load),'CollaborationStoreResponse' => $envelope($receipt),
    'CollaborationReceiptResponse' => $envelope(['oneOf' => [$object(['found' => ['type' => 'boolean','enum' => [false]]]),
        $object(['found' => ['type' => 'boolean','enum' => [true]],'receipt' => $receipt])]]),
    'CollaborationReferenceResponse' => $envelope($object(['document_id' => $id] + $room + ['file_keys' => $fileKeys,'authorization_revision' => $digest,'authorized_at_ms' => $id])),
    'CollaborationTargetPreparationResponse' => $envelope($object($targetReply)),
    'CollaborationTargetResponse' => $envelope($object($targetReply + ['store_id' => $key,'state_seq' => $seq,'committed_binary_sha256' => $digest])),
    'CollaborationRecoveryResponse' => $envelope($object(['protocol' => $protocol,'holder_key' => $holder,'request_id' => $key] + $room + ['binding' => $binding,
        'baseline' => $object($baseline + ['status' => ['type' => 'string','enum' => ['active','archived']]])])),
    'CollaborationRevokeResponse' => $envelope($object(['revoked' => ['type' => 'boolean','enum' => [true]]])),
]]];
