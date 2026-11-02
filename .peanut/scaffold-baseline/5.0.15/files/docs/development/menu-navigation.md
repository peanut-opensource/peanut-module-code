# 默认导航分类与菜单定制

## 宿主预设、主题与替换

`web/src/peanut.overrides.ts` 是应用前端覆盖入口。`shellPresentation` 只定义外观，默认使用 `shellPresets.admin`；应用可选择同文件导入的 `shellPresets.workspace` 并组合自有值：

```ts
export const shellPresentation: ShellPresentation = {
  ...shellPresets.workspace,
  navigationMode: 'grouped',
  brand: { name: '我的工作区', logo: '/brand/logo.svg' },
  theme: {
    density: 'compact',
    colors: { primary: '#24785d', background: '#f5f7f6', text: '#1f2d29' },
    tokens: { '--pa-shell-sidebar-width': '246px', '--pa-shell-header-height': '58px' },
  },
};
```

`layout` 的 `admin/workspace`、`navigationMode` 的 `tree/grouped/top` 和 `theme` 相互独立。tree 保留分层折叠菜单；grouped 将每层分类标题和后代平铺显示，不按组折叠。两种侧栏模式都可用原收起/展开按钮切换整个侧栏宽度；grouped 收起时隐藏分类标题、保留平铺入口，展开恢复标题和文字，导航方式仍为 grouped。top 沿原页头菜单呈现。导航只消费 `AppSettings.shellNavigationMode`，原 `topMenu` 独立开关已经移除，避免双轨偏好冲突。`themeColor`、`menuWidth`、`tabBar`、`navbar/menu/footer` 保留独立语义。主题变量只应用于本宿主，不保存身份或发起业务请求。默认品牌沿 Brand store 及租户信息，应用指定 brand 时只替换展示。

普通用户从原 Navbar 的“页面配置”打开 GlobalSetting，选择已登记的管理台/工作台预设，再调整应用允许的布局、导航、密度和主题模式。选择写入原 AppSettings：`shellPreset/shellLayout/shellNavigationMode/shellDensity`，主题仍使用 `theme` 与已有 `element-theme` 偏好键；其余展示项沿原临时设置/复制配置方式保存，没有新增服务端设置、存储账本或业务请求。更换预设只修改同一 Shell 的展示 props，不切换业务组件身份或重建 runtime。

应用在同一 `peanut.overrides.ts` 中导出精确允许项（类型 `ShellUserOptions` 来自 `layout/shell-presets.ts`）。零项表示禁止用户调整并保留应用默认，单项表示固定值且不显示选择器，多项才显示选项。旧偏好超出允许值时不生效。媒体类应用可固定工作台和平铺导航，同时允许主题和密度：

```ts
export const shellUserOptions: ShellUserOptions = {
  presets: ['workspace'],
  layouts: ['workspace'],
  navigationModes: ['grouped'],
  densities: ['comfortable', 'compact'],
  modes: ['light', 'dark'],
};
```

`shellPresentation` 定义应用默认品牌、颜色、token 和初始布局等；选择预设提供其内置展示默认，独立参数只在允许集合内覆盖。应用的明确单项限制始终优先于用户偏好。固定源码颜色/token 不由这个普通用户界面编辑，普通用户不能提交组件名、任意 CSS 或覆盖类名。

主题应用于 `.tenant-shell` 根，侧栏使用 `--pa-shell-surface-color`，菜单继承 Element Plus 变量。仅需深色侧栏、浅色内容时，应用自有样式可在 `.tenant-shell .tenant-sidebar` 局部设置背景及 `--el-menu-bg-color/text-color/active-color/hover-bg-color`；不要为局部外观把整个 theme.mode 改为 dark。样式不替换菜单数据或账号动作。

宿主使用原 `useMenuTree` 已授权菜单、原图标映射、`activeMenu` 与 route name 跳转；分类/预设不新增、删除或重新授予权限。外链继续交给浏览器并保留 `_self/_blank` 目标；内部跳转不另建 URL 映射。原账号、租户切换、退出、菜单搜索和 action binding 的消费者不变。面包屑来自已匹配路由，标签栏复用既有 TabBar 及缓存合同。

要替换整个外壳，在现有 `PEANUT_ADMIN_PRESETS` 或默认导出的应用 overrides 中登记 Core 的 `WORKSPACE_SHELL_OVERRIDE_KEY`（`peanut.shell.service.workspace-component`，kind=`service`，contractVersion=`1.0.0`），值为 `(audience) => Component`。`web/src/core/runtime.ts` 将现有 permission slot 和 `ADMIN_SHELL_OVERRIDE_SLOTS` 交给同一个 registry，按 default → preset → application 解析，不使用另一套扫描器。组件接收同一 theme/layout/navigation-mode 属性及 header/sidebar/breadcrumb/tabs/default slots，必须保留宿主传来的账号动作、授权菜单和页面内容；未知 key、合同或错误组件仍报错。

Web 的锁定模块贡献入口支持既有 Core `AdminModuleShellSlotContribution` 的 `shellSlots`：`header-context`、`header-actions`、`sidebar-footer`、`page-tools`。在模块自己的 contribution 对象中声明 `name`、懒加载 `component` 及可选 `access: { moduleKey, permissionKeys }`。宿主先检查当前启用模块，再按所属模块和已有权限 evaluator 过滤，未获准的组件不加载；退出/权限变化后随组件卸载，模块仍负责自己的订阅和请求释放。不要把贡献位的可见性当作后端授权。

Platform 的真实应用入口是 `platform/src/App.vue`，自有覆盖入口为 `platform/src/peanut.overrides.ts`。`platformPresets` 定义管理台/工作台展示值，`platformUserOptions` 用上述五组精确允许项约束页头“展示设置”，每组首项为初始值；`platformPresentation` 是本页面的原生 reactive 展示状态，选择只作用于当前页面，不创建用户配置数据库或新的持久记录。`presets/overrides` 继续使用同一 Core shell slot。平台账号、导航可见性及 `selectView`/刷新/退出仍由原平台宿主处理。OpsConsolePage 保留原 `refresh/upgrade` slots、runtime 和 providers，不引入租户 Web runtime。自定义平台外壳须透传宿主内容，不能据布局选择改变平台身份或操作权限。

这些允许项、默认值及组件覆盖属于应用源码定制，升级时由应用按原 ownership/baseline 审阅合并。已有 app-owned `peanut.overrides.ts` 采用这批宿主源码时须补齐 `shellUserOptions` 导出，不能只复制宿主调用而遗漏应用允许项。修改不等于已发布 Core 包或已完成发行资格。

Web 的静态模块和插件路由在 `router/routes/index.ts` 装配时递归校验 meta，非法源码配置在注册前报错；插件 meta 不先做布尔强转来掩盖非法输入。服务端菜单在 `server-menu.ts` 映射到已知静态路由后检查投影 meta，丢弃非法节点。导航和菜单匹配直接使用已采用的记录，继续按当前会话执行角色、权限包和租户模块门槛，不逐次重验不变的 meta 结构。

租户后台保留工作台独立入口，其余默认入口按真实用途进入六类：组织与权限、会员与财务、内容与资源、应用与集成、系统设置、运维与审计。平台后台保留总览，按租户运营、组织与权限、运维与审计组织原有操作入口。分类只是导航节点，不产生业务权限；页面的 URL、route name、组件注册和权限码保持原身份。

模块继续通过原有 `module.json` 引用的 `resources/menus.json` 声明菜单。租户 page 必须明确写 `parent_key`，选择产品装配提供的 `core.organization`、`core.business`、`core.content`、`core.applications`、`core.system`、`core.operations`，或本模块声明的同 scope 分类。确有高频独立入口时显式写 `parent_key: null`；遗漏字段会被同步器拒绝。禁止另建分类 manifest。实例展示定制使用菜单管理入口，不直接改库。通常采用分类和页面两层；分类必须有可见页面才显示。

首次安装在正式应用迁移完成后，由 Identity 的 `MenuCatalogSynchronizer` 同步分类与模块贡献。正式模块目录同步复用运行入口已编译、准入的 registry，不扫描未登记源码目录来加入贡献。旧实例通过同一应用迁移账本执行 `20261004-group-default-navigation.sql` 和 `20261004-menu-stable-identities.sql`，再由正常升级的 `plugin:reconcile --release-locked` 同步。无需重新初始化权限、删除菜单或更改产品版本。迁移保留旧菜单 ID、目录 key、角色权限绑定和原页面地址；重复迁移、同步不会重复创建分类。

菜单表中的 `upstream_defaults_json` 保存上游默认比较值，`menu_conflict_json` 保存冲突原因。历史记录使用提交中固定的旧默认快照比较，不把现场值当作默认。只有全部受管字段仍等于默认的节点才自动采用新默认；名称、父级、排序、图标、地址、隐藏、缓存、禁用或权限字段被定制时保留整个节点。旧目录被定制时，保留原子页位置。无法识别的记录保留现状；目录同步记录 `MENU_DEFAULT_CONFLICT`，数据库冲突列可供维护者检查。用户新增菜单不因目录发现消失而被删除或退役。

`is_show` 控制导航显示；菜单禁用及定制限制影响菜单投影。菜单管理的“所需权限包”从已登记业务权限包中选择，单个菜单或按钮的 `perms` 只引用一个权限包 key，可跨菜单、按钮及客户端复用，不在菜单中创建 API 或权限包。目录包含系统、模块及平台创建的实例自建包；定义维护与 API 关联仍使用 Identity 的平台入口。受管菜单保留模块声明的默认权限包，也允许显式选择同域启用的已有权限包作为实例引用，来源可为 system、module 或 custom；有实例引用时，以它替代默认菜单门槛，不把两个包同时要求，清空则恢复源码默认。API 源码前置权限仍须全部满足，模块部署、租户开通和 Scope 也继续独立校验。菜单编辑本身不会赋予或撤销角色权限。前端只映射已注册的静态页面，绝不执行数据库中的任意组件路径。尚未交付静态页面的目录声明不会生成可点击入口。

固定业务入口也可通过自建 `C` 类型菜单引用已登记的静态页面，并选择所需权限包。自建 `A` 类型按钮挂在页面菜单下，按钮 `paths` 保存页面已有的动作标识，父级 `C.paths` 确定该动作所在页面，`perms` 保存所需权限包。动作标识必须与页面代码调用一致，不是 API 路径。`/adminapi/user/info` 的 `action_bindings` 返回 `{page_path, action_key, permission_key}` 列表，包括已配置但当前用户未获授权的项；前端在当前路由页面选择配置，再用实际授予的权限包判断。未配置的动作沿页面已有权限判断，配置不会为用户补发源码默认动作权限，其他页面的同名动作也不会被一起替换。

上述完整菜单编辑能力仍属于 ST 管理入口。MT 使用平台权限面板配置已有租户页面和按钮的引用：读取 `GET /platformapi/permission/menus`，保存 `PUT /platformapi/permission/menus/binding`。平台可为菜单选择同域有效包或清空恢复已知默认；按钮动作标识为空时可首次填写，已有标识不可改，同页同动作不能引用不同包。此入口不新增或删除菜单，不编辑 `C` 页面路径、父子关系、组件或模块身份，普通租户不能修改这些实例全局引用。保存必须携带读取所得的不透明 `revision`；精确字段及错误见 [API 合同](../public/api-and-sdk.md#实例菜单与按钮引用)。

正式停用/卸载和重新安装沿原模块生命周期执行；其受管状态同时更新默认比较值，以免重新安装被误判为人工定制。发布与实际下游升级仍由正常产品流程完成，源码合入不代表实例已升级。

直接验证入口：`php server/tests/Productization/MenuGroupingTest.php`（必须提供已登记、已租约授权的三个空 MySQL 8.4 schema）；`node --test scripts/tests/menu-grouping-navigation-test.mjs`。数据库入口使用现有 `RegisteredMysqlTestResource`，拒绝未知、非空或其他任务的资源，仅清理本次拥有的合成数据。

菜单管理与父子关系使用 `menu_key` / `parent_key` 字符串标识；角色的独立权限包授权使用 `permission_keys`，不从菜单树反推授权。菜单标识来自模块声明（例如 `peanut.file.library`）、固定系统种子声明（例如 `system.menu.lists`）或自建菜单创建时生成的 `custom.<随机标识>`；不是路径或数字哈希。数据库仍有自增 `id`，只用于存储关联及记录定位，不作为跨目录、模块或前端的菜单身份。两张既有表的数字 ID 可以相同；合并及树节点始终按标识区分。权限包与 API 的实例关联及角色授权边界见[身份与授权](../architecture/identity-organization-access.md#3-部门岗位角色和数据范围)。

`pa_system_menu` 的编号 1–999,999,999 预留给上游系统种子，新自建记录从 1,000,000,000 开始。已有记录不重编号，即使历史自建记录处于预留段也保留。新增系统种子应显式指定预留段编号，并给出永久、唯一的语义标识；编号不能用来判断模块、授权或合并菜单。身份迁移按旧默认中的路径/权限语义识别历史种子；无法唯一识别的记录保留内容并获得独立自建标识。

管理列表同时显示系统、自建及模块贡献菜单的稳定标识和 `module_key`。模块菜单允许实例调整名称、图标、父分类、排序、显示、缓存、禁用状态及权限包实例引用；源码默认权限、路由、组件、所属模块及生命周期身份继续由模块声明控制。原生模块菜单在同一 `menu_definition` 记录的 `instance_permission_id` 保存实例引用，保留原 `required_permission_id`、`menu_key` 和模块归属；正常模块同步不修改该实例字段，它不参与源码默认字段比较。原生模块菜单的父分类只能选择原生分类，历史展示记录保留已有定制。模块菜单不能由普通删除按钮移除，停用/卸载仍由模块生命周期执行。变更仅写入该菜单现有的存储 owner，不创建额外镜像记录；升级同步按旧默认比较保留展示定制，并保留独立的实例权限引用。
