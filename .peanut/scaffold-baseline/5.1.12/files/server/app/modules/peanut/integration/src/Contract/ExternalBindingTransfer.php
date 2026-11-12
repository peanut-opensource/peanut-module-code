<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Trusted configuration-transfer boundary. Callers redact secrets before public serialization. */
interface ExternalBindingTransfer
{
    /** @return list<array{provider:string,value:array{identity_hash:?string,identity_hint:string,config:array<string,mixed>,status:bool},revision:int}> */
    public function snapshot(TenantContext $context): array;

    /** @return null|array{provider:string,value:array{identity_hash:?string,identity_hint:string,config:array<string,mixed>,status:bool},revision:int} */
    public function current(TenantContext $context, string $provider): ?array;

    /**
     * null revision means create-only; a non-null revision requires exactly that existing state.
     * Never imports a callback key. Participates in the caller's transaction, or owns one when called alone.
     * @param array{identity_hash:?string,identity_hint:string,config:array<string,mixed>,status:bool} $value
     */
    public function apply(TenantContext $context, string $provider, array $value, ?int $expectedRevision): void;
}
