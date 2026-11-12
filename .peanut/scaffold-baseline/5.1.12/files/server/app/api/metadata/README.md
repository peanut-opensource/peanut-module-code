# HTTP API metadata

`contracts/openapi.json` is the structured HTTP contract source. Every declared
method/path pair must match the route inventory built by
`server/route/registry_source.php`; the generator rejects declarations for
routes that do not exist.

Module owners may add a `api/metadata/openapi.php` file below their module. The
file returns either an OpenAPI `paths` fragment keyed by concrete runtime path,
or `{paths, components}` when it owns reusable schemas, responses, parameters
or request bodies. The generator merges these fragments and rejects duplicate
operations or component names, unknown routes, unresolved references and empty
schemas.

For example, the owner of `peanut.task` adds only its own file at
`server/app/modules/peanut/task/api/metadata/openapi.php`:

```php
<?php
declare(strict_types=1);

return [
    '/adminapi/peanut.task/jobs' => [
        'get' => [
            'operationId' => 'listTaskJobs',
            'tags' => ['Task'],
            'responses' => [
                '200' => [
                    'description' => 'Typed task list.',
                    'content' => ['application/json' => ['schema' => [
                        '$ref' => '#/components/schemas/TaskJobListResponse',
                    ]]],
                ],
                '401' => ['$ref' => '#/components/responses/ErrorResponse'],
                '403' => ['$ref' => '#/components/responses/ErrorResponse'],
            ],
        ],
    ],
];
```

The path and method must already exist in the route inventory. Owners must add
real parameters, request bodies, response schemas and existing stable business
errors for the implemented controller contract; the generator never guesses
them from method names. Shared envelopes and errors stay in the central source;
module-owned records stay beside the Module. A generated operation is counted
separately as `complete` or `partial`: generic `ApiResponse`, missing response
schemas, mismatched path parameters and security-domain mismatches remain
explicit gaps even when an `operationId` exists.

Run the no-write contract check while editing (use the PHP executable selected by the environment; do not hard-code a Homebrew path):

```shell
php scripts/generate-api-contracts.php --check \
  --catalog-output=/tmp/peanut-api-catalog.json
```

Run `php scripts/generate-api-contracts.php` to produce the application-owned
`server/generated/openapi.json`, the route/access/error catalog at
`server/generated/api-catalog.json`, module-local TypeScript path maps and typed
SDK factories, and `web/src/generated/openapi.d.ts`. A `--project-root=<absolute-path>`
mirror is optional and explicit; it is not a default dependency and is not needed by
generated applications.
