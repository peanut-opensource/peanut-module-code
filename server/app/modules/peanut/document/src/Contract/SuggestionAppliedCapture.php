<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;

/** Signed-service fixed capture data. Only the provider can prove its original durable barrier binding. */
final readonly class SuggestionAppliedCapture
{
    public string $binary;
    public RichTextProjection $projection;
    public int $stateSeq;
    public string $baselineBinarySha256;
    public string $baselineProjectionSha256;
    public string $binarySha256;
    public string $projectionSha256;
    public string $snapshotBase64;
    public string $referenceDigest;

    public function __construct(public BusinessBarrierPlan $plan, public array $data)
    {
        foreach (['protocol','operation_id','input_sha256','room_key','holder_key','lease_epoch'] as $key) {
            if (($data[$key] ?? null) !== $plan->data[$key]) {
                throw new \InvalidArgumentException('DOCUMENT_SUGGESTION_CAPTURE_INVALID');
            }
        }
        if ($plan->data['action'] !== 'suggestion.accept' || ($data['phase'] ?? null) !== 'suggestion_applied'
            || !is_int($data['base_state_seq'] ?? null) || $data['base_state_seq'] !== $plan->data['base_state_seq']
            || !is_int($data['actor_member_id'] ?? null) || $data['actor_member_id'] < 1
            || !is_array($data['structured_content'] ?? null)) {
            throw new \InvalidArgumentException('DOCUMENT_SUGGESTION_CAPTURE_INVALID');
        }
        foreach (['baseline_binary_sha256','baseline_projection_sha256','binary_sha256','projection_sha256','reference_digest'] as $key) {
            if (!is_string($data[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                throw new \InvalidArgumentException('DOCUMENT_SUGGESTION_CAPTURE_INVALID');
            }
        }
        $binary = is_string($data['binary_base64'] ?? null) ? base64_decode($data['binary_base64'], true) : false;
        $snapshot = is_string($data['snapshot_base64'] ?? null) ? base64_decode($data['snapshot_base64'], true) : false;
        if (!is_string($binary) || $binary === '' || strlen($binary) > 4194304
            || base64_encode($binary) !== $data['binary_base64'] || hash('sha256', $binary) !== $data['binary_sha256']
            || !is_string($snapshot) || $snapshot === '' || strlen($snapshot) > 4194304 || base64_encode($snapshot) !== $data['snapshot_base64']) {
            throw new \InvalidArgumentException('DOCUMENT_SUGGESTION_CAPTURE_INVALID');
        }
        $this->projection = RichTextProjection::fromStructured($data['structured_content']);
        if (hash('sha256', $this->projection->json) !== $data['projection_sha256']
            || AcceptedStoreInput::referencesDigest($this->projection->fileReferences) !== $data['reference_digest']) {
            throw new \InvalidArgumentException('DOCUMENT_SUGGESTION_CAPTURE_INVALID');
        }
        $this->binary = $binary;
        $this->stateSeq = $data['base_state_seq'];
        $this->baselineBinarySha256 = $data['baseline_binary_sha256'];
        $this->baselineProjectionSha256 = $data['baseline_projection_sha256'];
        $this->binarySha256 = $data['binary_sha256'];
        $this->projectionSha256 = $data['projection_sha256'];
        $this->snapshotBase64 = $data['snapshot_base64'];
        $this->referenceDigest = $data['reference_digest'];
    }
}
