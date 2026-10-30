<template>
  <div class="container">
    <Breadcrumb :items="['menu.decoration', 'menu.decoration.tabbar']" />
    <div v-loading="loading">
      <el-card class="general-card" header="Tabbar">
        <el-alert type="info" :closable="false" style="margin-bottom: 16px">
          首项固定显示并指向首页；其余项目可调整名称、图标、链接和显示状态。总项数为
          2～5，至少保留 2 个可见项。
        </el-alert>
        <div class="tabbar-workbench">
          <aside class="preview-pane">
            <div class="preview-title">实时预览</div>
            <div class="phone-preview">
              <div class="phone-content">页面内容预览</div>
              <div class="tabbar-preview">
                <div
                  v-for="(item, index) in visibleItems"
                  :key="`preview-${item.position}-${item.name}`"
                  class="tabbar-preview-item"
                  :style="{
                    color:
                      index === 0
                        ? form.style.selected_color
                        : form.style.default_color,
                  }"
                >
                  <img
                    v-if="index === 0 ? item.selected : item.unselected"
                    :src="
                      images.preview(
                        index === 0 ? item.selected : item.unselected
                      )
                    "
                    alt=""
                  />
                  <span v-else class="tabbar-icon-placeholder">●</span>
                  <small>{{ item.name || '菜单' }}</small>
                </div>
              </div>
            </div>
          </aside>
          <section class="editor-pane">
            <el-form
              :model="form.style"
              label-position="top"
              class="style-form"
            >
              <el-form-item label="默认颜色">
                <el-input
                  v-model="form.style.default_color"
                  placeholder="#666666"
                />
              </el-form-item>
              <el-form-item label="选中颜色">
                <el-input
                  v-model="form.style.selected_color"
                  placeholder="#2F80ED"
                />
              </el-form-item>
            </el-form>

            <el-divider>Tabbar 项</el-divider>
            <el-card
              v-for="(item, index) in form.list"
              :key="item.position ?? index"
              class="item-card"
            >
              <template #header>
                <div class="card-header"
                  ><span
                    >第 {{ index + 1 }} 项{{
                      index === 0 ? '（固定首页）' : ''
                    }}</span
                  >
                  <el-space>
                    <el-tag v-if="index === 0" type="primary">固定</el-tag>
                    <template v-else>
                      <el-button
                        size="small"
                        :disabled="index === 1"
                        @click="moveItem(index, -1)"
                        >上移</el-button
                      >
                      <el-button
                        size="small"
                        :disabled="index === form.list.length - 1"
                        @click="moveItem(index, 1)"
                        >下移</el-button
                      >
                      <el-button
                        type="danger"
                        size="small"
                        :disabled="form.list.length <= 2"
                        @click="removeItem(index)"
                        >删除</el-button
                      >
                    </template>
                  </el-space></div
                >
              </template>
              <el-form :model="item" label-position="top">
                <el-form-item label="名称">
                  <el-input
                    v-model="item.name"
                    :maxlength="20"
                    show-word-limit
                    placeholder="名称不能为空且最多 20 个字"
                  />
                </el-form-item>
                <el-space wrap>
                  <el-form-item label="未选中图标">
                    <div class="icon-field">
                      <img
                        v-if="item.unselected"
                        :src="images.preview(item.unselected)"
                        alt=""
                      />
                      <FilePicker
                        :type="10"
                        :limit="1"
                        button-text="选择图标"
                        @select="
                          (urls, files) =>
                            setImage(item, 'unselected', urls, files)
                        "
                      />
                    </div>
                  </el-form-item>
                  <el-form-item label="选中图标">
                    <div class="icon-field">
                      <img
                        v-if="item.selected"
                        :src="images.preview(item.selected)"
                        alt=""
                      />
                      <FilePicker
                        :type="10"
                        :limit="1"
                        button-text="选择图标"
                        @select="
                          (urls, files) =>
                            setImage(item, 'selected', urls, files)
                        "
                      />
                    </div>
                  </el-form-item>
                </el-space>
                <el-form-item label="业务链接">
                  <el-space fill>
                    <el-select
                      v-model="item.link.target_type"
                      style="width: 150px"
                      :disabled="index === 0"
                      @change="ensureQuery(item)"
                    >
                      <el-option label="站内页面" value="shop" />
                      <el-option
                        :label="
                          articleOptions.length ? '文章' : '文章（暂无可选项）'
                        "
                        value="article"
                        :disabled="articleOptions.length === 0"
                      />
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
                      :disabled="index === 0"
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
                    </template>
                  </el-space>
                </el-form-item>
                <el-form-item label="显示状态">
                  <el-switch
                    v-model="item.is_show"
                    :active-value="1"
                    :inactive-value="0"
                    :disabled="index === 0"
                  />
                </el-form-item>
              </el-form>
            </el-card>
            <el-button v-if="form.list.length < 5" @click="addItem"
              >添加 Tabbar 项</el-button
            >
            <div class="actions">
              <el-button
                v-permission="['peanut.settings.manage']"
                type="primary"
                :loading="submitLoading"
                @click="handleSubmit"
                >保存</el-button
              >
            </div>
          </section>
        </div>
      </el-card>
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import FilePicker from '@/components/file-picker/index.vue';
  import usePublicImageReferences from '@/hooks/public-image-references';
  import type { FileRecord } from '@/modules/peanut-file/api';
  import {
    getDecorationTabbar,
    getDecorationArticleOptions,
    saveDecorationTabbar,
    type DecorationLink,
    type DecorationArticleOption,
    type DecorationTabbar,
    type DecorationTabbarItem,
  } from '@/api/decoration';

  const emptyLink = (): DecorationLink => ({
    target_type: 'shop',
    target: 'home',
  });
  const newItem = (position: number): DecorationTabbarItem => ({
    position,
    name: `菜单 ${position + 1}`,
    selected: '',
    unselected: '',
    link:
      position === 0 ? emptyLink() : { target_type: 'shop', target: 'news' },
    is_show: 1,
  });
  const form = reactive<DecorationTabbar>({
    style: { default_color: '#666666', selected_color: '#2F80ED' },
    list: [newItem(0), newItem(1)],
  });
  const loading = ref(true);
  const submitLoading = ref(false);
  const articleOptions = ref<DecorationArticleOption[]>([]);
  const images = usePublicImageReferences();
  const visibleItems = computed(() =>
    form.list.filter((item) => item.is_show === 1)
  );

  const load = async () => {
    loading.value = true;
    try {
      const { data } = await getDecorationTabbar();
      images.replace(data.resource_urls);
      Object.assign(form.style, data.style);
      form.list.splice(
        0,
        form.list.length,
        ...data.list.map((item, index) => {
          if (item.link.target_type === 'mini_program' && !item.link.query)
            item.link.query = {};
          return { ...item, position: index };
        })
      );
    } finally {
      loading.value = false;
    }
  };
  const loadArticleOptions = async () => {
    const { data } = await getDecorationArticleOptions();
    articleOptions.value = data;
  };
  load();
  loadArticleOptions();

  const addItem = () => {
    if (form.list.length >= 5) return;
    form.list.push(newItem(form.list.length));
  };
  const removeItem = (index: number) => {
    if (index === 0 || form.list.length <= 2) return;
    form.list.splice(index, 1);
    form.list.forEach((item, position) => {
      item.position = position;
    });
  };
  const moveItem = (index: number, offset: number) => {
    const next = index + offset;
    if (index <= 0 || next <= 0 || next >= form.list.length) return;
    [form.list[index], form.list[next]] = [form.list[next], form.list[index]];
    form.list.forEach((item, position) => {
      item.position = position;
    });
  };
  const setImage = (
    item: DecorationTabbarItem,
    field: 'selected' | 'unselected',
    urls: string[],
    files: FileRecord[]
  ) => {
    item[field] = images.select(urls, files, item[field]);
  };
  const ensureQuery = (item: DecorationTabbarItem) => {
    item.link.query ||= {};
  };

  const validColor = (value: string) => /^#[0-9a-f]{6}$/i.test(value);
  const handleSubmit = async () => {
    if (
      !validColor(form.style.default_color) ||
      !validColor(form.style.selected_color)
    ) {
      ElMessage.error('颜色必须为 #RRGGBB 格式');
      return;
    }
    if (form.list.length < 2 || form.list.length > 5) {
      ElMessage.error('Tabbar 总项数必须为 2～5 项');
      return;
    }
    if (
      form.list.some((item) => {
        const name = item.name.trim();
        return name === '' || Array.from(name).length > 20;
      })
    ) {
      ElMessage.error('Tabbar 名称不能为空且最多 20 个字');
      return;
    }
    const visible = form.list.filter((item) => item.is_show === 1).length;
    if (
      visible < 2 ||
      form.list[0].is_show !== 1 ||
      form.list[0].link.target_type !== 'shop' ||
      form.list[0].link.target !== 'home'
    ) {
      ElMessage.error('首项必须显示并固定指向首页，且至少保留 2 个可见项');
      return;
    }
    submitLoading.value = true;
    try {
      await saveDecorationTabbar({
        style: { ...form.style },
        list: form.list.map((item, position) => ({ ...item, position })),
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
  .style-form {
    max-width: 560px;
  }
  .tabbar-workbench {
    display: grid;
    grid-template-columns: 340px minmax(0, 1fr);
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
  .phone-preview {
    display: flex;
    width: 320px;
    min-height: 610px;
    padding: 10px;
    flex-direction: column;
    overflow: hidden;
    border: 8px solid #1f2937;
    border-radius: 34px;
    background: #f5f7fa;
    box-shadow: 0 16px 38px rgb(15 23 42 / 16%);
  }
  .phone-content {
    display: grid;
    flex: 1;
    place-items: center;
    color: #9ca3af;
  }
  .tabbar-preview {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: 1fr;
    padding: 9px 4px;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 -4px 16px rgb(15 23 42 / 8%);
  }
  .tabbar-preview-item {
    display: flex;
    min-width: 0;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }
  .tabbar-preview-item img,
  .tabbar-icon-placeholder {
    width: 28px;
    height: 28px;
    object-fit: contain;
  }
  .tabbar-icon-placeholder {
    display: grid;
    place-items: center;
  }
  .tabbar-preview-item small {
    max-width: 58px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .item-card {
    margin-bottom: 14px;
  }
  .icon-field {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .icon-field img {
    width: 48px;
    height: 48px;
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
    .tabbar-workbench {
      grid-template-columns: 1fr;
    }
    .preview-pane {
      position: static;
    }
  }
</style>
