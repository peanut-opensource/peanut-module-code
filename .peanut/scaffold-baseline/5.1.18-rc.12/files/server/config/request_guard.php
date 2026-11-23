<?php

declare(strict_types=1);

// No route is guarded by default. For a new tenant-admin route, attach after
// login/permission middleware: ->middleware(RequestGuardMiddleware::class, 'example').
// Define 'example' with cache_store and matching lock_store ('file' or 'redis'),
// then limit (integer) and interval (e.g. '1 minute'), and/or repeat_interval.
// File locks require a single shared host; Redis locks require a Redis cache store.
// Never attach repeat_interval to a durable idempotent command: its stored-result
// replay is handled by the existing idempotency service.
return ['policies' => []];
