<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** Immutable wire binding, never an authorization grant. The provider verifies the original ledger. */
final readonly class BusinessBarrierPlan
{
    public DocumentOwnerReference $owner;

    public function __construct(public array $data)
    {
        $keys = ['protocol','binding','operation_id','action','input_sha256','barrier_key','room_key','service_instance_id','control_mode','holder_key','lease_epoch','base_state_seq','base_auth_revision','owner_revision','deadline'];
        if (count($data) !== count($keys) || array_diff(array_keys($data), $keys) !== []
            || ($data['protocol'] ?? null) !== 2 || !is_array($data['binding'] ?? null)) {
            throw new \InvalidArgumentException('DOCUMENT_BUSINESS_BARRIER_INVALID');
        }
        $b = $data['binding'];
        if (count($b) !== 5 || array_diff(array_keys($b), ['instance_id','edition','tenant_id','document_id','owner']) !== []
            || !is_string($b['instance_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $b['instance_id']) !== 1
            || !in_array($b['edition'] ?? null, ['engine','standalone','multi-tenant'], true)
            || !is_int($b['tenant_id'] ?? null) || $b['tenant_id'] < 1 || !is_int($b['document_id'] ?? null) || $b['document_id'] < 1
            || !is_array($b['owner'] ?? null) || count($b['owner']) !== 4
            || array_diff(array_keys($b['owner']), ['module_key','object_type','object_id','slot']) !== []) {
            throw new \InvalidArgumentException('DOCUMENT_BUSINESS_BARRIER_INVALID');
        }
        foreach (['module_key','object_type','object_id','slot'] as $key) {
            if (!is_string($b['owner'][$key] ?? null)) {
                throw new \InvalidArgumentException('DOCUMENT_BUSINESS_BARRIER_INVALID');
            }
        }
        $this->owner = new DocumentOwnerReference($b['owner']['module_key'], $b['owner']['object_type'], $b['owner']['object_id'], $b['owner']['slot']);
        foreach (['input_sha256','barrier_key','room_key','owner_revision'] as $key) {
            if (!is_string($data[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                throw new \InvalidArgumentException('DOCUMENT_BUSINESS_BARRIER_INVALID');
            }
        }
        foreach (['lease_epoch','base_auth_revision','deadline'] as $key) {
            if (!is_int($data[$key] ?? null) || $data[$key] < 1) {
                throw new \InvalidArgumentException('DOCUMENT_BUSINESS_BARRIER_INVALID');
            }
        }
        foreach (['base_state_seq'] as $key) {
            if (!is_int($data[$key] ?? null) || $data[$key] < 0) {
                throw new \InvalidArgumentException('DOCUMENT_BUSINESS_BARRIER_INVALID');
            }
        }
        if (!is_string($data['operation_id'] ?? null) || preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $data['operation_id']) !== 1
            || !in_array($data['control_mode'] ?? null, ['native','closed_persisted'], true)
            || ($data['control_mode'] === 'native'
                ? (!is_string($data['holder_key']) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $data['holder_key']) !== 1)
                : ($data['holder_key'] !== null || $data['action'] !== 'restore'))
            || !is_string($data['service_instance_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $data['service_instance_id']) !== 1
            || !in_array($data['action'] ?? null, ['version.restore','archive','restore'], true)) {
            throw new \InvalidArgumentException('DOCUMENT_BUSINESS_BARRIER_INVALID');
        }
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(\PeanutAdmin\Modules\RichText\Contract\RichTextProjection::canonical($this->data), JSON_THROW_ON_ERROR));
    }
}
