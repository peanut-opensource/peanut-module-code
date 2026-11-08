# 管理界面设计与开发准则

本准则适用于管理端及模块页面，也是 Agent 修改 UI 前的必读入口。目标是清晰、舒展、克制的企业界面：用信息顺序、对齐、留白和规律的颜色建立层次。

## 设计参考与组件边界

参考蚂蚁集团 Ant Design 的[价值观](https://ant.design/docs/spec/values)、[布局](https://ant.design/docs/spec/layout)、[色彩](https://ant.design/docs/spec/colors)、[字体](https://ant.design/docs/spec/font)（查阅：2026-11-08；参考项目 MIT 许可）。借鉴空间节奏、语义色和排版原则；下述参数属于 Peanut 项目约定，不是 Ant 原文。

管理框架配置另参考 [Vue Vben Admin 的 preferences](https://github.com/vbenjs/vue-vben-admin/blob/main/packages/%40core/preferences/src/config.ts)（2026-11-08 查阅，MIT）：布局、导航、主题和标签缓存分别配置。采用其职责分离思路，不复制整套配置、组件、状态管理或依赖；Peanut 的实际入口仍是下文现有 Shell、Page 和 Element Plus 合同。折叠图标栏与子菜单浮层沿用 [Element Plus Menu](https://element-plus.org/en-US/component/menu.html) 的 collapse / SubMenu 能力；不为收缩模式重建菜单数据或独立Popover导航。

内容区域参考 MIT 许可的 ng-alain／Delon：[Page Header](https://github.com/ng-alain/delon/blob/master/packages/abc/page-header/index.zh-CN.md)、[页头组合源码](https://github.com/ng-alain/delon/blob/master/packages/abc/page-header/page-header.component.ts)、[页头表面和响应式样式](https://github.com/ng-alain/delon/blob/master/packages/abc/page-header/style/index.less)、[查询表格示例](https://github.com/ng-alain/ng-alain/blob/master/src/app/routes/pro/list/table-list/table-list.component.html)、[ST表格](https://github.com/ng-alain/delon/blob/master/packages/abc/st/index.zh-CN.md)、[Schema表单](https://github.com/ng-alain/delon/blob/master/packages/form/docs/schema.zh-CN.md)（2026-11-08查阅）。借鉴区域组合及数据规则与呈现配置分离，不引入Angular、Delon、Ant组件或通用页面引擎。ng-alain允许Page Header包含面包屑；Peanut已由Shell顶栏提供，因此内容页不重复采用该部分。

**继续使用 Vue、Element Plus 和现有 PageContent / PageHeader / PageToolbar。** 不引入 Ant 组件、CSS reset、第二套主题或 Schema 页面引擎。Element Plus 拥有控件交互、尺寸及状态；Web Core 拥有无业务组件和 Shell 主题合同；Code 拥有应用视觉装配与官方业务样板；模块拥有业务内容及局部样式。

应用默认样式位于 `web/src/assets/style/global.less`。只针对明确的 Page 类和已有 `.general-card` 装配样式，不能全局覆盖 `.el-*`；不增加 `!important` 压过应用定制。先核实际 CSS 加载顺序与选择器优先级，新增默认样式不能抢占模块自有布局。应用保留有效品牌与业务布局定制；旧默认中的固定面包屑定位、依赖旧侧栏宽度的偏移、Shell与页面重复padding须按实际冲突原位移除，不能仅因位于应用override中而继续保留。

公共视觉规则集中维护在本准则及既有公共装配入口，不散落复制到业务页面或模块。页面样式只表达业务结构和必要的局部布局，不重复定义公共颜色、标题、间距或卡片规则；新模块优先采用现有 Page 组件，并沿用 Element Plus 语义 tokens。应用品牌与主题 override 保留，由原生三方审阅处理。

应用品牌输入保留在应用自有 `server/config/brand.json`，派生客户端品牌及公共可选默认值统一由现有 `scripts/sync-brand-assets.mjs` 生成。Web构建和类型检查先调用该入口；不在页面Store复制默认值、不要求覆盖应用品牌文件来补新字段。更改生成链后，须在真实生成应用中运行受影响入口，不能只用Code自带品牌配置的构建代替。

## 色彩与主题

| 用途 | 唯一现有入口 | 使用约定 |
| --- | --- | --- |
| 品牌、链接、主操作 | `--el-color-primary`，来自 Shell theme / 应用 themeColor | 沿用应用品牌蓝色或自定义品牌；每个操作区域一个视觉主操作 |
| 成功、警告、危险、信息 | `--el-color-success/warning/danger/info` | 只表达对应含义，状态配文字或图标，不只靠颜色 |
| 页面、面板、浮层 | `--el-bg-color-page`、`--el-bg-color`、`--el-bg-color-overlay` | 中性背景与内容表面区分；普通卡片不用常驻重投影 |
| 标题、正文、说明 | `--el-text-color-primary/regular/secondary` | 标题最强，正文清楚，说明低一级；禁用色不作正文 |
| 分隔、悬停 | `--el-border-color-lighter`、`--el-fill-color-light` | 优先横向分隔与留白，不在每层重复边框 |

不得硬编码白色背景、灰色正文或状态色。深色模式与品牌覆盖复用 `ShellThemeInput.colors/tokens` 及应用原有配置，不另存一份颜色真值。弹窗、下拉等 Teleport 内容须核实际主题作用域，不能只检查 Shell 内的样式。不得为统一视觉重置应用主题、菜单或权限。

应用装配的配色预设集中在现有 `layout/shell-presets.ts`：通用商务蓝、科技协作靛、绿色运营绿。每套同时定义五种语义色及浅／深色中性表面；它们是 Peanut 的场景选择，不宣称是第三方官方色表。页面设置可切换应用允许的预设并调整主色、成功、警告、危险、信息色；中性色由预设或应用主题统一拥有，页面不另设颜色。切换预设清除个人手动颜色，恢复颜色则回到当前预设／应用默认；选择与手动语义色沿现有 localStorage 合同保存，不写入角色或业务设置。

应用 `shellPresentation.theme.colors/tokens` 的固定覆盖优先，设置页对固定语义色只读；`shellUserOptions.colorPresets` 限制可选预设。复制设置输出可用于原有应用设置入口的预设与语义色值。既有应用未显式选择配色预设时保留原主色；默认设置、个人选择、固定应用品牌的职责不能混用。不要把颜色选择当成无障碍资格，状态必须保留文字／图标，定制后应核实际可读性。

## 空间与排版

- 采用 4px 基础间距。桌面分区和面板内边距沿 Shell spacing（紧凑12px、舒适20px、宽松28px），字段/操作组沿 Shell gap（8/12/16px）；390px下分区12–16px、面板14–16px。不叠加 Shell 与整页 padding。采用 `.pa-page-stack` 时由其 gap 统一分区间距，直接子区域不再叠加旧上下 margin；修正限定在实际采用的结构，保留其他业务布局。
- 密度分为紧凑、舒适、宽松，由 Web Core 的同一 Shell theme 计算控件高度与空间变量，顶栏依次48／56／64px；页面消费变量，不自建密度开关。应用可限制允许选项；用户选择不改应用自有主题或权限。查询区独立保持紧凑28px控件，编辑表单继续沿所选密度。
- Element Plus当前编译样式的默认button／select高度不随`--el-component-size`变化；Page页头操作、筛选及分组表单在global.less集中适配默认尺寸，保留显式small／large与link／text行为。依据[官方主题说明](https://element-plus.org/en-US/guide/theming)，只在已采用的Page区域覆盖所需BEM样式，不扩大到全局控件，不以新主题库或逐页补丁处理错位。
- 页面标题24px/32px、600字重，窄屏20px/28px；分组标题16–18px；正文沿用系统字体14px/22px，说明13px/22px。标题与说明组成一个文本组，主操作与该组垂直居中；页面名称、创建和筛选文案使用同一业务称谓。通常保留三个文本层级，数字按列对齐。
- 单一 h1，标题左对齐，主操作在右侧且可换行。独立内容卡片圆角8px、轻边框和统一轻投影，卡片标题区沿主题extra-light填充；嵌套字段或分组不叠投影。浮层保留 Element Plus 默认投影。
- “大气”来自层次、对齐和内容节奏；不靠巨型按钮、装饰图标或虚构数据填满面板。密集页可用既有 compact 密度，画布/编辑器保留实际任务空间。

## 五类页面模式

| 模式 | 必需结构 | 重点 |
| --- | --- | --- |
| 列表 | PageContent → PageHeader标题/主操作 → PageToolbar筛选 → 数据 → 分页 | 默认横向分隔；核心列足够宽，次要长值可省略并提供完整查看；多列表格内部横滚，页面整体不横滚 |
| 详情 | 标题/状态 → 摘要 → 字段分组 → 关联信息/历史 | 业务字段可扫描，不能默认退化为JSON；任务诊断可展示技术明细 |
| 表单 | 标题 → 字段分组 → 校验 → 取消/保存 | 标签、必填、只读和错误清楚；普通编辑宽约640–800px，复杂任务另定；保留 pending 与权限 |
| 工作台 | 标题/刷新 → 关键状态 → 指标/待办 → 入口 | 仅展示真实数据；演示或不可用状态明确标识 |
| 复杂工作区 | 标题/工具 → 画布/编辑区 → 属性/检查 | 保留独立分栏与滚动；窄屏工具换行、侧栏按真实能力切换；不强套列表卡片 |

PageHeader 标题插槽包含 h1；PageToolbar 使用准确的本地化 label。Shell 已提供面包屑，业务页不重复创建；移除旧面包屑时须核对页面仍有可见标题，不能用隐藏路径代替页头。优先用 Element Plus 表格、表单、描述、空态、弹窗及已有领域组件。

面包屑优先使用当前可见菜单的层级与名称；菜单是授权后的导航来源，路由元数据是无菜单页面的回退。面包屑放在桌面顶栏折叠按钮之后，说明当前位置；内容区不再占一行重复路径。多页签是独立的工作区导航，位于顶栏之下、内容标题之前，启用时独占44px表面，保留横向滚动与明确活动态。窄屏隐藏顶栏路径，用页面单h1保留当前任务名称；菜单使用触控抽屉。列表按下文固定区域组织，查询标签与控件行内对齐，查询/重置属于筛选，导出属于结果操作；通用排版集中在 `global.less`，不复制到各模块。分组平铺菜单保留清楚的组标题、条目缩进和选中状态，不把多级条目挤成连续文字。

导航外观只在公共 menu/tab-bar 组件维护：分组标题为12px次级文字、不显示入口图标；根级直达入口与组内入口按层级对齐，宽松模式统一44px行高、组间16px，活动项使用主色浅底。尊重应用菜单数据及顺序，不靠改菜单或权限实现视觉排序。桌面Logo区宽度与侧栏共用同一宽度变量，折叠为图标时两者同时收缩；折叠按钮紧跟Logo区，不放侧栏底部。收缩的分组使用Element Plus原生SubMenu浮层，支持悬停二级菜单，不将分组标题简单隐藏后丢失入口。多页签采用轻量下划线与浅主色活动态，独立导航条不套额外卡片；内容区单h1及主操作对齐，普通宽松页保留28px四周留白。

租户套餐摘要仅在工作台展示，不在 Shell 或普通内容页重复挂载。展示位置和授权执行相互独立，移除摘要不改变许可证、模块权益与接口鉴权。

标签栏分为固定首项、中间工作项滚动区、右侧固定管理按钮。首项沿已有工作台路由且不可关闭；中间独立横滚，左右两端不随滚动消失。右侧使用明确的菜单图标，提供可点击／键盘可达的Element Plus下拉菜单，列出全部已打开页面并标识当前页，桌面可从中切换滚动区外的页面；列表过长在浮层内滚动。管理动作复用各标签右键菜单的同一处理器；首项仍不能关闭。

标签身份参考MIT许可的[Vben tabbar](https://github.com/vbenjs/vue-vben-admin/blob/main/packages/stores/src/modules/tabbar.ts)与[Soybean tab shared](https://github.com/soybeanjs/soybean-admin/blob/main/src/store/modules/tab/shared.ts)（2026-11-08查阅）。两者都将工作项身份与实际导航URL分开；Vben支持`meta.fullPathKey: false`按路径复用，Soybean默认按路径、显式multiTab才按参数多开。Peanut采用Vben这项配置，内置工作台及成员／部门／角色／岗位列表设`fullPathKey: false`，query或hash变化更新原标签的完整URL，不再创建同名标签，也不加序号掩盖问题。自定义业务页不配置时继续按fullPath多开，避免升级时合并应用原有参数工作区；确需多开的业务应提供有意义的页面标题。

滚动及工具入口另核对[Vben固定源码的标签栏](https://github.com/vbenjs/vue-vben-admin/blob/53b47f8ce99ecea11aa04c9c876a2f18c479838f/packages/%40core/ui-kit/layout-ui/src/components/layout-tabbar.vue)和[Soybean固定源码的标签栏](https://github.com/soybeanjs/soybean-admin/blob/d2dfefd757e37d8d6077ea1cea6181fb628d6097/src/layouts/modules/global-tab/index.vue)。借鉴活动项定位、横向滚动和工具入口的职责，不引入其布局包、BetterScroll或整套状态体系。

公开`getTabKey`由Web Core提供，去重、活动态、KeepAlive、关闭与重新加载共用同一键，实际跳转始终使用完整URL。Code现有TabBar负责固定区和滚动，TabItem负责标签呈现，TabBarMenu共用右键／右侧菜单及关闭、刷新处理器，关闭图标通过其slot调用同一动作；页面不复制标签状态或菜单实现。溢出时提供左右滚动按钮，边界禁用，同时保留原生横滚；右侧使用更多操作图标，其下拉仍列出已打开页面。标签图标取同一授权菜单条目，缺省时使用路由元数据；解析由菜单和标签共用的menu-icons维护，不复制映射。不要把图标或标题当作标签身份。不得删除业务参数来消除标签。菜单激活跟随实际路由而非点击事件。切换保留已缓存页面的本地状态；关闭标签清除对应工作项的缓存，不连带清除其他工作项。浏览器刷新、退出及业务编辑器的未保存恢复另按各自合同处理，不能把KeepAlive宣称为持久草稿。

## 整体区域与设置职责

| 区域 | 固定职责 | 禁止混入 |
| --- | --- | --- |
| 品牌区 | 应用Logo与名称；宽度和侧栏同步 | 页面标题、业务操作 |
| 顶部工具区 | Logo后折叠控制、桌面面包屑、搜索与全局设置、用户入口 | 查询条件、页面创建／保存按钮 |
| 侧栏主导航 | 授权后的真实菜单，分组弱化；子项相对根入口轻缩进 | 套餐、重复账户入口、页面说明 |
| 侧栏辅助区 | 固定底部辅助菜单：页面设置、授权信息、最后关于应用；已有贡献槽 | 第二套主导航或无实际目标的帮助链接 |
| 标签区 | 已打开页面与工作项切换、溢出滚动、统一管理菜单 | 筛选值、重复页面标题说明 |
| 页头区 | 独立主题表面、底部分隔；单h1、可选说明／状态、页面主操作 | 再放面包屑、查询控件或嵌套卡片 |
| 内容区 | 下文九类职责按实际页面组合；统一内边距和面板节奏 | 再叠整页padding或额外导航壳 |
| 浮层区 | Element Plus的弹窗／抽屉／下拉；继承应用主题，正文有界滚动 | 另一套颜色、遮挡操作区的整窗滚动 |

根PageContent的第一个PageHeader沿用同一组件，以独立表面衔接标签区，桌面上下20px、窄屏16px，横向和Shell内容边距一致。Shell只定义一次`--pa-page-inset`，页头和内容共用；存在page-tools贡献时保留其位置，不让页头负边距覆盖贡献。该默认仅匹配Shell中的根PageContent，不改嵌套工作区或应用自有容器。页头用24px主标题与16px结果标题形成层级，不能只靠持续放大字号建立层次。

顶栏、根页头、侧栏、独立内容面板的轻阴影分别消费 Web Core `--pa-shell-header-shadow`、`--pa-shell-page-header-shadow`、`--pa-shell-sidebar-shadow`、`--pa-shell-panel-shadow`，由当前主题生成并允许应用 token 覆盖。根页头使用4%主色与当前表面色的极浅过渡，保留文字对比与应用品牌；不另设固定蓝／白。分隔沿 Element Plus extra-light 边框；不在嵌套卡片、每层菜单和每个字段重复投影。层次由背景、独立表面、轻投影和留白共同表达。

页面配置复用既有Block分组组件：外观负责预设／主题／品牌色／密度；布局与导航负责布局／导航方式／既有菜单来源及宽度；区域显示负责顶栏／侧栏／页脚／标签；辅助功能保留已有色弱选项。统一使用应用允许选项与固定品牌优先级，不新增一套配置存储。抽屉桌面400px、窄屏不超过100vw，主体滚动、关闭和复制操作固定在底部；哪些选项持久化仍按原状态合同说明，不宣称所有选项都会跨会话保存。

## 内容区域合同

区域是页面的组合职责，不是新增九个组件。使用已有PageContent、PageHeader、PageToolbar和Element Plus，省略无业务内容的可选区，不为填满模板虚构说明、统计或操作。普通列表采用成员页的现有样板，公共样式集中在global.less：

| 区域 | 内容与交互 | 现有承载及样式 |
| --- | --- | --- |
| 页面容器 | 继承Shell内容宽度；workspace仅限制内容容器最大宽度，标签栏占主区域全宽；统一纵向节奏，页面不再叠padding | PageContent、`pa-page-stack` |
| 页头 | 单h1、可选一句任务说明；创建等页面级主操作靠右，窄屏可另起一行；外边缘与内容面板对齐，保留Shell上方留白，不用负margin扩宽 | PageHeader、`pa-page-header__description`、actions插槽 |
| 查询 | 只含查询条件与查询／重置；默认紧凑、标签与输入同行，Enter提交；普通3项不做无意义折叠 | 独立general-card、PageToolbar、el-form、`pa-page-filters` |
| 结果工具栏 | 数据集名称h2、真实总数／选择数；导出和批量动作在右；批量动作仅在已有选择能力与权限时展示 | `pa-page-results-toolbar--standalone`、`pa-page-results-title` |
| 数据主体 | 表格、树、列表或画布；包含自己的加载／空／失败状态 | el-table、`pa-page-table`；不套第二个横滚容器 |
| 行内操作 | 固定列内低权重编辑等高频动作；危险动作有名称与原确认流程 | el-button link、el-popconfirm；超过合理宽度才使用已有el-dropdown |
| 分页 | 独立于表格横滚；总数、页码和每页数对应同一真实查询 | el-pagination、nav及`pa-page-pagination` |
| 反馈 | 加载、失败、无权限、空数据区分；通知靠近操作，原错误拦截继续工作 | Element Plus既有loading、empty、alert、message；保留有效数据 |
| 编辑／详情 | 表单按业务分组；页级或弹窗底部只有一处提交操作；只读详情使用字段组而非伪表单 | el-form、el-descriptions、el-dialog；下述表单合同 |

筛选与结果各自一张面板，靠区域标题和留白说明关系，不在每个区再嵌卡片。无筛选的列表直接开始结果面板；复杂工作区不强加查询、表格或分页。普通内容标题24px／窄屏20px，结果标题16px，任务说明13px次级色；不能把结果总数或提示提升为第二个页面标题。

### 查询与结果状态

- 桌面常用条件一行，容器变窄时两列／单列；查询表单使用左标签及明确标签宽度，输入与标签保持同行，操作项标签宽度为0。查询区统一28px高度，字段宽度不挤到难以阅读，查询／重置相邻。超过首屏合理容量的高级条件才提供显式展开，不能默认藏住生效条件；编辑表单的分组与置顶标签不因此改变。
- 查询或重置回第一页；改每页数量按业务已有合同重取数据。输入中的条件、已生效条件、URL持久化分别由原页面状态拥有；不为样式修改暗改它们的含义。新页应明确定义何时提交，不能一半即时查询、一半按钮查询。
- 总数来自当前响应，不用当前页length冒充总数；权限不足的字段、按钮沿原判断。选择与批量操作若确有需求，必须说明当前页／跨页范围以及查询变化时如何处理，不能悄悄把当前页选择变成全量操作。
- 加载使用el-table所在区域的v-loading；重复请求、错误、权限及并发处理继续遵守原API合同，不能因UI统一改业务接口或数据结构。

### 表格、固定区域与分页

- 列宽按内容分配：标识／状态／数字显式width，长名称用min-width或有依据的width与show-overflow-tooltip；状态配文字。不要把所有列平均分，也不要用整表min-width把右侧操作推到视口之外。
- 表格宽度为容器100%。横滚只由Element Plus原生scrollbar承担；页头、查询、结果工具栏和分页不随表格横滚。左右固定列必须位于对应连续边界并给宽度。
- 宽屏可固定左侧必要标识和右侧操作；390px默认释放左侧固定，右侧操作缩到实际动作所需宽度，确保中间仍能读取与横滚。没有必要固定的表格不强制固定列；不新增另一套表格组件。
- 长结果用Element Plus height／max-height及内部纵滚保留表头。样板max-height为`max(320px, calc(100dvh - 440px))`；这是本样板的可视窗口预算，不是所有页面硬编码标准。其他查询区高度不同须按实际布局选择；不以CSS sticky叠加原生固定表头。
- 分页在表格下方独立横线分区，桌面右对齐；窄屏保留总数、前后页和有限页码，省略拥挤的每页数选择。仍能换行，不要求用户横滚才能翻页。当前数据不足时明确不宣称已验多页／长表纵滚。
- 行操作使用真实权限，编辑与删除保持原确认／loading。固定列与表头继承主题背景和Element Plus阴影，不用硬编码白底掩盖重叠；需实际横滚核对左右固定和操作点击。

### 表单、动态字段与详情

- 普通表单先分基本信息、业务关系、状态／安全等有含义的fieldset／legend，避免无意义编号。已有`pa-page-form-section`／`pa-page-form-grid`提供两列／窄屏单列；长输入和角色等多选占整行，提示使用`pa-page-form-help`。
- 标签置顶、必填与校验由el-form拥有；占位符不能替代标签。只读／禁用原因清楚，提交保持原loading与权限。错误保留用户输入，字段显隐不等于后端授权。
- 编辑弹窗宽度不超过视口减24px，长表单只滚动body，标题和取消／保存保持可见；使用`pa-page-form-dialog`。复杂编辑器按自身工作区合同，不套表单滚动。独立长表单只设一处操作区；需要吸底时占位并避免遮挡最后字段。
- ng-alain动态表单的可借鉴点是分离：数据定义负责类型、required、范围与后端校验；UI定义负责label、widget、布局、help、顺序；选项／依赖负责加载、联动、错误。不能把UI schema当作任意代码执行、权限或数据库定义。
- Peanut现有配置驱动表单包括网站配置的本地fields清单，以及peanut-settings运行时对布尔、枚举、数字、文本、密钥的受限schema编辑；复合schema仍按其原合同只读。模块设置继续采用它，不把单一业务设置runtime冒充通用表单引擎。
- 当前未提供通用拖拽表单设计器，不能宣称采用Delon即已获得该能力。普通业务继续Element Plus显式表单；只有实际消费者确需动态字段时，先在现有业务字段清单中定义typed配置。显隐后是否保留值、异步选项依赖、只读／密钥不回显、清空／未提交的语义必须与原API一致，不为设计参考新增JSONSchema引擎、eval、依赖或服务端结构。
- 详情按el-descriptions或实际字段分组呈现，摘要、状态、关联数据和历史各有含义；页头动作与内容动作分开。复杂工作区保留画布／正文／属性区域和自身滚动，不强制套上述列表布局。

## 侧栏区域与辅助入口

品牌／折叠控制在现有顶栏，主导航独立滚动，底部辅助区域不随菜单长度被挤出；复用现有`sidebar-footer`贡献槽和Footer，不创建另一套导航。分组标题从侧栏24px开始，组内活动背景从16px开始、内部16px；组内文字相对根入口轻缩进8px，避免侧栏空白过宽。根入口保持原层级，折叠后恢复64px图标栏，不把展开缩进带入浮层。

官方[Vben侧栏](https://github.com/vbenjs/vue-vben-admin/blob/main/packages/%40core/ui-kit/layout-ui/src/components/layout-sidebar.vue)将菜单、底部折叠和品牌分区，[Ant Design Pro布局](https://github.com/ant-design/ant-design-pro/blob/master/src/app.tsx)把账号放顶栏、版权放Footer。这些不是统一行业强制标准。Peanut已在Logo旁提供折叠，顶栏已有用户／租户入口，因此侧栏不重复头像、退出、套餐或组织切换。侧栏顶部用户区只适用于它确实承担独立工作空间切换的应用定制。

默认底部是点击展开的低权重辅助菜单，复用 Footer 与 Element Plus Dropdown：文字在左、水平三点在最右，折叠时图标居中；桌面浮层在侧栏右侧、窄屏向上展开，点击状态不因鼠标跨越浮层间距而关闭。页面设置打开现有设置面板；授权信息仅点击后打开现有只读许可证组件；关于应用始终排最后，显示应用自身品牌、真实客户端package版本与版权，开发构建明确DEV。普通页面不常驻套餐摘要，package版本不代表模板已经正式发布。折叠时图标带可访问名称，手机选择后关闭导航抽屉再呈现目标浮层。

后台网站设置沿已有 tenant website JSON 配置 `sidebar_footer_enabled/title/settings/license/about`，不新增表或菜单权限。总开关、标题及三个入口开关通过既有Host绑定品牌配置下发，空标题采用本地化默认；未配置的应用沿生成品牌默认。后台保存仍需原设置管理权限，显示开关不授予访问权限，许可证仍由原租户接口授权。不得让普通成员为取得外观配置而读取受限网站管理接口。帮助／反馈由应用通过既有贡献槽接入真实入口；原footer开关只控制内置区，不能隐藏模块贡献。顶部导航模式保留原页面版权Footer。

## 状态与真实验收

区分加载、空结果、失败和无权限。失败不静默清空已有数据，不制造假成功。保留键盘焦点、标签、禁用说明、错误提示和后端授权；隐藏按钮不能代替接口鉴权。窄屏弹窗宽度不超过 `calc(100vw - 24px)`。

受影响页面在1440px和390px真实浏览器截图并实际操作搜索、表单、保存、详情或画布工具；检查 Console、Network、整体溢出、内部横滚，以及浅色/深色与品牌覆盖。明确来源、操作、PASS/FAIL/NOT_RUN和限制。类型、构建、HTTP200不能代替视觉和业务验收。

## Agent 与升级

1. 先读本准则、直接消费者、组件实现、主题来源与升级归属，区分已有未用、环境问题和真实缺口。
2. 按实际页面模式定义最小写集，复用现有组件；只修已观察问题，不创建竞争基础设施。
3. 保留接口、结构、租户隔离、菜单及权限语义；菜单调整同时遵守[菜单与导航](menu-navigation.md)的安全域与实例引用边界，图表退出先按[图表能力](charts.md#独立交付与退出)核实际消费者。模块 UI 改动计入模块内容身份，核对声明的完整前端根，包括可选运行目录与各客户端；内容变化须同步模块声明、Composer 和所属前端包版本，再由原生工具生成锁。已有内容一致的版本直接复用，不为标记同步空升版本，也不保留同版本异内容。同步公开入口与生成 AGENTS，AGENTS 只指向本文。
4. 用原生生成器更新模板 inventory。正式模板形成后，各应用分别通过 baseline 三方计划采用；不能复制覆盖目录、重设baseline或把整个客户端标为自有来跳过保护。
5. 应用自有 AGENTS、页面、主题、菜单、角色、权限、业务数据和秘密继续受原生归属保护。同文件双方修改可能产生需要审阅的真实冲突，不能承诺任意二开零冲突，也不能靠覆盖消除冲突。

开发提交与本文不构成正式版本、业务应用升级或生产部署。
