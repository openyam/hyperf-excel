# Hyperf Excel

`openyam/hyperf-excel` is a concern-driven spreadsheet package for Hyperf 3.1. Its developer experience is inspired by `maatwebsite/excel`, while its runtime integrates directly with Hyperf instead of Laravel or Illuminate.

## Install

```bash
composer require openyam/hyperf-excel
php bin/hyperf.php vendor:publish openyam/hyperf-excel
```

It requires PHP 8.1+ and supports PhpSpreadsheet 3.10.7+ and 5.9+. Hyperf automatically discovers the package `ConfigProvider` and binds `OpenYam\HyperfExcel\ExcelInterface`.

## Export

```php
use OpenYam\HyperfExcel\Concerns\FromArray;
use OpenYam\HyperfExcel\Concerns\WithHeadings;
use OpenYam\HyperfExcel\Exportable;

final class UsersExport implements FromArray, WithHeadings
{
    use Exportable;

    public function headings(): array { return ['id', 'name']; }
    public function array(): array { return [[1, 'Alice'], [2, 'Bob']]; }
}

return (new UsersExport())->download('users.xlsx');
```

Exports can use arrays, Hyperf collections, iterators, database queries, Hyperf views, multiple sheets, mappings, styles, number formats, drawings and events. Output formats include XLSX, XLS, CSV, TSV, ODS, HTML and PDF.

## Import

Implement `ToArray`, `ToCollection`, `OnEachRow`, or `ToModel`, then add concerns such as `WithHeadingRow`, `WithValidation`, `WithBatchInserts`, `WithChunkReading`, `WithUpserts`, or `SkipsOnFailure`.

```php
$result = (new UsersImport())->import($uploadedFile);

foreach ($result->failures as $failure) {
    // row, attribute, errors, values
}
```

Model imports use Hyperf Database and configurable transactions. Validation uses Hyperf Validation. Local files, PSR-7 uploads and streams are accepted; configured Hyperf Filesystem disks are supported for remote storage.

`WithChunkReading` parses single-sheet CSV/TSV inputs in one streaming pass. Workbook formats such as XLSX use PhpSpreadsheet read filters to load row windows. It can be combined with `WithSheetSelection`, `WithReadFilter`, `WithHeadingFormatter`, `RemembersRowNumber`, `SkipsOnError`/`SkipsErrors`, and `WithProgress`.

With `WithHeadingRow`, values beyond the heading width receive position-based `column_N` keys. Existing names are disambiguated with `_2`, `_3`, and later suffixes. Streaming CSV and workbook readers use the same rule, and shorter rows are not padded with absent trailing fields.

For `WithProgress`, `processed`, `skipped`, and `total` are cumulative for the entire operation; `sheet` only identifies the worksheet currently being handled. Workbook import totals are candidate data rows calculated from worksheet metadata, heading/start rows, and row limits, so empty-row or read filters may leave the final handled count below `total`. Streaming CSV/TSV imports do not pre-scan the file and therefore report `total` and the row/column counts in `BeforeImport` worksheet metadata as `null`. Export sources generally have no known size and also report `total` as `null`.

The simple CSV/TSV streaming path dispatches global lifecycle events in this order: `BeforeExport`, `BeforeSheet`, `BeforeWriting`, output writing, and `AfterSheet`. The event workbook is read-only context and does not contain streamed rows. Implement `WithEvents` when listeners need to mutate the workbook; that automatically selects the full PhpSpreadsheet path.

`toArray()` and `toCollection()` also honor `WithSheetSelection`. With `WithMultipleSheets`, heading, start-row, row/column limit, and read-filter concerns are applied from each sheet import object.

For multi-sheet exports, string array keys provide default sheet titles, explicit `WithTitle` values take precedence, and integer keys only define order.

Query, iterator, and collection exports no longer retain a second full copy of source rows. Simple CSV/TSV exports stream directly. XLSX data is populated in batches, although PhpSpreadsheet still retains workbook cells in memory.

`FromQuery::query()` must return a Hyperf query builder or model builder. Query exports iterate with `cursor()`; `WithChunkSize` controls worksheet write batches only and no longer controls OFFSET pagination.

`WithCustomValueBinder` is scoped to the current workbook instead of PhpSpreadsheet global state. CSV/TSV exports using a custom binder automatically use the workbook writer path.

## Multiple sheets, views, and PDF

`WithMultipleSheets::sheets()` returns import or export objects keyed by worksheet index or name. Export string keys provide default titles, while an explicit `WithTitle` value takes precedence. `FromView` renders a Hyperf view through `RenderInterface`; its template name and data are supplied separately by `view()` and `viewData()`.

PDF exports use Dompdf by default. Install it first with `composer require dompdf/dompdf`. The driver can be changed to mPDF or TCPDF under `excel.pdf.driver` after installing the corresponding suggested dependency.

## Queue

Queued operations implement both `ShouldQueue` and `Queueable`. Only the class name and the array returned by `toQueuePayload()` are serialized. `queue()` and `queueImport()` return a `QueuedOperation`; completion and failure are exposed through Hyperf events and optional `WithQueueCallbacks` methods.

Queue options support `max_attempts`. Bind your own `OperationStatusStoreInterface` implementation to persist pending, running, completed, and failed states; query it with `$excel->queueStatus($operationId)`.

Operation status represents the import or export itself. A successful operation remains `completed` if its completion callback or event throws, while the notification exception is still propagated to the queue. When the operation fails, both failure notifications are attempted and their errors never replace the original operation exception. Consumer jobs always clear their operation context.

## Security defaults

Inputs are limited to 50 MiB. Failed uploads, stalled streams, invalid ZIP metadata, and archive path traversal are rejected. XLSX/ODS archives are limited to 512 MiB uncompressed data, a compression ratio of 100, and 10,000 entries. Reader types and stream wrappers are allow-listed. Exported strings beginning with `=`, `+`, `-`, or `@` are prefixed with a single quote by default; trusted exports may opt out with `WithFormulaProtection`.

All limits are configurable under `excel.security`; use `0` to disable a size, compression-ratio, or archive-entry limit. Local stores stage output in the destination directory and atomically replace the target; failures preserve the previous file and remove the staging file. Package-managed temporary directories and files use owner-only permissions. Remote stores continue to use the configured Hyperf Filesystem driver. Non-collection imports use `read_batch_size` (1000 by default) for internal row conversion batches. `toArray()` and `toCollection()` still retain their complete result sets.

For CSV/TSV settings, `delimiter` and `enclosure` must be single-byte characters, `escape_character` must be empty or a single-byte character, and `input_encoding` must be supported by mbstring. Global configuration and `WithCustomCsvSettings` use the same validation and invalid settings throw `ExcelException`.

Streaming and workbook-backed CSV/TSV exports share the same serialization rules, so enabling events, a custom binder, or a view does not change the CSV dialect. `excel_compatibility` uses a UTF-8 BOM, `sep=;`, a semicolon delimiter, quoted fields, and CRLF line endings.

Chunk reading cannot be combined with `WithCalculatedFormulas`, because referenced cells may be outside the loaded window.

## Quality checks

```bash
composer test
composer analyse
composer coverage-check
composer benchmark-quick
composer benchmark -- 50000 20 3
vendor/bin/php-cs-fixer fix --dry-run --using-cache=no --sequential
```

`benchmark-quick` runs one 5,000-row, 10-column CSV/XLSX pass. Full benchmark arguments are rows, columns, runs, and an optional `csv` or `xlsx` format. Phase progress is written to STDERR and the final median duration and peak-memory JSON is written to STDOUT; compare results on the same PHP version, dependency set, and hardware.

`coverage-check` requires PCOV or Xdebug and fails when line coverage falls below the committed progressive baseline.

## Migration notes

- `BeforeImport` exposes a reader context containing the reader type, worksheet metadata, and chunked flag instead of a complete `Spreadsheet`.
- `FromQuery::query()` implementations that returned `object` or custom wrappers must return `Hyperf\Database\Query\Builder|Hyperf\Database\Model\Builder`; query exports now use `cursor()` instead of `chunk()`.
- String keys in multi-sheet exports now become default worksheet titles and can be overridden with `WithTitle`.

Licensed under the MIT License.
