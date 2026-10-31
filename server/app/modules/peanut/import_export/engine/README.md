# Official import/export engine

## Export provider and real consumer

`module.json` exports `Engine\Contract\DataProvider`, `SchemaDefinition`,
`ColumnDefinition`, `ExportBatch` and `RowIssue`. The existing ModuleProvider
constructs its internal `DataProviderRegistry` with actual provider instances;
it is not an HTTP plugin loader. Its current provider is
`app\common\infrastructure\export\OperationLogExportProvider`, key `app.operation-log`.

That provider's `schema()` returns revision `1` and export-only columns, e.g.
`new ColumnDefinition('id', 'ID', false, true, false, 20)`. `SchemaDefinition`
fixes keys/headings and CSV order; missing fields or invalid/oversized text fail.
`exportBatch(context, cursor, limit)` returns scalar/null row maps in
`ExportBatch(rows, nextCursor)`. The real provider reads ascending IDs, with the
last ID as cursor only for a full batch. Limit is at most 500; a `null` cursor
ends export. Providers retain object authorization, not browser-selected SQL.

The command entry submits asynchronous work rather than calling batches inline:

```php
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\CsvExportOperation;

function submitOperationLogExport(
    ImportExportCommands $exports,
    AuthorizedOperationContext $context,
    string $requestKey,
): AsyncExportOperation {
    // Context comes from the authorized operation-log export use case.
    return $exports->submitCsvExport($context, CsvExportOperation::operationLog($requestKey));
}
```

`OperationLogExportApplicationService` is the existing authorized consumer.
The receipt identifies an operation, not an inline file or completed export;
the worker calls the registered provider and File serves the completed result.
See the [Web runtime guide](../../../../../../web/src/modules/peanut-import-export/README.md)
for list/cancel/download/error handling and identity disposal.

The real operation-log provider rejects both import methods. No production
import provider has been demonstrated. The interface alone does not authorize
one; an actual business provider must own validation, row idempotency and writes.
This guide adds no import implementation, fixture or simulated operation.

`peanut.import-export` owns the operation and row-error ledgers. Public callers
select registered provider keys and fixed CSV operations; they cannot submit a
class, SQL, table, filesystem path, or executable command.

Operation creation and task publication join the same transaction. Worker
attempt, progress, cancellation, and completion writes are fenced by operation,
task key, and attempt. Import rows use stable business idempotency keys, while
the task lease is renewed before each row or export batch and rechecked after
side effects.
