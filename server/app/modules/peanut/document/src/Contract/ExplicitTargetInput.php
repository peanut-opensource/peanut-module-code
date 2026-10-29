<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;

/** Signed service attestation of the click-time snapshot; not a human capability or a draft write. */
final readonly class ExplicitTargetInput
{
    public string $binary;
    public RichTextProjection $projection;

    public function __construct(public array $tuple)
    {
        $keys = ['protocol','connection_key','request_id','store_id','state_seq','committed_binary_sha256',
            'target_binary_sha256','target_projection_sha256','binary_base64','structured_content',
            'projector_version','reference_digest','target_progress','coverage'];
        if (count($tuple) !== count($keys) || array_diff(array_keys($tuple), $keys) !== [] || ($tuple['protocol'] ?? null) !== 2) {
            throw new \InvalidArgumentException('DOCUMENT_TARGET_FIELDS_INVALID');
        }
        foreach (['state_seq','target_progress'] as $key) {
            if (!is_int($tuple[$key]) || $tuple[$key] < 0) {
                throw new \InvalidArgumentException('DOCUMENT_TARGET_PROGRESS_INVALID');
            }
        }
        foreach (['connection_key','committed_binary_sha256','target_binary_sha256','target_projection_sha256','reference_digest'] as $key) {
            if (!is_string($tuple[$key]) || preg_match('/^[0-9a-f]{64}$/D', $tuple[$key]) !== 1) {
                throw new \InvalidArgumentException('DOCUMENT_TARGET_DIGEST_INVALID');
            }
        }
        foreach (['request_id','store_id'] as $key) {
            if (!is_string($tuple[$key]) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $tuple[$key]) !== 1) {
                throw new \InvalidArgumentException('DOCUMENT_TARGET_ID_INVALID');
            }
        }
        if (!is_string($tuple['projector_version']) || preg_match('/^[A-Za-z0-9_.:\/-]{1,64}$/D', $tuple['projector_version']) !== 1
            || !is_array($tuple['structured_content'])) {
            throw new \InvalidArgumentException('DOCUMENT_TARGET_CONTENT_INVALID');
        }
        $this->binary = self::binary($tuple['binary_base64']);
        $this->projection = RichTextProjection::fromStructured($tuple['structured_content']);
        if (!hash_equals(hash('sha256', $this->binary), $tuple['target_binary_sha256'])
            || !hash_equals(hash('sha256', $this->projection->json), $tuple['target_projection_sha256'])
            || !hash_equals(AcceptedStoreInput::referencesDigest($this->projection->fileReferences), $tuple['reference_digest'])) {
            throw new \InvalidArgumentException('DOCUMENT_TARGET_CONTENT_MISMATCH');
        }
        $coverage = $tuple['coverage'];
        if (!is_array($coverage) || count($coverage) !== 4
            || array_diff(array_keys($coverage), ['algorithm','covers_target','committed_snapshot_base64','target_snapshot_base64']) !== []
            || $coverage['algorithm'] !== 'yjs.snapshotContainsUpdate/13.6.32' || $coverage['covers_target'] !== true) {
            throw new \InvalidArgumentException('DOCUMENT_TARGET_COVERAGE_INVALID');
        }
        self::binary($coverage['committed_snapshot_base64']);
        self::binary($coverage['target_snapshot_base64']);
    }

    private static function binary(mixed $encoded): string
    {
        $binary = is_string($encoded) && strlen($encoded) <= 5592408 ? base64_decode($encoded, true) : false;
        if (!is_string($binary) || $binary === '' || strlen($binary) > 4194304 || base64_encode($binary) !== $encoded) {
            throw new \InvalidArgumentException('DOCUMENT_TARGET_BINARY_INVALID');
        }
        return $binary;
    }

    public function hash(): string
    {
        $business = $this->tuple;
        unset($business['connection_key']);
        return hash('sha256', json_encode(RichTextProjection::canonical($business), JSON_THROW_ON_ERROR));
    }

    public function operationKey(): string
    {
        return 'target:' . hash('sha256', $this->tuple['request_id']);
    }
}
