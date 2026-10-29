# peanut.rich-text

RichText 是 `peanut.content-tools` 的正式 provider。PHP 组合根从已编译、已选 Module provider 收集 typed contribution，统一校验后安装完整注册器；前端使用同一 locked contribution。工具 ID `rich-text.tiptap`，查看/编辑选择由 owner 的可信能力合同提供，不按注册顺序选择。

公开 `./document`、`./editor` 导出保持，新增 `./viewer` 和 `./file-picker`。组件使用实际 Tiptap 编辑/只读查看；单人编辑输出经 `peanut.richtext/1` 校验的 JSON。PHP 旧保存入口也委托公开 `RichTextProjection`。查看不会发送更新或保存请求；ContentSurface 编辑器仅在点击请求保存时发事件，持久化回执仍由 owner 提供。

媒体节点仅保存 `fileKey/kind/variantKey/alt/title`。公开 RichTextFilePicker 直接复用 FileAssetSelector/createFileAssetRuntime，加载状态、分页数据与请求取消仍属于 File；owner 将其选择事件接到 `pickMedia` 回调，`resolveMedia` 返回新授权的元数据、能力和 File callbacks，再委托 ContentViewer 获取受控字节。缺少回调时显示占位，不请求任意 URL，不保存交付地址。未知 legacy ref 或非法结构显示错误且保留原内容，迁移报告与认领由 F1 处理。

身份/权限分区 `contextKey` 变化、卸载或权限模式变化会销毁 Tiptap、媒体 NodeView 和解析/选择请求。独立调用 Editor 的 owner 必须传入当前身份分区，授权仍由 owner/File 每次复核。

当前编辑器接受owner提供的`collaborationBinding`：协议必须为`yjs/1`且document是真实Y.Doc；等待ready/frozen后绑定Tiptap Collaboration，按writable控制编辑，不再用JSON重复初始化或双写协作内容。仅传旧collaboration配置或已有序列化状态而没有合法binding时仍返回unavailable，不连接旧URL或伪造ticket。身份、绑定代次和销毁会释放旧会话。Hocuspocus provider源码入口已存在，实际双人同步、撤权、保存及重开仍需对应集成证据；sync不是持久ACK。
