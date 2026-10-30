<template>
  <span v-if="canBrowse" class="file-picker-trigger">
    <el-button :size="elementSize" :icon="Folder" @click="open">
      {{ buttonText }}
    </el-button>
  </span>

  <el-dialog
    v-model="visible"
    :title="title"
    width="860px"
    destroy-on-close
    @close="close"
  >
    <div class="picker-content">
      <div class="picker-filters">
        <el-select v-model="cid" style="width: 190px" @change="refresh">
          <el-option label="全部分类" value="" />
          <el-option label="未分组" :value="0" />
          <el-option
            v-for="item in flatCategories"
            :key="item.id"
            :value="item.id"
            :label="`${'\u3000'.repeat(item.depth)}${item.name}`"
          />
        </el-select>
        <el-select v-model="source" style="width: 140px" @change="refresh">
          <el-option label="全部来源" value="" />
          <el-option label="后台上传" :value="0" />
          <el-option label="用户上传" :value="1" />
        </el-select>
        <el-input
          v-model="name"
          clearable
          style="width: 220px"
          placeholder="搜索素材名称"
          @keyup.enter="refresh"
          @clear="refresh"
        >
          <template #append>
            <el-button :icon="Search" aria-label="搜索" @click="refresh" />
          </template>
        </el-input>
        <el-upload
          v-if="canUpload"
          :http-request="
            (options: UploadRequestOptions) => uploadFile(type, options)
          "
          :data="{ cid: String(cid === '' ? 0 : cid) }"
          :accept="acceptMap[type]"
          :show-file-list="false"
          :on-success="onUploadSuccess"
        >
          <el-button type="primary" :icon="Upload">上传</el-button>
        </el-upload>
      </div>

      <div v-loading="loading" class="picker-results">
        <div v-if="files.length" class="picker-grid">
          <button
            v-for="item in files"
            :key="item.id"
            type="button"
            class="picker-item"
            :class="{ selected: isSelected(item.id) }"
            @click="toggle(item)"
          >
            <img v-if="type === 10" :src="item.url" :alt="item.name" />
            <span v-else class="file-icon">
              <el-icon :size="42">
                <VideoPlay v-if="type === 20" />
                <Document v-else />
              </el-icon>
            </span>
            <span class="file-name" :title="item.name">{{ item.name }}</span>
            <el-icon v-if="isSelected(item.id)" class="check"
              ><Select
            /></el-icon>
          </button>
        </div>
        <el-empty v-else />
      </div>

      <div class="picker-footer">
        <span>已选择 {{ selected.length }} / {{ effectiveLimit }}</span>
        <el-pagination
          :current-page="page.current"
          :page-size="page.pageSize"
          :total="page.total"
          layout="prev, pager, next"
          @current-change="fetchFiles"
        />
      </div>
    </div>
    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button
        type="primary"
        :disabled="selected.length === 0"
        @click="confirm"
      >
        确定
      </el-button>
    </template>
  </el-dialog>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref } from 'vue';
  import { useRoute } from 'vue-router';
  import { ElMessage, type UploadRequestOptions } from 'element-plus';
  import {
    Document,
    Folder,
    Search,
    Select,
    Upload,
    VideoPlay,
  } from '@element-plus/icons-vue';
  import { hasPermission } from '@/hooks/permission';
  import {
    getFileCateList,
    getFileList,
    uploadFile,
    type FileCateRecord,
    type FileRecord,
    type FileType,
  } from '@/modules/peanut-file/api';

  const route = useRoute();
  const props = withDefaults(
    defineProps<{
      type: FileType;
      limit?: number;
      title?: string;
      buttonText?: string;
      size?: 'mini' | 'small' | 'medium' | 'large';
    }>(),
    {
      limit: 1,
      title: '选择素材',
      buttonText: '素材库',
      size: 'medium',
    }
  );

  const emit = defineEmits<{
    (event: 'open'): void;
    (event: 'select', urls: string[], files: FileRecord[]): void;
  }>();

  const visible = ref(false);
  const elementSize = computed<'small' | 'default' | 'large'>(() => {
    if (props.size === 'large') return 'large';
    if (props.size === 'mini' || props.size === 'small') return 'small';
    return 'default';
  });
  const canBrowse = computed(
    () =>
      hasPermission('peanut.file.list', route.path) &&
      hasPermission('peanut.file.category.list', route.path)
  );
  const uploadPermissionMap: Record<FileType, string> = {
    10: 'peanut.file.upload.image',
    20: 'peanut.file.upload.video',
    30: 'peanut.file.upload.file',
  };
  const canUpload = computed(() =>
    hasPermission(uploadPermissionMap[props.type], route.path)
  );
  const loading = ref(false);
  const categories = ref<FileCateRecord[]>([]);
  const files = ref<FileRecord[]>([]);
  const selected = ref<FileRecord[]>([]);
  const cid = ref<number | ''>('');
  const source = ref<number | ''>('');
  const name = ref('');
  const page = reactive({ current: 1, pageSize: 15, total: 0 });
  const effectiveLimit = computed(() => Math.max(1, props.limit));
  const acceptMap: Record<FileType, string> = {
    10: 'image/*',
    20: 'video/*',
    30: '.zip,.rar,.txt,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.7z,.gz',
  };
  const flatCategories = computed(() => {
    const result: Array<FileCateRecord & { depth: number }> = [];
    const walk = (items: FileCateRecord[], depth: number) => {
      items.forEach((item) => {
        result.push({ ...item, depth });
        if (item.children?.length) walk(item.children, depth + 1);
      });
    };
    walk(categories.value, 0);
    return result;
  });

  const fetchCategories = async () => {
    const { data } = await getFileCateList(props.type);
    categories.value = data;
  };
  const fetchFiles = async (current = 1) => {
    loading.value = true;
    try {
      const { data } = await getFileList({
        type: props.type,
        cid: cid.value,
        source: source.value,
        name: name.value,
        page_no: current,
        page_size: page.pageSize,
      });
      files.value = data.lists;
      page.current = data.pageNo;
      page.total = data.count;
    } finally {
      loading.value = false;
    }
  };
  const refresh = () => fetchFiles(1);
  const open = async () => {
    emit('open');
    selected.value = [];
    cid.value = '';
    source.value = '';
    name.value = '';
    visible.value = true;
    await Promise.all([fetchCategories(), fetchFiles(1)]);
  };
  const close = () => {
    visible.value = false;
    selected.value = [];
  };
  const isSelected = (id: number) =>
    selected.value.some((item) => item.id === id);
  const toggle = (item: FileRecord) => {
    const index = selected.value.findIndex(
      (selectedItem) => selectedItem.id === item.id
    );
    if (index >= 0) {
      selected.value.splice(index, 1);
      return;
    }
    if (effectiveLimit.value === 1) {
      selected.value = [item];
      return;
    }
    if (selected.value.length >= effectiveLimit.value) {
      ElMessage.warning(`最多选择 ${effectiveLimit.value} 个素材`);
      return;
    }
    selected.value.push(item);
  };
  const confirm = () => {
    emit(
      'select',
      selected.value.map((item) => item.url),
      [...selected.value]
    );
    close();
  };
  const onUploadSuccess = async () => {
    ElMessage.success('上传成功');
    await fetchFiles(1);
  };
</script>

<style scoped lang="less">
  .file-picker-trigger {
    display: inline-flex;
  }
  .picker-content {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .picker-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }
  .picker-results {
    width: 100%;
    min-height: 330px;
  }
  .picker-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    min-height: 330px;
  }
  .picker-item {
    position: relative;
    display: flex;
    min-width: 0;
    height: 150px;
    padding: 8px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--el-border-color);
    border-radius: 6px;
    background: var(--el-bg-color);
    cursor: pointer;
  }
  .picker-item.selected {
    border-color: var(--el-color-primary);
  }
  .picker-item img {
    width: 100%;
    height: 105px;
    object-fit: contain;
  }
  .file-icon {
    display: flex;
    height: 105px;
    align-items: center;
  }
  .file-name {
    width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .check {
    position: absolute;
    top: 7px;
    right: 7px;
    color: var(--el-color-primary);
  }
  .picker-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
</style>
