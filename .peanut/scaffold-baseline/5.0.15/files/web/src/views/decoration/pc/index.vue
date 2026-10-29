<template>
  <div class="container">
    <Breadcrumb :items="['menu.decoration', 'menu.decoration.pc']" />
    <div v-loading="loading">
      <el-card class="general-card" header="PC 首页 Banner">
        <el-alert type="info" :closable="false" style="margin-bottom: 16px">
          PC 首页只有一个标准 Banner
          组件；可调整启用状态、条目内容和展示样式，保存后客户端即时读取。
        </el-alert>
        <el-empty
          v-if="!page.id || !banner"
          description="暂无 PC Banner 装修页面"
        />
        <template v-else>
          <div class="pc-workbench">
            <aside class="preview-pane">
              <div class="preview-title">实时预览</div>
              <div class="desktop-preview">
                <div class="desktop-toolbar">
                  <span></span><span></span><span></span>
                </div>
                <div class="banner-preview" :style="previewStyle">
                  <template v-if="banner.content.enabled === 1">
                    <img
                      v-if="activePreviewItem?.image"
                      :src="activePreviewItem.image"
                      alt="Banner 预览"
                    />
                    <div v-else class="banner-placeholder">
                      {{ activePreviewItem?.name || 'PC Banner' }}
                    </div>
                    <div v-if="activePreviewItem?.name" class="banner-caption">
                      {{ activePreviewItem.name }}
                    </div>
                  </template>
                  <div v-else class="banner-placeholder">Banner 已停用</div>
                </div>
                <div class="preview-dots">
                  <button
                    v-for="(item, index) in items"
                    :key="`dot-${index}-${item.name}`"
                    type="button"
                    :class="{ active: previewIndex === index }"
                    :aria-label="`预览第 ${index + 1} 项`"
                    @click="previewIndex = index"
                  />
                </div>
              </div>
            </aside>
            <section class="editor-pane">
              <el-form
                :model="banner.content"
                label-position="top"
                class="form-width"
              >
                <el-form-item label="启用状态">
                  <el-switch
                    v-model="banner.content.enabled"
                    :active-value="1"
                    :inactive-value="0"
                  />
                </el-form-item>
              </el-form>

              <el-divider>Banner 条目</el-divider>
              <el-card
                v-for="(item, index) in items"
                :key="`${index}-${item.name}-${item.image}`"
                class="item-card"
              >
                <template #header>
                  <div class="card-header"
                    ><span>第 {{ index + 1 }} 项</span>
                    <el-button
                      v-if="items.length > 1"
                      type="danger"
                      size="small"
                      @click="removeItem(index)"
                      >删除</el-button
                    ></div
                  >
                </template>
                <el-form :model="item" label-position="top">
                  <el-form-item label="图片">
                    <div class="image-field">
                      <img v-if="item.image" :src="item.image" alt="Banner" />
                      <FilePicker
                        :type="10"
                        :limit="1"
                        button-text="选择图片"
                        @select="(urls) => setImage(item, urls)"
                      />
                    </div>
                  </el-form-item>
                  <el-form-item label="名称"
                    ><el-input v-model="item.name"
                  /></el-form-item>
                  <el-form-item label="业务链接">
                    <el-space fill wrap>
                      <el-select
                        v-model="item.link.target_type"
                        style="width: 150px"
                        @change="ensureQuery(item)"
                      >
                        <el-option label="站内页面" value="shop" />
                        <el-option label="文章" value="article" />
                        <el-option label="自定义链接" value="custom" />
                        <el-option label="小程序" value="mini_program" />
                      </el-select>
                      <el-select
                        v-if="item.link.target_type === 'article'"
                        v-model="item.link.target"
                        filterable
                        placeholder="选择可见文章"
                        style="width: 220px"
                      >
                        <el-option
                          v-for="article in articleOptions"
                          :key="article.id"
                          :label="article.title"
                          :value="String(article.id)"
                        />
                      </el-select>
                      <el-input
                        v-else
                        v-model="item.link.target"
                        placeholder="目标"
                      />
                      <template
                        v-if="
                          item.link.target_type === 'mini_program' &&
                          item.link.query
                        "
                      >
                        <el-input
                          v-model="item.link.query.app_id"
                          placeholder="小程序 AppID"
                        />
                        <el-select
                          v-model="item.link.query.env_version"
                          style="width: 130px"
                        >
                          <el-option label="开发版" value="develop" />
                          <el-option label="体验版" value="trial" />
                          <el-option label="正式版" value="release" />
                        </el-select>
                        <el-input
                          v-model="item.link.query.web_url"
                          placeholder="PC 回退网址（http/https）"
                        />
                      </template>
                    </el-space>
                  </el-form-item>
                </el-form>
              </el-card>
              <el-button v-if="items.length < 10" @click="addItem"
                >添加条目</el-button
              >

              <el-divider>展示样式</el-divider>
              <el-form :model="banner.styles" inline>
                <el-form-item label="位置"
                  ><el-select v-model="banner.styles.position"
                    ><el-option label="绝对定位" value="absolute" /><el-option
                      label="相对定位"
                      value="relative" /></el-select
                ></el-form-item>
                <el-form-item label="左偏移"
                  ><el-input v-model="banner.styles.left"
                /></el-form-item>
                <el-form-item label="上偏移"
                  ><el-input v-model="banner.styles.top"
                /></el-form-item>
                <el-form-item label="宽度"
                  ><el-input v-model="banner.styles.width"
                /></el-form-item>
                <el-form-item label="高度"
                  ><el-input v-model="banner.styles.height"
                /></el-form-item>
              </el-form>
              <el-space class="actions">
                <el-button
                  v-permission="['decoration/pc/page/save']"
                  type="primary"
                  :loading="submitLoading"
                  @click="handleSubmit"
                  >保存</el-button
                >
                <el-button @click="load">重置</el-button>
              </el-space>
            </section>
          </div>
        </template>
      </el-card>
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import FilePicker from '@/components/file-picker/index.vue';
  import {
    getPcDecorationLists,
    getPcDecorationDetail,
    getDecorationArticleOptions,
    savePcDecoration,
    type DecorationComponent as MutableComponent,
    type DecorationArticleOption,
    type DecorationItem,
    type DecorationPage,
  } from '@/api/decoration';

  const loading = ref(true);
  const submitLoading = ref(false);
  const articleOptions = ref<DecorationArticleOption[]>([]);
  const previewIndex = ref(0);
  const page = reactive<DecorationPage>({
    id: 0,
    type: 4,
    name: '',
    data: [],
    meta: [],
  });
  const banner = computed<MutableComponent | undefined>(() => {
    const list = Array.isArray(page.data) ? page.data : [];
    return list.find((component) => component.name === 'pc-banner');
  });
  const items = computed<DecorationItem[]>(() => {
    const value = banner.value?.content.data;
    return Array.isArray(value) ? value : [];
  });
  const activePreviewItem = computed(
    () => items.value[previewIndex.value] || items.value[0]
  );
  const previewStyle = computed(() => {
    const styles = banner.value?.styles || {};
    return {
      width: String(styles.width || '100%'),
      height: String(styles.height || '200px'),
      maxWidth: '100%',
      maxHeight: '260px',
      marginLeft: String(styles.left || '0'),
      marginTop: String(styles.top || '0'),
    };
  });
  const loadArticleOptions = async () => {
    const { data } = await getDecorationArticleOptions();
    articleOptions.value = data;
  };

  const ensureQuery = (item: DecorationItem) => {
    item.link.query ||= {};
  };
  const normalizeItems = () => {
    items.value.forEach((item) => {
      if (item.link.target_type === 'mini_program' && !item.link.query)
        item.link.query = {};
    });
  };
  const load = async () => {
    loading.value = true;
    try {
      const { data: list } = await getPcDecorationLists();
      const summary = list[0];
      if (!summary) {
        page.id = 0;
        return;
      }
      const { data } = await getPcDecorationDetail(summary.id);
      Object.assign(page, data);
      normalizeItems();
      previewIndex.value = 0;
    } finally {
      loading.value = false;
    }
  };
  load();
  loadArticleOptions();

  const addItem = () => {
    if (!banner.value || items.value.length >= 10) return;
    items.value.push({
      image: '',
      name: '',
      link: { target_type: 'shop', target: 'home' },
    });
  };
  const removeItem = (index: number) => {
    if (items.value.length <= 1) return;
    items.value.splice(index, 1);
    previewIndex.value = Math.min(previewIndex.value, items.value.length - 1);
  };
  const setImage = (item: DecorationItem, urls: string[]) => {
    item.image = urls[0] || '';
  };
  const handleSubmit = async () => {
    if (
      !page.id ||
      !banner.value ||
      items.value.length < 1 ||
      items.value.length > 10
    )
      return;
    submitLoading.value = true;
    try {
      await savePcDecoration({
        id: page.id,
        type: 4,
        data: page.data,
        meta: [],
      });
      ElMessage.success('保存成功');
    } catch {
      // The shared Axios interceptor displays the server error.
    } finally {
      submitLoading.value = false;
    }
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }
  .form-width {
    max-width: 560px;
  }
  .pc-workbench {
    display: grid;
    grid-template-columns: minmax(360px, 44%) minmax(0, 1fr);
    gap: 20px;
    align-items: start;
  }
  .preview-pane {
    position: sticky;
    top: 76px;
  }
  .preview-title {
    margin-bottom: 10px;
    color: var(--el-text-color-secondary);
    font-size: 13px;
    text-align: center;
  }
  .desktop-preview {
    min-height: 360px;
    padding-bottom: 18px;
    overflow: hidden;
    border: 1px solid var(--el-border-color);
    border-radius: 12px;
    background: #f5f7fa;
    box-shadow: 0 14px 34px rgb(15 23 42 / 12%);
  }
  .desktop-toolbar {
    display: flex;
    padding: 11px;
    gap: 6px;
    background: #fff;
  }
  .desktop-toolbar span {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #d1d5db;
  }
  .banner-preview {
    position: relative;
    display: grid;
    min-height: 160px;
    place-items: center;
    overflow: hidden;
    background: linear-gradient(135deg, #dbeafe, #ede9fe);
  }
  .banner-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .banner-placeholder {
    color: #64748b;
    font-size: 20px;
  }
  .banner-caption {
    position: absolute;
    right: 12px;
    bottom: 12px;
    left: 12px;
    padding: 8px 12px;
    border-radius: 6px;
    background: rgb(15 23 42 / 66%);
    color: #fff;
  }
  .preview-dots {
    display: flex;
    justify-content: center;
    margin-top: 14px;
    gap: 8px;
  }
  .preview-dots button {
    width: 8px;
    height: 8px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: #cbd5e1;
    cursor: pointer;
  }
  .preview-dots button.active {
    background: var(--el-color-primary);
  }
  .item-card {
    margin-bottom: 14px;
  }
  .image-field {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .image-field img {
    width: 180px;
    height: 90px;
    object-fit: cover;
    border-radius: 4px;
  }
  .actions {
    margin-top: 20px;
  }
  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  @media (max-width: 1200px) {
    .pc-workbench {
      grid-template-columns: 1fr;
    }
    .preview-pane {
      position: static;
    }
  }
</style>
