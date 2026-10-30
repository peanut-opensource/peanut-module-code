# 多格式内容的编辑器与预览器适配合同（039，承接 037）

- 状态：**公开实施目标合同，尚未完成各格式的真实实现与验收**；日期：2026-10-12。业务文档与实时富文本详见 [标准文档协同](document-collaboration-standard.md)；资源句柄、可见性、CDN 与访问安全仅引用 `peanut.file` 原有权威合同，本文件不另建一套文件管理权限。
- 范围：Code 新增目标 `peanut.content-tools`（中文“内容工具”，界面可称“内容编辑与预览”）统一处理工具注册与调度；`peanut.document` 与 `peanut.file` 负责各自数据/权限，现有 `peanut.rich-text` 是富文本工具提供者。Office 在线引擎不是 PHP Core，也不强迫所有派生 APP 安装。

## 1. 一个业务入口，多种格式处理

业务对象决定谁能访问；标准文档记录业务归属、状态、版本；`peanut.file` 记录二进制原件、受控地址与文件版本；具体格式由独立、显式登记的查看器或编辑器处理。**文件即使没有创建 `peanut.document` 记录，也允许在有独立 File 权限时由文件管理页面调用 `FileViewer`；不强制所有素材变成业务文档。**

逻辑内容类型 V1 明确：`rich_text`（原富文本结构及协同）与 `file_resource`（文件型内容，引用 `file_key`）。`file_resource` 的文档业务版本固定到已保存且不可变的文件字节/合法资源版本，不能反复覆盖原文件导致旧审批稿随之变化。图片、音视频、DOCX/XLSX/PPTX/PDF 不作为 Tiptap/Yjs 数据保存。

## 1.1 模块归属、组件合同与防止重复实现

- **内容工具模块**：`peanut.content-tools`；目标源码按既有规则为 `server/app/modules/peanut/content_tools/`（实际需要的注册/能力服务）和 `web/src/modules/peanut-content-tools/`。复用现行 module.json、原生容器、前端贡献和服务绑定，不增加独立仓库、插件市场、通用配置语言或自定义加载框架。
- **标准组件归属**：`ContentViewer`、`ContentEditor`、工具选择/能力状态、格式适配器的启动/销毁在 content-tools。`DocumentViewer/DocumentEditor` 留在 document，负责当前文档权限、版本、保存与协作会话后调用它们；File 的查看/编辑入口只是文件授权后的封装，不拥有另一套 PDF/Office/媒体编辑器。资源列表与 FileAssetPicker 仍属 file。
- **保留富文本实现**：`peanut.rich-text`/`RichTextEditor` 不盲目改名，不修改历史表和发布快照；仅注册为 rich-text provider。公共格式选择只在 content-tools 做一次；Document owner 负责文档身份、归属、版本和保存，旧 RichText CRUD/HMAC 管理入口退出活跃前端，历史记录保留且未知归属不重新开放写入。
- **合理粒度**：图片/PDF/音视频基础查看器可以作为 content-tools 内的适配器，不要求每种格式一个模块；同一 Office 引擎可以提供 DOCX/XLSX/PPTX 多种能力。只有真实存在独立依赖、部署、许可或版本交付需求时才拆独立 provider 模块，不先生成空包。
- **无环装配**：content-tools 定义工具协议和注册器，不直接依赖具体富文本/Office 实现；具体 provider 实现该协议，应用组合根选择并登记。document/file 的 UI 包可调用 content-tools，纯 File 存储后端不得反向依赖编辑 UI。所需服务端授权通过所属模块的可信公开入口核验，不用循环容器或请求中的类名解决依赖。
- **能力不是授权**：工具模块负责“这个格式能做什么”；Doc/File 负责“此人对这个对象允许做什么”。界面可用操作是两者与当前服务健康/开通状态的交集。客户端 capability/props 不能作为后端授权凭据；每次读取和保存都重新检查真实文档/资源上下文。

`ContentViewer`/`ContentEditor` 目标输入为已校验的内容描述、允许操作、已选工具标识及受控访问/保存回调；它们不自行持久化业务记录。目标事件为 `ready`、`capabilities-changed`、`dirty-change`、`save-request`、`error`，退出必须销毁编辑实例、订阅和临时授权。原有 `DocumentEditor` 的 `save-state` 仍由文档持久化回执驱动，而不是由工具的 `save-request` 冒充“已保存”。

注册项至少补充 `providerModuleKey`、`adapterVersion`、可用环境/服务依赖、MIME 范围、查看/编辑/协作模式、兼容协议和明确 unavailable 原因；字段通过既有贡献机制与生成类型登记。多个工具符合条件时按应用明确配置选择；不以注册先后、任意 URL 或客户端偏好绕开许可、权限与服务状态。注册组件也不意味着支持该格式的完整编辑或原样回存。

## 2. 格式与功能对应关系

| 内容 | 预览器/查看器 | 编辑器 | 首期必须保证 |
| --- | --- | --- | --- |
| 富文本 JSON | Tiptap 查看器 | Tiptap + Yjs/Hocuspocus | 实际编辑协作与持久保存 |
| 图片 | 带权限的图像展示 | 可选裁切/标注编辑器 | 安全预览或清楚降级 |
| PDF | PDF 阅读器（可评估 PDF.js） | 可选注释、表单或专业 PDF 引擎 | 预览授权；不假冒 PDF 原件编辑 |
| DOCX | Office 文档预览/转换 | 专用 Office 在线编辑 | 适配注册、能力识别、真实可用性检测 |
| XLSX | 工作簿/表格预览 | 专用电子表格编辑引擎 | 公式/图表保真由实际引擎单独核验 |
| PPTX | 幻灯片预览 | 专用演示编辑引擎 | 动画/字体/版式保真由实际引擎单独核验 |
| 视频、音频 | 受控媒体播放器、缩略图 | 可选剪辑/转码工具 | 观看权与下载原件权分离 |
| 其他或未知格式 | 安全元数据占位 | 暂无 | 没有真实预览/编辑能力即明确报错；下载须另行授权 |

上述表格规定**扩展点与降级规则**，并非宣布 DOCX、XLSX、PPTX 编辑功能已经实现。具体引擎（如 ONLYOFFICE Docs、其他可信 Office 服务）必须经许可、资源、权限、格式保真、升级和安全评估后显式选择；不能把供应商 API 名称直接当作已安装能力。

## 3. 公共类型与适配器注册（目标接口）

```ts
type ContentKind = 'rich_text' | 'file_resource';
type SurfaceIntent = 'view' | 'edit';
type SurfaceState = 'available' | 'read_only' | 'preview_unavailable' | 'edit_unsupported' | 'service_unavailable' | 'permission_denied';
interface SurfaceCapabilities {
  contentKind: ContentKind;
  verifiedMimeType?: string;
  viewerAdapterId?: string;
  editorAdapterId?: string;
  canView: boolean;
  canEdit: boolean;
  canDownloadOriginal: boolean;
  state: SurfaceState;
  capabilityRevision: string;
}
interface SurfaceAdapterRegistration {
  id: string;
  contentKinds: readonly ContentKind[];
  mimeTypes: readonly string[];
  intents: readonly SurfaceIntent[];
}
```

- `DocumentViewer` / `DocumentEditor`：面向有业务归属的文档，先经 `peanut.document` 对业务权限、归档/版本进行判断，再调用 File 对原件/衍生物授权，最后按 `SurfaceCapabilities` 选择适配器。
- `FileViewer` / 可选 `FileEditor`：面向文件管理等**不依赖文档归属**的界面，按 `peanut.file` 本身权限处理；业务侧不能借此绕过文档归属核验。
- `RichTextEditor`：保留现有公开导出，作为注册器中富文本编辑适配器；`modelValue` 采用不可变整文档替换，调用方变更内容时提供新根对象；不加载 Office 转换依赖。
- `SurfaceAdapterRegistration` 来自被审查的 Code 模块正式注册/前端贡献，不允许租户任意上传可执行 JS、动态注入 URL 或创建第二个 manifest；同一 MIME 多提供者通过应用显式选择，不依赖随机加载顺序。
- 能力由 PHP/模块当前安装、租户开通、文件/业务授权、真实 MIME/魔数、服务健康和实例配置计算；前端 props、扩展名、MIME 提示、`file_key` 均不是授权证明。`canView`、`canEdit`、`canDownloadOriginal` **互不蕴含**。

## 4. 预览、编辑与持久化安全

1. 浏览器请求具体预览前必须核最新权限。派生缩略图、预览 PDF、转码片段仍属于受控资源，不因源文件被业务文档引用而公开；不把临时 URL 写入正文/数据库/日志，也不直接将私有存储 Bucket 开放给 Office 查看器。
2. 服务端转换不可信 Office/PDF/媒体时，需要隔离执行、网络限制、宏/外部引用防护、资源/CPU/页数/时长上限、文件扩展与魔数校验、超时与失败重试合同。未部署可信转换能力时返回 `preview_unavailable`，下载也仅在有独立权限时出现。
3. 富文本 `Yjs` 自动保存/撤权沿 [主文档合同](document-collaboration-standard.md)；Office 类引擎如支持多人协作，使用其自身认证、文件锁、保存回调和版本写入合同，不得把 Office 字节塞进 Hocuspocus `Y.Doc` 当作自动支持协同。
4. 文件型编辑必须返回**新受控 `file_key` 或可信不可变文件修订**，经 PHP 再核当前业务所有者、租户、编辑权限、原文件关联与 `expected_state_seq` 后 CAS 绑定；已冻结的送审/发布版本继续指向原哈希字节，不随活跃文件改变。
5. 出现功能未安装、用户失权、预览生成失败或在线引擎不可用时显示准确的 `state`；不能默默回退到不安全的文件下载、未经审阅的 iframe、老业务角色或不可追踪的外部在线编辑站。

## 5. 分期与最低证据

- V1 基础交付：`content_kind` 识别、统一能力查询、适配器明确注册/选择、`DocumentViewer`/`DocumentEditor` 接口和安全降级；富文本的实际多人协同；文件类在已有可用组件范围内提供权限正确的预览。
- 可选后续包：DOCX/XLSX/PPTX 的真实 Office 查看/在线编辑服务、PDF 复杂编辑、音视频剪辑、图片专业编辑、跨格式转换流水线。每一个正式打开的能力均需独立的适配器版本、文件保真、权限撤销、不可变版本和异常恢复验收。
- 代表性验证：同文件名但不同 MIME/魔数、无权限的文件键、受限预览但无下载权、未知格式、服务缺失、衍生物失效、Excel 公式和 PPT 动画的保真、替换文件后的冻结版本不变化、租户/人员切换不串预览缓存。只允许将已实际验证的组合标记 `available`。

## 6. 参考来源（不是自动采用供应商）

- [ONLYOFFICE Docs API](https://api.onlyoffice.com/docs/docs-api/get-started/how-it-works)：示例说明在线编辑服务与应用自有文件存储/授权应分开；是否采用须另行核许可和运行合同。
- [ONLYOFFICE 支持的格式](https://github.com/ONLYOFFICE/document-formats)：仅用于后续适配器测试范围分析，不代表 Peanut 已支持对应格式。
