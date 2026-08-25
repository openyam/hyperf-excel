# Hyperf Excel

[English documentation](README_EN.md)

`openyam/hyperf-excel` 为 Hyperf 3.1 提供基于 Concern 的 Excel 导入、导出、下载、存储与异步处理能力。API 设计参考 `maatwebsite/excel`，运行时完全使用 Hyperf 组件，不需要安装 Laravel 或 Illuminate。

## 环境要求

- PHP 8.1+
- Hyperf 3.1
- PhpSpreadsheet 3.10.7+ 或 5.9+
- PHP ZIP、XML、GD、mbstring 等 PhpSpreadsheet 必需扩展

```bash
composer require openyam/hyperf-excel
php bin/hyperf.php vendor:publish openyam/hyperf-excel
```

Hyperf 会通过 `ConfigProvider` 自动注册 `OpenYam\HyperfExcel\ExcelInterface`。配置发布到 `config/autoload/excel.php`。

## 导出与下载

```php
<?php

use Hyperf\Collection\Collection;
use OpenYam\HyperfExcel\Concerns\FromCollection;
use OpenYam\HyperfExcel\Concerns\ShouldAutoSize;
use OpenYam\HyperfExcel\Concerns\WithHeadings;
use OpenYam\HyperfExcel\Concerns\WithMapping;
use OpenYam\HyperfExcel\Exportable;

final class UsersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    use Exportable;

    public function __construct(private readonly Collection $users) {}

    public function collection(): Collection
    {
        return $this->users;
    }

    public function headings(): array
    {
        return ['ID', '姓名', '邮箱'];
    }

    public function map(mixed $user): array
    {
        return [$user->id, $user->name, $user->email];
    }
}

// Controller 中返回 PSR-7 下载响应
return (new UsersExport($users))->download('users.xlsx');

// 保存到本地路径或 Hyperf Filesystem 磁盘
$export->store(BASE_PATH . '/runtime/users.xlsx');
$export->store('exports/users.xlsx', 's3');
```

也可以注入 `ExcelInterface` 并调用 `download()`、`store()` 或 `raw()`。支持 `FromArray`、`FromCollection`、`FromIterator`、`FromQuery`、`FromView`、`WithMultipleSheets`、样式、列格式、宽度、绘图、属性和事件等 Concerns。

## 导入

```php
<?php

use Hyperf\Database\Model\Model;
use OpenYam\HyperfExcel\Concerns\SkipsEmptyRows;
use OpenYam\HyperfExcel\Concerns\ToModel;
use OpenYam\HyperfExcel\Concerns\WithBatchInserts;
use OpenYam\HyperfExcel\Concerns\WithChunkReading;
use OpenYam\HyperfExcel\Concerns\WithHeadingRow;
use OpenYam\HyperfExcel\Concerns\WithValidation;
use OpenYam\HyperfExcel\Importable;

final class UsersImport implements ToModel, WithHeadingRow, WithValidation, WithBatchInserts, WithChunkReading, SkipsEmptyRows
{
    use Importable;

    public function headingRow(): int { return 1; }
    public function batchSize(): int { return 500; }
    public function chunkSize(): int { return 500; }

    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    public function model(array $row): ?Model
    {
        return new User(['name' => $row['name'], 'email' => $row['email']]);
    }
}

$result = (new UsersImport())->import($uploadedFile);

if (! $result->successful()) {
    foreach ($result->failures as $failure) {
        // $failure->row, $failure->attribute, $failure->errors
    }
}
```

默认模型导入使用数据库事务。分块导入按块提交；实现 `SkipsOnFailure` 时无效行会回调 `onFailure()` 并继续，否则抛出 `ValidationException`。

`WithChunkReading` 对单 Sheet CSV/TSV 使用一次遍历的流式解析；XLSX 等工作簿格式使用 PhpSpreadsheet 读取过滤器按行窗口加载。它可以与 `WithSheetSelection`、`WithReadFilter`、`WithHeadingFormatter`、`RemembersRowNumber`、`SkipsOnError`/`SkipsErrors` 和 `WithProgress` 组合使用。

使用 `WithHeadingRow` 时，如果数据行比标题行更宽，多出的列会按实际列序号生成 `column_N` 键；若该名称已被标题占用，则追加 `_2`、`_3` 等后缀。CSV 流式路径与工作簿路径使用相同规则，较短的数据行不会补充不存在的尾部字段。

`toArray()` 和 `toCollection()` 同样遵循 `WithSheetSelection`；使用 `WithMultipleSheets` 时，每个 Sheet 会应用对应子导入对象的标题行、起始行、行列限制和读取过滤设置。

查询、迭代器和集合导出不再缓存完整业务数据。简单 CSV/TSV 会直接流式写入；XLSX 虽然分批填充，但 PhpSpreadsheet 仍需在内存中保存工作簿单元格。

`FromQuery::query()` 必须返回 Hyperf 的 Query Builder 或 Model Builder。查询导出通过 `cursor()` 逐行读取，`WithChunkSize` 只控制写入工作表的批次大小，不再控制数据库 OFFSET 分页。

`WithCustomValueBinder` 绑定到当前工作簿，不修改 PhpSpreadsheet 的全局 binder，适用于 Hyperf 并发导出。CSV/TSV 使用自定义 binder 时会自动切换到工作簿写入路径。

实现 `WithProgress` 后，`processed`、`skipped` 和 `total` 始终表示整个操作的累计进度，`sheet` 仅标识当前工作表。工作簿导入的 `total` 是根据 Sheet 元数据、标题行、起始行和行数限制计算的候选数据行数；空行或读取过滤可能导致最终处理数小于 `total`。为保证 CSV/TSV 真正单遍读取，流式导入不会预扫描文件，其进度和 `BeforeImport` worksheet metadata 中的行列总数为 `null`。导出数据源通常也无法预知总量，因此 `total` 为 `null`。

简单 CSV/TSV 的流式路径会按 `BeforeExport`、`BeforeSheet`、`BeforeWriting`、写入内容、`AfterSheet` 的顺序派发全局生命周期事件。事件中的工作簿仅作为只读上下文，不会反映已写入的流式数据；需要通过事件修改工作簿时，请让导出对象实现 `WithEvents`，包会自动使用完整 PhpSpreadsheet 路径。

## 多 Sheet、视图与 PDF

- `WithMultipleSheets::sheets()` 返回按索引或名称排列的导入/导出对象。导出时字符串键是默认 Sheet 标题，子导出实现的 `WithTitle` 优先；整数键仅表示顺序。
- `FromView::view()` 返回模板名，`viewData()` 返回模板数据，内容由 Hyperf `RenderInterface` 渲染。
- PDF 默认使用 Dompdf，需要时请先安装：`composer require dompdf/dompdf`，然后调用 `$export->download('report.pdf')`。也可在配置中选择 `Mpdf` 或 `Tcpdf`，并安装相应建议依赖。

## 异步队列

队列类必须同时实现 `ShouldQueue` 和 `Queueable`。载荷必须是可安全序列化的普通数组；消费者通过容器重新创建业务对象。

```php
use OpenYam\HyperfExcel\Concerns\Queueable;
use OpenYam\HyperfExcel\Concerns\ShouldQueue;

final class ReportExport implements FromQuery, ShouldQueue, Queueable
{
    use Exportable;

    private int $tenantId;

    public function toQueuePayload(): array { return ['tenant_id' => $this->tenantId]; }
    public function useQueuePayload(array $payload): void { $this->tenantId = (int) $payload['tenant_id']; }
    // query() ...
}

$operation = $export->queue('exports/report.xlsx', 's3', options: ['queue' => 'default']);
```

`QueuedOperation` 包含操作 ID 和投递结果。完成与失败分别派发 `QueueCompleted`、`QueueFailed`；实现 `WithQueueCallbacks` 可在业务对象上接收回调。队列不内置状态表。

队列状态表示导入或导出本身的结果：业务成功后状态保持 `completed`，即使完成回调或完成事件抛出异常；通知异常仍会交给队列处理。业务失败时状态记录为 `failed`，失败回调和失败事件都会尝试执行，且通知异常不会覆盖原始业务异常。消费端始终清理当前协程的操作上下文。

队列选项支持 `max_attempts`。包提供 `OperationStatusStoreInterface`，可在容器中替换默认空实现，以保存 `pending`、`running`、`completed`、`failed` 状态和进度；通过 `$excel->queueStatus($operationId)` 查询。状态存储不绑定数据库或 Redis。

## 安全默认值

- 输入文件最大 50 MiB。
- XLSX/ODS 解压总量最大 512 MiB，最大压缩比 100。
- XLSX/ODS 归档条目最多 10000 个，避免大量空条目消耗处理资源。
- 只允许配置中的 reader 类型和 `file` stream wrapper。
- 上传错误、无进展的输入流、异常 ZIP 元数据和归档路径穿越会被拒绝。
- 导出字符串以 `= + - @` 开头时默认添加单引号，降低公式注入风险。可信导出可实现 `WithFormulaProtection` 并返回 `false`。

这些限制可在 `excel.security` 中调整；将相应数值设为 `0` 可关闭大小、压缩比或归档条目数量限制。

CSV/TSV 配置中的 `delimiter` 和 `enclosure` 必须是单字节字符，`escape_character` 必须为空或单字节字符，`input_encoding` 必须是 mbstring 支持的编码。全局配置和 `WithCustomCsvSettings` 都使用相同校验；非法配置会抛出 `ExcelException`。

CSV/TSV 的流式和工作簿导出路径共享同一序列化规则，启用事件、自定义 binder 或视图不会改变 CSV 方言。`excel_compatibility` 使用 UTF-8 BOM、`sep=;`、分号分隔符、双引号包围和 CRLF 换行。

导出到本地路径时会先写入目标目录中的临时文件，再通过原子重命名替换目标，失败时保留原文件并清理中间文件。包管理的临时目录和文件使用仅所有者可访问的权限；远程磁盘仍由配置的 Hyperf Filesystem 驱动处理。

内部非分块模型/逐行导入使用 `read_batch_size`（默认 1000）控制行转换批次。`toArray()` 和 `toCollection()` 因需要返回完整结果，仍会在内存中保留全部结果行。

主版本迁移注意：`BeforeImport` 不再暴露完整 `Spreadsheet`，而是通过 `context` 提供 reader type、Sheet metadata 和是否分块的信息；分块读取不能与 `WithCalculatedFormulas` 同时使用，因为公式引用的单元格可能不在当前窗口。

## 与 maatwebsite/excel 的关系

本包提供迁移友好的 API 风格，不承诺 `Maatwebsite\Excel` 类零修改运行：

| Laravel Excel | Hyperf Excel |
| --- | --- |
| `Maatwebsite\Excel\Concerns\*` | `OpenYam\HyperfExcel\Concerns\*` |
| Illuminate Collection | Hyperf Collection |
| Eloquent Model/Builder | Hyperf Model/Builder |
| Laravel Validation | Hyperf Validation |
| Laravel Filesystem | Hyperf Filesystem |
| PendingDispatch | `QueuedOperation` + Hyperf events |
| Laravel View | Hyperf `RenderInterface` |

迁移通常从替换 `use` 语句开始。视图需拆分为 `view()` 和 `viewData()`；队列对象需显式声明安全载荷。

## 开发

```bash
composer install
composer test
composer analyse
composer coverage-check
composer benchmark-quick
composer benchmark -- 50000 20 3
```

`benchmark-quick` 使用 5000 行、10 列和单轮 CSV/XLSX 运行完成快速验证。完整基准参数依次为行数、列数、运行次数和可选格式（`csv` 或 `xlsx`）；运行期间在 STDERR 输出当前阶段，最终在 STDOUT 输出导入导出的中位耗时与峰值内存 JSON。建议在相同 PHP、依赖版本和硬件上比较优化前后的结果。

`coverage-check` 需要启用 PCOV 或 Xdebug；它会在行覆盖率低于仓库中的渐进基线时失败。

## 主版本迁移

- `FromQuery::query()` 的返回类型从 `object` 收紧为 `Hyperf\Database\Query\Builder|Hyperf\Database\Model\Builder`；自定义包装对象需改为返回原生 Builder。
- 查询导出改用 `cursor()`，不再调用 Builder 的 `chunk()`。
- 多 Sheet 导出的字符串数组键现在会成为默认 Sheet 标题；`WithTitle` 可显式覆盖。

许可证：[MIT](LICENSE)。
