<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.file']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.file') }}</template>
      <div class="asset-selector-link">
        <el-button
          v-permission="['peanut.file.visibility.change']"
          @click="openVisibility(false)"
          >可见性任务</el-button
        >
        <el-button @click="$router.push({ name: 'peanut.file.assets' })">
          {{ $t('systemFile.op.assetSelector') }}
        </el-button>
      </div>
      <el-tabs
        v-model="activeType"
        type="card"
        @update:model-value="onTypeChange"
      >
        <el-tab-pane :name="10" :label="$t('systemFile.tab.image')" />
        <el-tab-pane :name="20" :label="$t('systemFile.tab.video')" />
        <el-tab-pane :name="30" :label="$t('systemFile.tab.file')" />
      </el-tabs>

      <el-row :gutter="16" class="body">
        <!-- 左侧分类 -->
        <el-col :span="4">
          <div class="cate-panel">
            <div class="cate-head">
              <span>{{ $t('systemFile.cate.title') }}</span>
              <el-button
                v-permission="['peanut.file.category.add']"
                link
                size="small"
                :aria-label="$t('systemFile.cate.addTitle')"
                :title="$t('systemFile.cate.addTitle')"
                @click="handleCateAdd()"
              >
                <template #icon><Plus /></template>
              </el-button>
            </div>
            <ul class="cate-list">
              <li :class="{ active: currentCid === '' }">
                <button class="cate-name" type="button" @click="selectCate('')">
                  {{ $t('systemFile.cate.all') }}
                </button>
              </li>
              <li
                v-for="c in flatCateList"
                :key="c.id"
                :class="{ active: currentCid === c.id }"
              >
                <button
                  class="cate-name"
                  type="button"
                  @click="selectCate(c.id)"
                >
                  {{ `${'  '.repeat(c.depth)}${c.name}` }}
                </button>
                <span class="cate-ops" @click.stop>
                  <el-button
                    v-permission="['peanut.file.category.add']"
                    link
                    size="small"
                    :aria-label="
                      $t('systemFile.cate.addChild', { name: c.name })
                    "
                    :title="$t('systemFile.cate.addChild', { name: c.name })"
                    @click="handleCateAdd(c.id)"
                  >
                    <template #icon><Plus /></template>
                  </el-button>
                  <el-button
                    v-permission="['peanut.file.category.edit']"
                    link
                    size="small"
                    :aria-label="
                      $t('systemFile.cate.editAction', { name: c.name })
                    "
                    :title="$t('systemFile.cate.editAction', { name: c.name })"
                    @click="handleCateEdit(c)"
                  >
                    <template #icon><icon-edit /></template>
                  </el-button>
                  <el-popconfirm
                    :title="
                      $t('systemFile.cate.delete.confirm', { name: c.name })
                    "
                    @confirm="handleCateDelete(c)"
                  >
                    <template #reference>
                      <el-button
                        v-permission="['peanut.file.category.delete']"
                        link
                        size="small"
                        :aria-label="
                          $t('systemFile.cate.deleteAction', { name: c.name })
                        "
                        :title="
                          $t('systemFile.cate.deleteAction', { name: c.name })
                        "
                      >
                        <template #icon><Delete /></template>
                      </el-button>
                    </template>
                  </el-popconfirm>
                </span>
              </li>
            </ul>
          </div>
        </el-col>
        <!-- 右侧文件区 -->
        <el-col :span="20">
          <div class="toolbar">
            <el-space>
              <el-upload
                v-permission="[uploadPermission]"
                :http-request="
                  (options: UploadRequestOptions) =>
                    uploadFile(activeType, options)
                "
                :data="{ cid: String(currentCid === '' ? 0 : currentCid) }"
                :show-file-list="false"
                :accept="acceptMap[activeType]"
                @success="onUploadSuccess"
              >
                <template #trigger>
                  <el-button type="primary">
                    <template #icon><icon-upload /></template>
                    {{ $t('systemFile.op.upload') }}
                  </el-button>
                </template>
              </el-upload>
              <el-input
                v-model="searchName"
                clearable
                style="width: 200px"
                :placeholder="$t('systemFile.search.placeholder')"
                @keyup.enter="() => fetchFiles(1)"
                @clear="() => fetchFiles(1)"
              />
              <el-select
                v-model="searchSource"
                clearable
                style="width: 140px"
                :placeholder="$t('systemFile.search.source')"
                @change="() => fetchFiles(1)"
                @clear="() => fetchFiles(1)"
              >
                <el-option :value="0" :label="$t('systemFile.source.admin')" />
                <el-option :value="1" :label="$t('systemFile.source.user')" />
              </el-select>
              <el-button @click="() => fetchFiles(1)">
                <template #icon><Search /></template>
              </el-button>
            </el-space>
            <el-space v-if="checkedIds.length">
              <span class="selected-tip">
                {{ $t('systemFile.op.selected', { n: checkedIds.length }) }}
              </span>
              <el-button
                v-permission="['peanut.file.move']"
                size="small"
                @click="openMove"
              >
                {{ $t('systemFile.op.move') }}
              </el-button>
              <el-button
                v-permission="['peanut.file.visibility.change']"
                size="small"
                @click="openVisibility(true)"
                >设为私有</el-button
              >
              <el-popconfirm
                :title="$t('systemFile.op.batchDelete.confirm')"
                @confirm="handleBatchDelete"
              >
                <template #reference
                  ><el-button
                    v-permission="['peanut.file.delete']"
                    size="small"
                    type="danger"
                    >{{ $t('systemFile.op.delete') }}</el-button
                  ></template
                >
              </el-popconfirm>
            </el-space>
          </div>

          <div v-loading="loading" style="width: 100%">
            <div v-if="renderData.length" class="grid">
              <div
                v-for="item in renderData"
                :key="item.id"
                class="grid-item"
                :class="{ checked: checkedIds.includes(item.id) }"
              >
                <div class="thumb" @click="toggleCheck(item.id)">
                  <el-checkbox
                    class="thumb-check"
                    :model-value="checkedIds.includes(item.id)"
                    @click.stop
                    @change="() => toggleCheck(item.id)"
                  />
                  <FileContentViewer
                    :item="item"
                    :context-key="contextKey"
                    :registry="contentRegistry"
                    compact
                  />
                </div>
                <div class="name" :title="item.name">{{ item.name }}</div>
                <div class="ops">
                  <el-button link size="small" @click="previewFile(item)">
                    {{ $t('systemFile.op.preview') }}
                  </el-button>
                  <el-button
                    v-permission="['peanut.file.rename']"
                    link
                    size="small"
                    @click="handleRename(item)"
                  >
                    {{ $t('systemFile.op.rename') }}
                  </el-button>
                  <el-popconfirm
                    :title="$t('systemFile.op.delete.confirm')"
                    @confirm="handleDelete(item)"
                  >
                    <template #reference
                      ><el-button
                        v-permission="['peanut.file.delete']"
                        link
                        size="small"
                        type="danger"
                        >{{ $t('systemFile.op.delete') }}</el-button
                      ></template
                    >
                  </el-popconfirm>
                </div>
              </div>
            </div>
            <el-empty v-else />
          </div>

          <div class="pager">
            <el-pagination
              v-model:current-page="pagination.current"
              v-model:page-size="pagination.pageSize"
              :total="pagination.total"
              layout="prev, pager, next"
              @current-change="fetchFiles"
            />
          </div>
        </el-col>
      </el-row>
    </el-card>
    <el-dialog v-model="previewVisible" title="文件预览" destroy-on-close>
      <FileContentViewer
        v-if="previewItem"
        :key="contextKey"
        :item="previewItem"
        :context-key="contextKey"
        :registry="contentRegistry"
      />
    </el-dialog>
    <!-- 分类新增/编辑 -->
    <el-dialog
      v-model="cateModalVisible"
      :title="
        cateIsEdit
          ? $t('systemFile.cate.editTitle')
          : $t('systemFile.cate.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form
        ref="cateFormRef"
        :model="cateForm"
        :rules="cateRules"
        label-position="top"
      >
        <el-form-item prop="name" :label="$t('systemFile.cate.field.name')">
          <el-input
            v-model="cateForm.name"
            maxlength="20"
            show-word-limit
            :placeholder="$t('systemFile.cate.field.name.placeholder')"
          />
        </el-form-item>
      </el-form>
      <template #footer
        ><el-button @click="cateModalVisible = false">取消</el-button
        ><el-button type="primary" :loading="cateSubmitting" @click="submitCate"
          >保存</el-button
        ></template
      >
    </el-dialog>

    <!-- 文件重命名 -->
    <el-dialog
      v-model="renameModalVisible"
      :title="$t('systemFile.op.rename')"
      :close-on-click-modal="false"
    >
      <el-form
        ref="renameFormRef"
        :model="renameForm"
        :rules="renameRules"
        label-position="top"
      >
        <el-form-item prop="name" :label="$t('systemFile.rename.field')">
          <el-input
            v-model="renameForm.name"
            maxlength="20"
            show-word-limit
            :placeholder="$t('systemFile.rename.placeholder')"
          />
        </el-form-item>
      </el-form>
      <template #footer
        ><el-button @click="renameModalVisible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="renameSubmitting"
          @click="submitRename"
          >保存</el-button
        ></template
      >
    </el-dialog>

    <FileVisibilityDialog
      v-model="visibilityVisible"
      :file-keys="visibilityKeys"
      :context-key="contextKey"
      @changed="() => fetchFiles()"
    />

    <!-- 移动分类 -->
    <el-dialog
      v-model="moveModalVisible"
      :title="$t('systemFile.op.move')"
      :close-on-click-modal="false"
    >
      <el-form :model="{ moveTarget }" label-position="top">
        <el-form-item :label="$t('systemFile.move.target')">
          <el-select
            v-model="moveTarget"
            :placeholder="$t('systemFile.move.placeholder')"
          >
            <el-option
              :value="0"
              :label="$t('systemFile.cate.uncategorized')"
              >{{ $t('systemFile.cate.uncategorized') }}</el-option
            >
            <el-option
              v-for="c in flatCateList"
              :key="c.id"
              :value="c.id"
              :label="`${'  '.repeat(c.depth)}${c.name}`"
            >
              {{ `${'  '.repeat(c.depth)}${c.name}` }}
            </el-option>
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer
        ><el-button @click="moveModalVisible = false">取消</el-button
        ><el-button type="primary" :loading="moveSubmitting" @click="submitMove"
          >保存</el-button
        ></template
      >
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance, UploadRequestOptions } from 'element-plus';
  import { Delete, Plus, Search } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot, isLogin } from '@/utils/auth';
  import createLockedContentToolRegistry from '@/modules/peanut-content-tools/src/contributions';
  import {
    getFileCateList,
    addFileCate,
    editFileCate,
    deleteFileCate,
    getFileList,
    moveFile,
    renameFile,
    deleteFile,
    uploadFile,
    type FileType,
    type FileCateRecord,
    type FileRecord,
  } from '@/modules/peanut-file/api';
  import FileContentViewer from './FileContentViewer.vue';
  import FileVisibilityDialog from './FileVisibilityDialog.vue';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(false);
  const user = useUserStore();
  const sessionGeneration = ref(getSessionSnapshot().generation);
  const contentRegistry = createLockedContentToolRegistry();
  const contextKey = computed(() =>
    JSON.stringify([
      sessionGeneration.value,
      user.accountId,
      user.tenantName,
      user.role,
      user.permissions,
    ])
  );
  const unsubscribe = user.$subscribe(
    () => {
      sessionGeneration.value = getSessionSnapshot().generation;
    },
    { flush: 'sync' }
  );
  const previewItem = ref<FileRecord>();
  const previewVisible = ref(false);

  const activeType = ref<FileType>(10);
  const acceptMap: Record<FileType, string> = {
    10: 'image/*',
    20: 'video/*',
    30: '',
  };
  const uploadPermissionMap: Record<FileType, string> = {
    10: 'peanut.file.upload.image',
    20: 'peanut.file.upload.video',
    30: 'peanut.file.upload.file',
  };
  const uploadPermission = computed(
    () => uploadPermissionMap[activeType.value]
  );

  // ---- 分类 ----
  let disposed = false;
  const cateList = ref<FileCateRecord[]>([]);
  let cateRequestId = 0;
  const flatCateList = computed(() => {
    const result: Array<FileCateRecord & { depth: number }> = [];
    const walk = (items: FileCateRecord[], depth: number) => {
      items.forEach((item) => {
        result.push({ ...item, depth });
        if (item.children?.length) walk(item.children, depth + 1);
      });
    };
    walk(cateList.value, 0);
    return result;
  });
  const currentCid = ref<number | ''>('');

  const fetchCate = async () => {
    if (disposed) return;
    const type = activeType.value;
    const requestContext = contextKey.value;
    cateRequestId += 1;
    const requestId = cateRequestId;
    const { data } = await getFileCateList(type);
    if (
      disposed ||
      requestId !== cateRequestId ||
      type !== activeType.value ||
      requestContext !== contextKey.value
    )
      return;
    cateList.value = data;
  };

  // ---- 文件 ----
  const renderData = ref<FileRecord[]>([]);
  let fileListRequestId = 0;
  let fileListController: AbortController | undefined;
  const searchName = ref('');
  const searchSource = ref<number | ''>('');
  const checkedIds = ref<number[]>([]);
  const visibilityVisible = ref(false);
  const visibilityKeys = ref<string[]>([]);
  const openVisibility = (selection: boolean) => {
    visibilityKeys.value = selection
      ? renderData.value
          .filter((item) => checkedIds.value.includes(item.id))
          .map((item) => item.file_key)
          .filter((key): key is string => typeof key === 'string')
      : [];
    visibilityVisible.value = true;
  };
  const pagination = reactive({ current: 1, pageSize: 24, total: 0 });

  const fetchFiles = async (page = 1) => {
    if (disposed) return;
    fileListController?.abort();
    const controller = new AbortController();
    fileListController = controller;
    previewVisible.value = false;
    previewItem.value = undefined;
    const type = activeType.value;
    fileListRequestId += 1;
    const requestId = fileListRequestId;
    const requestContext = contextKey.value;
    setLoading(true);
    try {
      const { data } = await getFileList(
        {
          type,
          cid: currentCid.value,
          name: searchName.value,
          source: searchSource.value,
          page_no: page,
          page_size: pagination.pageSize,
        },
        controller.signal
      );
      if (
        requestId !== fileListRequestId ||
        type !== activeType.value ||
        requestContext !== contextKey.value
      )
        return;
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
      checkedIds.value = [];
    } catch {
      return;
    } finally {
      if (requestId === fileListRequestId && type === activeType.value) {
        setLoading(false);
      }
    }
  };

  const init = async () => {
    const requestContext = contextKey.value;
    await fetchCate();
    if (disposed || requestContext !== contextKey.value) return;
    await fetchFiles(1);
  };
  init();

  const onTypeChange = async (nextType: string | number) => {
    fileListController?.abort();
    const type = Number(nextType);
    if (type !== 10 && type !== 20 && type !== 30) return;
    activeType.value = type;
    // Invalidate in-flight requests before awaiting the category list; a slow
    // response for the previous tab must not repopulate this tab's file grid.
    cateRequestId += 1;
    fileListRequestId += 1;
    currentCid.value = '';
    searchName.value = '';
    searchSource.value = '';
    cateList.value = [];
    renderData.value = [];
    checkedIds.value = [];
    pagination.current = 1;
    pagination.total = 0;
    setLoading(false);
    await fetchCate();
    await fetchFiles(1);
  };

  const selectCate = (cid: number | '') => {
    currentCid.value = cid;
    fetchFiles(1);
  };

  const toggleCheck = (id: number) => {
    const i = checkedIds.value.indexOf(id);
    if (i >= 0) checkedIds.value.splice(i, 1);
    else checkedIds.value.push(id);
  };
  // ---- 上传回调 ----
  const onUploadSuccess = () => {
    ElMessage.success(t('systemFile.tip.uploadOk'));
    fetchFiles(pagination.current);
  };

  // ---- 分类弹窗 ----
  const cateModalVisible = ref(false);
  const cateIsEdit = ref(false);
  const cateSubmitting = ref(false);
  const cateFormRef = ref<FormInstance>();
  const cateForm = reactive({ id: 0, pid: 0, name: '' });
  const cateRules = {
    name: [
      { required: true, message: t('systemFile.cate.field.name.required') },
      { max: 20, message: t('systemFile.cate.field.name.max') },
    ],
  };

  const handleCateAdd = (pid = 0) => {
    cateIsEdit.value = false;
    cateForm.id = 0;
    cateForm.pid = pid;
    cateForm.name = '';
    cateModalVisible.value = true;
  };
  const handleCateEdit = (c: FileCateRecord) => {
    cateIsEdit.value = true;
    cateForm.id = c.id;
    cateForm.name = c.name;
    cateModalVisible.value = true;
  };
  const submitCate = async () => {
    const valid = await cateFormRef.value?.validate().catch(() => false);
    if (!valid) return;
    cateSubmitting.value = true;
    try {
      if (cateIsEdit.value) {
        await editFileCate({ id: cateForm.id, name: cateForm.name });
      } else {
        await addFileCate({
          type: activeType.value,
          pid: cateForm.pid,
          name: cateForm.name,
        });
      }
      ElMessage.success(t('systemFile.tip.ok'));
      cateModalVisible.value = false;
      await fetchCate();
    } finally {
      cateSubmitting.value = false;
    }
  };
  const handleCateDelete = async (c: FileCateRecord) => {
    await deleteFileCate(c.id);
    ElMessage.success(t('systemFile.tip.ok'));
    if (currentCid.value === c.id) currentCid.value = '';
    await fetchCate();
    await fetchFiles(1);
  };

  // ---- 重命名 ----
  const renameModalVisible = ref(false);
  const renameSubmitting = ref(false);
  const renameFormRef = ref<FormInstance>();
  const renameForm = reactive({ id: 0, name: '' });
  const renameRules = {
    name: [
      { required: true, message: t('systemFile.rename.required') },
      {
        max: 20,
        message: t('systemFile.cate.field.name.max').replace(
          t('systemFile.cate.field.name'),
          t('systemFile.rename.field')
        ),
      },
    ],
  };
  const handleRename = (item: FileRecord) => {
    renameForm.id = item.id;
    renameForm.name = item.name;
    renameModalVisible.value = true;
  };
  const submitRename = async () => {
    const valid = await renameFormRef.value?.validate().catch(() => false);
    if (!valid) return;
    renameSubmitting.value = true;
    try {
      await renameFile(renameForm.id, renameForm.name);
    } catch {
      return;
    } finally {
      renameSubmitting.value = false;
    }
    ElMessage.success(t('systemFile.tip.ok'));
    renameModalVisible.value = false;
    await fetchFiles(pagination.current);
  };

  // ---- 删除 ----
  const handleDelete = async (item: FileRecord) => {
    await deleteFile([item.id]);
    ElMessage.success(t('systemFile.tip.ok'));
    await fetchFiles(pagination.current);
  };
  const handleBatchDelete = async () => {
    await deleteFile([...checkedIds.value]);
    ElMessage.success(t('systemFile.tip.ok'));
    await fetchFiles(pagination.current);
  };

  const previewFile = (item: FileRecord) => {
    previewItem.value = item;
    previewVisible.value = true;
  };
  watch(
    contextKey,
    () => {
      fileListController?.abort();
      cateRequestId += 1;
      fileListRequestId += 1;
      renderData.value = [];
      cateList.value = [];
      currentCid.value = '';
      checkedIds.value = [];
      searchName.value = '';
      searchSource.value = '';
      pagination.current = 1;
      pagination.pageSize = 24;
      pagination.total = 0;
      cateModalVisible.value = false;
      cateIsEdit.value = false;
      Object.assign(cateForm, { id: 0, pid: 0, name: '' });
      renameModalVisible.value = false;
      Object.assign(renameForm, { id: 0, name: '' });
      moveModalVisible.value = false;
      moveTarget.value = 0;
      previewVisible.value = false;
      previewItem.value = undefined;
      setLoading(false);
      if (isLogin() && user.accountId !== undefined) init();
    },
    { flush: 'sync' }
  );
  watch(previewVisible, (visible) => {
    if (!visible) previewItem.value = undefined;
  });
  onBeforeUnmount(() => {
    disposed = true;
    fileListController?.abort();
    unsubscribe();
    cateRequestId += 1;
    fileListRequestId += 1;
  });

  // ---- 移动 ----
  const moveModalVisible = ref(false);
  const moveSubmitting = ref(false);
  const moveTarget = ref<number>(0);
  const openMove = () => {
    moveTarget.value = 0;
    moveModalVisible.value = true;
  };
  const submitMove = async () => {
    moveSubmitting.value = true;
    try {
      await moveFile([...checkedIds.value], moveTarget.value);
      ElMessage.success(t('systemFile.tip.ok'));
      moveModalVisible.value = false;
      await fetchFiles(pagination.current);
    } finally {
      moveSubmitting.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemFile',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .body {
    margin-top: 8px;
  }

  .asset-selector-link {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 8px;
  }

  .cate-panel {
    border: 1px solid var(--color-neutral-3);
    border-radius: 4px;
    overflow: hidden;
  }

  .cate-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    font-weight: 500;
    border-bottom: 1px solid var(--color-neutral-3);
  }

  .cate-list {
    margin: 0;
    padding: 4px 0;
    list-style: none;
    max-height: 520px;
    overflow-y: auto;

    li {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 12px;
      cursor: pointer;

      &:hover {
        background: var(--color-fill-2);
      }

      &.active {
        background: var(--color-primary-light-1);
        color: rgb(var(--primary-6));
      }
    }

    .cate-name {
      flex: 1;
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      padding: 0;
      border: 0;
      background: transparent;
      color: inherit;
      text-align: left;
      cursor: pointer;
    }

    .cate-ops {
      display: none;
      align-items: center;
      gap: 4px;
    }

    li:hover .cate-ops,
    li:focus-within .cate-ops,
    li.active .cate-ops {
      display: flex;
    }
  }

  .toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
  }

  .selected-tip {
    color: var(--color-text-3);
    font-size: 12px;
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 16px;
  }

  .grid-item {
    border: 1px solid var(--color-neutral-3);
    border-radius: 4px;
    overflow: hidden;

    &.checked {
      border-color: rgb(var(--primary-6));
    }
  }

  .thumb {
    position: relative;
    height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-fill-2);
    cursor: pointer;

    img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }
  }

  .thumb-check {
    position: absolute;
    top: 6px;
    left: 6px;
  }

  .thumb-icon {
    color: var(--color-text-3);
  }

  .name {
    padding: 6px 8px;
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ops {
    display: flex;
    justify-content: space-between;
    padding: 0 4px 4px;
    border-top: 1px solid var(--color-neutral-3);
  }

  .pager {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>
