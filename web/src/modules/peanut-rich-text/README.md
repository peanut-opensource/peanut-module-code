# peanut.rich-text

RichText 仅作为 `peanut.content-tools` 的正式 provider，不维护文档身份或独立管理页。PHP 组合根从已编译、已选 Module provider 收集 typed contribution，统一校验后安装完整注册器；前端使用同一 locked contribution。工具 ID `rich-text.tiptap`，查看/编辑选择由 owner 的可信能力合同提供，不按注册顺序选择。

公开 `./document`、`./editor`、`./viewer` 和 `./file-picker` 导出保持。组件使用实际 Tiptap 编辑/只读查看；单人编辑输出经 `peanut.richtext/1` 校验的 JSON。`modelValue` 采用不可变整文档替换：调用方不得原位修改收到的文档对象，任何内容变更都应提供新的根对象；外部新对象仍完整经过结构和媒体校验。ContentSurface 编辑器仅在点击请求保存时发事件，持久化回执仍由 owner 提供。旧 RichText CRUD/HMAC 管理页、路由和 API 不再是活跃前端入口；Document owner 负责文档身份、归属、版本和保存。既有 RichText 表、修订及引用保持原样，未知 owner 的历史记录不重新归属或开放写入。

媒体节点仅保存 `fileKey/kind/variantKey/alt/title`。公开 RichTextFilePicker 直接复用 FileAssetSelector/createFileAssetRuntime，加载状态、分页数据与请求取消仍属于 File；owner 将其选择事件接到 `pickMedia` 回调，`resolveMedia` 返回新授权的元数据、能力和 File callbacks，再委托 ContentViewer 获取受控字节。缺少回调时显示占位，不请求任意 URL，不保存交付地址。未知 legacy ref 或非法结构显示错误且保留原内容，迁移报告与认领由 F1 处理。

身份/权限分区 `contextKey` 变化、卸载或权限模式变化会销毁 Tiptap、媒体 NodeView 和解析/选择请求。独立调用 Editor 的 owner 必须传入当前身份分区，授权仍由 owner/File 每次复核。

当前编辑器接受owner提供的`collaborationBinding`：协议必须为`yjs/1`且document是真实Y.Doc；等待ready/frozen后绑定Tiptap Collaboration，按writable控制编辑，不再用JSON重复初始化或双写协作内容。仅传旧collaboration配置或已有序列化状态而没有合法binding时仍返回unavailable，不连接旧URL或伪造ticket。身份、绑定代次和销毁会释放旧会话。Hocuspocus provider源码入口已存在，实际双人同步、撤权、保存及重开仍需对应集成证据；sync不是持久ACK。

## 正式复用

业务文档优先使用 Document 的 `DocumentEditor/DocumentViewer`，其真实消费者 `peanut-document/components/DocumentSurface.vue` 经 ContentEditor/ContentViewer 调度本模块的 `rich-text.tiptap` contribution，再使用 `RichTextSurface → RichTextEditor`。不为富文本另建身份、保存 API 或菜单。独立表单可使用公开编辑器：

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { RichTextEditor } from '@peanut-admin/peanut-rich-text/editor';
import { emptyDocument } from '@peanut-admin/peanut-rich-text/document';

const value = ref(emptyDocument());
</script>

<template>
  <RichTextEditor v-model="value" context-key="account:member:tenant:draft" />
</template>
```

`modelValue` 输入、`update:modelValue` 输出均为 `RichTextDocumentValue`，不是 HTML；单人编辑经校验后发出整文档及 `dirty-change`。`ready` 表示编辑器就绪，`error(code)` 表示投影、链接、媒体或协同输入错误，不表示已保存。示例 contextKey 应由宿主替换为真实身份/租户/对象分区；独立编辑器不自行授权或持久化，业务保存仍需 owner 校验对象权限及租户。`readOnly` 或 binding 的 `writable=false` 禁止操作；协同输入、释放与保存确认沿上文合同。

工具按钮支持鼠标和键盘激活，以中文标明功能并反馈当前格式，撤销/重做根据实际历史禁用。链接输入支持 Enter 设置，选中链接后可移除；非法链接显示提示且不改变正文。媒体只经已授权的 callbacks 选择，未提供时不出现文件按钮。只读模式不展示格式按钮。
