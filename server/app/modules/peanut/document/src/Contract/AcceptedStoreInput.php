<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;

/** Exact capture data, never a service/human authority proof. */
final readonly class AcceptedStoreInput
{
    public RichTextProjection $projection;
    public string $binary;
    public DocumentOwnerReference $owner;

    public function __construct(public array $tuple)
    {
        $expected = ['protocol','instance_id','edition','tenant_id','document_id','owner','room_key',
            'service_instance_id','lease_epoch','base_state_seq','store_id','target_progress',
            'baseline_sha256','binary_sha256','projector_version','projection_sha256',
            'reference_digest','participants','binary_base64','structured_content'];
        if (count($tuple) !== count($expected) || array_diff(array_keys($tuple), $expected) !== []) {
            throw new \InvalidArgumentException('COLLABORATION_STORE_FIELDS_INVALID');
        }
        foreach (['tenant_id','document_id','lease_epoch'] as $field) {
            if (!is_int($tuple[$field]) || $tuple[$field] < 1) {
                throw new \InvalidArgumentException('COLLABORATION_STORE_ID_INVALID');
            }
        }
        foreach (['base_state_seq','target_progress'] as $field) {
            if (!is_int($tuple[$field]) || $tuple[$field] < 0) {
                throw new \InvalidArgumentException('COLLABORATION_STORE_SEQUENCE_INVALID');
            }
        }
        foreach (['baseline_sha256','binary_sha256','projection_sha256','reference_digest','room_key'] as $field) {
            if (!is_string($tuple[$field]) || preg_match('/^[0-9a-f]{64}$/D', $tuple[$field]) !== 1) {
                throw new \InvalidArgumentException('COLLABORATION_STORE_DIGEST_INVALID');
            }
        }
        foreach (['instance_id','service_instance_id','store_id'] as $field) {
            if (!is_string($tuple[$field]) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $tuple[$field]) !== 1) {
                throw new \InvalidArgumentException('COLLABORATION_STORE_KEY_INVALID');
            }
        }
        if (!is_string($tuple['projector_version']) || preg_match('/^[A-Za-z0-9_.:\/-]{1,64}$/D', $tuple['projector_version']) !== 1) {
            throw new \InvalidArgumentException('COLLABORATION_PROJECTOR_VERSION_INVALID');
        }
        if ($tuple['protocol'] !== 2 || !in_array($tuple['edition'], ['engine','standalone','multi-tenant'], true)
            || !is_array($tuple['owner']) || count($tuple['owner']) !== 4
            || !is_array($tuple['structured_content']) || !is_array($tuple['participants'])
            || !array_is_list($tuple['participants']) || count($tuple['participants']) > 1000
            || !is_string($tuple['binary_base64']) || strlen($tuple['binary_base64']) > 5592408) {
            throw new \InvalidArgumentException('COLLABORATION_STORE_CONTENT_INVALID');
        }
        foreach ($tuple['participants'] as $participant) {
            if (!is_array($participant) || count($participant) !== 3
                || array_diff(array_keys($participant), ['connection_key','authorization_revision','authorized_at_ms']) !== []
                || !is_string($participant['connection_key'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $participant['connection_key']) !== 1
                || !is_string($participant['authorization_revision'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $participant['authorization_revision']) !== 1
                || !is_int($participant['authorized_at_ms'] ?? null) || $participant['authorized_at_ms'] < 1) {
                throw new \InvalidArgumentException('COLLABORATION_PARTICIPANT_INVALID');
            }
        }
        if (array_diff(array_keys($tuple['owner']), ['module_key','object_type','object_id','slot']) !== []) {
            throw new \InvalidArgumentException('COLLABORATION_STORE_OWNER_INVALID');
        }
        foreach ($tuple['owner'] as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('COLLABORATION_STORE_OWNER_INVALID');
            }
        }
        $this->owner = new DocumentOwnerReference(
            $tuple['owner']['module_key'],
            $tuple['owner']['object_type'],
            $tuple['owner']['object_id'],
            $tuple['owner']['slot'],
        );
        $binary = base64_decode($tuple['binary_base64'], true);
        if (!is_string($binary) || $binary === '' || strlen($binary) > 4194304
            || base64_encode($binary) !== $tuple['binary_base64'] || !hash_equals(hash('sha256', $binary), $tuple['binary_sha256'])) {
            throw new \InvalidArgumentException('COLLABORATION_STORE_BINARY_INVALID');
        }
        $this->binary = $binary;
        $this->projection = RichTextProjection::fromStructured($tuple['structured_content']);
        if (!hash_equals(hash('sha256', $this->projection->json), $tuple['projection_sha256'])
            || !hash_equals(self::referencesDigest($this->projection->fileReferences), $tuple['reference_digest'])) {
            throw new \InvalidArgumentException('COLLABORATION_STORE_PROJECTION_INVALID');
        }
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($this->tuple), JSON_THROW_ON_ERROR));
    }

    public static function referencesDigest(array $references): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($references), JSON_THROW_ON_ERROR));
    }
}
