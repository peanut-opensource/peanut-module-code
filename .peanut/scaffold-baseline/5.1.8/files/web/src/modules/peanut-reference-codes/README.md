# 基础字典的正式复用入口

模块标识为 `peanut.reference-codes`。通用字典与版本化编码使用现有的不同入口，不需要为了下拉选项声明版本化代码集。

## 前端版本化编码

模块源码包 `@peanut-admin/peanut-reference-codes` 的 `.` 导出路由贡献，`./runtime` 导出 `ReferenceCodesPage`、`createReferenceCodesRuntime`、`createReferenceCodesFetchTransport`、注入 key 和输入输出类型。这是宿主源码集成入口，未声明独立 npm 发行物。宿主提供 Vue、Element Plus、UI、i18n 及本模块 `locale` 中的语言消息。

实际消费者是本目录的 `RuntimePage.vue`，已通过同一 runtime 入口使用页面和工厂；路由贡献挂载 `/system/reference-codes`。复用时沿用该文件的会话 fetch 与权限回调；独立宿主在安装此源码包后可使用：

```ts
import { onBeforeUnmount, provide } from 'vue';
import {
  createReferenceCodesFetchTransport,
  createReferenceCodesRuntime,
  referenceCodesRuntimeKey,
  ReferenceCodesPage,
} from '@peanut-admin/peanut-reference-codes/runtime';

// sessionFetch、canRead、canManage 由当前宿主的会话与授权入口提供。
const runtime = createReferenceCodesRuntime({
  transport: createReferenceCodesFetchTransport({
    baseUrl: window.location.origin,
    fetch: sessionFetch,
  }),
  canRead,
  canManage,
});
provide(referenceCodesRuntimeKey, runtime);
onBeforeUnmount(runtime.dispose);
// 在模板渲染 <ReferenceCodesPage />；页面负责首次 loadSets。
```

工厂返回每个实例独立的响应式 `state` 与查询/命令方法。无页面消费者可依次 `loadSets()`、`selectSet(moduleKey, setKey)`，读取 `state.entries`；条目含 code、revision、etag、lifecycle 和可空的 effective 版本。时间输入须包含毫秒及 `Z` 或时区偏移，输出归一为 UTC。`setAsOf` 对无效时间抛出 `REFERENCE_CODES_INSTANT_INVALID`，页面提供内联错误提示，原查询结果不变。

读权限为 `peanut.reference-codes.read`，写权限为 `peanut.reference-codes.manage`；前端回调只控制交互，服务端仍校验当前身份、权限、租户和所属模块启用状态。transport 使用宿主会话请求，不自己创建或切换租户。切换身份/租户或卸载时销毁旧 runtime，再创建新实例；`dispose()` 中止请求并清空条目、草稿和错误，不能将 runtime 保存成跨租户全局单例。

请求错误记录在 `state.errors`，保留请求编号；过期版本记录在 `state.stale`，使用 `reloadStale(code)` 后由用户重新确认，不能无条件覆盖。创建、追加版本和退役沿现有 Idempotency-Key；追加/退役使用当前 ETag。退役永久保留编码身份，不能恢复或复用。`state.sets` 只包含启用模块声明的代码集；为空是明确的空状态，不会自动创建代码集或业务数据。

## 通用字典与后端合同

通用字典管理的真实消费者为 `web/src/views/system/dict/index.vue` 与其 `DataDrawer.vue`，已有类型/条目维护、状态和分页。宿主前端现有 `web/src/api/system/dict.ts` 提供 `getDictDataByType(typeValue)`，调用 `GET /adminapi/dict/data/byType?type_value=...`。返回启用选项（id、name、value、sort）；此宿主 API 文件不是独立模块包导出。

跨模块 PHP 通过 module.json 的 `contracts.exports` 及 Provider 绑定使用：

| 公开入口 | 输入与输出 | 真实消费者 |
| --- | --- | --- |
| `Contract\DictionaryQuery` | 可信 TenantContext；types/entries 分页 DTO、type/entry 可空 DTO、enabledTypes/enabledByType 列表 | DictDataApplicationService 的 byType 经同一 DictionaryRuntime 实现查询 |
| `Contract\TenantDictionaryCommands` | 可信 TenantContext、实体 ID、已校验值；创建/更新 DTO 或 void | DictTypeApplicationService、DictDataApplicationService 经 DictionaryRuntime 写入 |
| `Contract\SystemReferenceCodeQuery` | 类型标识；实例共享只读选项 DTO 列表 | DictionaryRuntime 的 systemEntriesByType 入口；当前未确认独立跨模块业务消费者 |
| `Service\ReferenceCodeCatalogService` | 选定 ManifestDocument 集合、当前时间；同步部署声明或输出 definitionPlan | 宿主 ModuleCatalogApplier |

例如在已有可信执行上下文及授权的模块用例中构造注入 `DictionaryQuery $dictionary`，调用 `$dictionary->enabledByType($context, 'your-type')`，映射返回 DTO 的 `name` / `value`。不要直接读写字典私表或导出 ORM。该读取合并共享系统项与当前租户启用项，重复 value 以系统项优先；空列表表示没有启用选项，权限/网络/数据库错误不应被消费者改成空列表。

DictionaryQuery/Commands 要求宿主先建立正式租户执行 Scope，并在用例入口校验权限；TenantContext 参数不替代执行 Scope 或调用方授权。现有 HTTP 入口带登录、模块、权限中间件。写入的类型冲突/被占用、跨租户不存在等错误沿现有业务异常处理，不由复用方绕过。模块停用拒绝新操作，保留字典数据；目录同步属于部署生命周期，不授予普通业务调用方安装模块或写任意租户数据的权限。

## 查询与命令组合及拒绝例

在已有正式租户 Scope、授权和输入校验的模块用例中，注入公开接口；下面方法体中的 `$context` 是可信 `TenantContext`，`$entryId` 是当前租户已选条目 ID：

```php
use PeanutAdmin\Modules\ReferenceCodes\Contract\DictionaryQuery;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryCommands;
use PeanutAdmin\Modules\ReferenceCodes\Dto\DictionaryEntryDto;

// 由宿主原生容器注入，不自行 new 数据库 provider 或建立另一份 registry。
public function __construct(
    private DictionaryQuery $dictionary,
    private TenantDictionaryCommands $dictionaryCommands,
) {}

// 在已授权的用例方法体中：
$this->dictionaryCommands->setEntryDisabled($context, $entryId, false);
$options = array_map(
    static fn(DictionaryEntryDto $entry): array => ['label' => $entry->name, 'value' => $entry->value],
    $this->dictionary->enabledByType($context, 'catalog-status'),
);
```

`ModuleProvider` 将两个接口绑定到现有 `DictionaryRuntime`，后者使用已有租户/系统 provider；部署的版本化代码集则使用现有 `DeployedReferenceCodeSetRegistry` → `ReferenceCodeSetRegistry`。普通字典查询不需要往版本化 registry 再声明一次。自定义页面只选择上述字典 API 或版本化 runtime 中对应业务的一条数据链；复用已加载的结果，不同时发两种查询来拼相同选项。

拒绝例：删除仍有数据项的字典类型时，`deleteType($context, $typeId)` 抛出 `DICTIONARY_TYPE_IN_USE`；重复类型标识写入抛出 `DICTIONARY_TYPE_EXISTS`。让现有业务错误边界呈现失败，不把它变成成功、先删数据项或直接写私表。其他租户的 ID 保持不存在语义，不能通过传入请求中的 tenant ID 切换 Scope。无读/写权限仍在宿主入口拒绝；接口参数本身不授予权限。
