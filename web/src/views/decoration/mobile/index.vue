<template>
  <div class="container">
    <Breadcrumb :items="['menu.decoration', 'menu.decoration.mobile']" />
    <div v-loading="loading">
      <el-card class="general-card">
        <el-tabs
          v-model="activeType"
          @tab-change="loadPageByType(Number($event))"
        >
          <el-tab-pane
            v-for="item in pageTypes"
            :key="item.type"
            :name="item.type"
            :label="item.label"
          />
        </el-tabs>

        <el-empty v-if="!page.id" description="暂无可编辑的装修页面" />
        <template v-else>
          <div class="decoration-workbench">
            <aside class="preview-pane">
              <div class="preview-title">实时预览</div>
              <div class="phone-preview">
                <div
                  class="phone-header"
                  :style="{ background: previewHeaderColor }"
                >
                  {{ previewTitle }}
                </div>
                <div
                  class="phone-content"
                  :style="{
                    backgroundColor: previewBackgroundColor,
                    backgroundImage: previewBackgroundImage,
                  }"
                >
                  <template v-if="activeType === 5">
                    <div
                      class="theme-preview-card"
                      :style="{
                        background: `linear-gradient(135deg, ${theme.themeColor1}, ${theme.themeColor2})`,
                        color: theme.buttonColor,
                      }"
                    >
                      <strong>主题 {{ theme.themeColorId }}</strong>
                      <span>按钮与强调色预览</span>
                    </div>
                  </template>
                  <template v-else>
                    <div
                      v-for="component in previewComponents"
                      :key="`preview-${component.name}`"
                      class="preview-component"
                    >
                      <div
                        v-if="component.name === 'search'"
                        class="preview-search"
                      >
                        搜索商品或资讯
                      </div>
                      <div
                        v-else-if="component.name === 'user-info'"
                        class="preview-user"
                      >
                        <span class="preview-avatar">P</span>
                        <span>欢迎使用 Peanut Module Code</span>
                      </div>
                      <div
                        v-else-if="component.name === 'news'"
                        class="preview-news"
                      >
                        <strong>最新资讯</strong><span>查看更多 ›</span>
                      </div>
                      <div
                        v-else-if="component.name === 'customer-service'"
                        class="preview-service"
                      >
                        <strong>{{
                          content(component).title || '联系客服'
                        }}</strong>
                        <span>{{ content(component).time || '服务时间' }}</span>
                        <span>{{
                          content(component).mobile || '联系电话'
                        }}</span>
                      </div>
                      <div
                        v-else-if="component.name === 'my-service'"
                        class="preview-block"
                      >
                        <strong>{{
                          content(component).title || '我的服务'
                        }}</strong>
                        <div class="preview-grid">
                          <div
                            v-for="item in visibleItems(component).slice(0, 8)"
                            :key="item.name + item.image"
                            class="preview-grid-item"
                          >
                            <img v-if="item.image" :src="item.image" alt="" />
                            <span v-else class="preview-placeholder">图</span>
                            <small>{{ item.name || '服务' }}</small>
                          </div>
                        </div>
                      </div>
                      <div
                        v-else-if="component.name === 'nav'"
                        class="preview-block"
                      >
                        <div class="preview-grid">
                          <div
                            v-for="item in visibleItems(component).slice(0, 10)"
                            :key="item.name + item.image"
                            class="preview-grid-item"
                          >
                            <img v-if="item.image" :src="item.image" alt="" />
                            <span v-else class="preview-placeholder">图</span>
                            <small>{{ item.name || '导航' }}</small>
                          </div>
                        </div>
                      </div>
                      <div
                        v-else
                        class="preview-banner"
                        :style="bannerPreviewStyle(component)"
                      >
                        <img
                          v-if="visibleItems(component)[0]?.image"
                          :src="visibleItems(component)[0].image"
                          alt=""
                        />
                        <span v-else>{{ component.title }}</span>
                      </div>
                    </div>
                  </template>
                </div>
              </div>
            </aside>
            <section class="editor-pane">
              <el-alert
                v-if="activeType === 5"
                type="info"
                :closable="false"
                style="margin-bottom: 16px"
              >
                预设主题的颜色由主题编号决定；需要自定义颜色时选择编号 7。
              </el-alert>

              <el-form
                v-if="activeType === 5"
                :model="theme"
                label-position="top"
                class="form-width"
              >
                <el-form-item label="主题编号">
                  <el-select v-model="theme.themeColorId"
                    ><el-option
                      v-for="item in themeOptions"
                      :key="item.value"
                      :label="item.label"
                      :value="item.value"
                  /></el-select>
                </el-form-item>
                <el-form-item label="主题色 1"
                  ><el-input v-model="theme.themeColor1" placeholder="#RRGGBB"
                /></el-form-item>
                <el-form-item label="主题色 2"
                  ><el-input v-model="theme.themeColor2" placeholder="#RRGGBB"
                /></el-form-item>
                <el-form-item label="导航栏颜色"
                  ><el-input
                    v-model="theme.navigationBarColor"
                    placeholder="#RRGGBB"
                /></el-form-item>
                <el-form-item label="顶部文字颜色">
                  <el-select v-model="theme.topTextColor"
                    ><el-option
                      v-for="item in textColorOptions"
                      :key="item.value"
                      :label="item.label"
                      :value="item.value"
                  /></el-select>
                </el-form-item>
                <el-form-item label="按钮文字颜色">
                  <el-select v-model="theme.buttonColor"
                    ><el-option
                      v-for="item in textColorOptions"
                      :key="item.value"
                      :label="item.label"
                      :value="item.value"
                  /></el-select>
                </el-form-item>
              </el-form>

              <template v-else>
                <el-alert
                  type="info"
                  :closable="false"
                  style="margin-bottom: 16px"
                >
                  标准组件集合固定，只能调整顺序、启用状态和组件内容；固定组件不可隐藏或删除。
                </el-alert>
                <el-card
                  v-if="hasMeta"
                  class="component-card"
                  header="页面设置"
                >
                  <el-form
                    :model="metaContent"
                    label-position="top"
                    class="form-width"
                  >
                    <el-form-item label="页面标题"
                      ><el-input
                        v-model="metaContent.title"
                        :maxlength="8"
                        show-word-limit
                        placeholder="页面标题最多 8 个字"
                    /></el-form-item>
                    <el-form-item label="标题图片">
                      <div class="image-field">
                        <img
                          v-if="metaContent.title_img"
                          :src="String(metaContent.title_img)"
                          alt="标题图片"
                        />
                        <FilePicker
                          :type="10"
                          :limit="1"
                          button-text="选择标题图片"
                          @select="(urls) => setMetaImage('title_img', urls)"
                        />
                      </div>
                    </el-form-item>
                    <el-form-item label="背景颜色"
                      ><el-input
                        v-model="metaContent.bg_color"
                        placeholder="#RRGGBB"
                    /></el-form-item>
                    <el-form-item label="背景图片">
                      <div class="image-field">
                        <img
                          v-if="metaContent.bg_image"
                          :src="String(metaContent.bg_image)"
                          alt="背景图片"
                        />
                        <FilePicker
                          :type="10"
                          :limit="1"
                          button-text="选择背景图片"
                          @select="(urls) => setMetaImage('bg_image', urls)"
                        />
                      </div>
                    </el-form-item>
                    <el-form-item label="标题样式"
                      ><el-select v-model="metaContent.title_type"
                        ><el-option
                          v-for="item in binaryOptions"
                          :key="item.value"
                          :label="item.label"
                          :value="item.value" /></el-select
                    ></el-form-item>
                    <el-form-item label="背景样式"
                      ><el-select v-model="metaContent.bg_type"
                        ><el-option
                          v-for="item in binaryOptions"
                          :key="item.value"
                          :label="item.label"
                          :value="item.value" /></el-select
                    ></el-form-item>
                    <el-form-item label="文字颜色"
                      ><el-select v-model="metaContent.text_color"
                        ><el-option
                          v-for="item in binaryOptions"
                          :key="item.value"
                          :label="item.label"
                          :value="item.value" /></el-select
                    ></el-form-item>
                  </el-form>
                </el-card>
                <el-card
                  v-for="(component, index) in components"
                  :key="component.name"
                  class="component-card"
                >
                  <template #header
                    ><div class="component-header"
                      ><span>{{ component.title }}</span>
                      <el-space>
                        <el-button
                          size="small"
                          :disabled="index === 0"
                          @click="moveComponent(index, -1)"
                        >
                          上移
                        </el-button>
                        <el-button
                          size="small"
                          :disabled="index === components.length - 1"
                          @click="moveComponent(index, 1)"
                        >
                          下移
                        </el-button>
                        <el-tag v-if="isFixed(component)" type="primary"
                          >固定</el-tag
                        >
                        <el-switch
                          v-else-if="hasEnabled(component)"
                          v-model="component.content.enabled"
                          :active-value="1"
                          :inactive-value="0"
                        /> </el-space
                    ></div>
                  </template>

                  <el-form :model="content(component)" label-position="top">
                    <template v-if="isMetaComponent(component)">
                      <el-form-item label="页面标题"
                        ><el-input
                          v-model="metaContent.title"
                          :maxlength="8"
                          show-word-limit
                          placeholder="页面标题最多 8 个字"
                      /></el-form-item>
                      <el-form-item label="背景颜色"
                        ><el-input
                          v-model="metaContent.bg_color"
                          placeholder="#RRGGBB"
                      /></el-form-item>
                      <el-form-item label="标题样式"
                        ><el-select v-model="metaContent.title_type"
                          ><el-option
                            v-for="item in binaryOptions"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value" /></el-select
                      ></el-form-item>
                      <el-form-item label="背景样式"
                        ><el-select v-model="metaContent.bg_type"
                          ><el-option
                            v-for="item in binaryOptions"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value" /></el-select
                      ></el-form-item>
                      <el-form-item label="文字颜色"
                        ><el-select v-model="metaContent.text_color"
                          ><el-option
                            v-for="item in binaryOptions"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value" /></el-select
                      ></el-form-item>
                    </template>

                    <template v-else-if="component.name === 'customer-service'">
                      <el-form-item label="客服标题"
                        ><el-input
                          v-model="content(component).title"
                          :maxlength="20"
                      /></el-form-item>
                      <el-form-item label="服务时间"
                        ><el-input
                          v-model="content(component).time"
                          :maxlength="20"
                      /></el-form-item>
                      <el-form-item label="联系电话"
                        ><el-input
                          v-model="content(component).mobile"
                          :maxlength="20"
                      /></el-form-item>
                      <el-form-item label="客服二维码">
                        <div class="image-field">
                          <img
                            v-if="content(component).qrcode"
                            :src="String(content(component).qrcode)"
                            alt="客服二维码"
                          />
                          <FilePicker
                            :type="10"
                            :limit="1"
                            button-text="选择二维码"
                            @select="
                              (urls) =>
                                setContentImage(component, 'qrcode', urls)
                            "
                          />
                        </div>
                      </el-form-item>
                      <el-form-item label="说明"
                        ><el-input
                          v-model="content(component).remark"
                          type="textarea"
                          :maxlength="20"
                      /></el-form-item>
                    </template>

                    <template v-else-if="component.name === 'my-service'">
                      <el-form-item label="服务标题"
                        ><el-input
                          v-model="content(component).title"
                          :maxlength="20"
                      /></el-form-item>
                      <el-form-item label="服务样式"
                        ><el-select v-model="content(component).style"
                          ><el-option
                            v-for="item in styleOptions"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value" /></el-select
                      ></el-form-item>
                      <ItemEditor
                        :items="items(component)"
                        :article-options="articleOptions"
                        :show-enabled="true"
                        :max="100"
                        @image="(item, urls) => setItemImage(item, urls)"
                        @add="addItem(component)"
                        @remove="(index) => removeItem(component, index)"
                        @move="
                          (index, offset) => moveItem(component, index, offset)
                        "
                      />
                    </template>

                    <template
                      v-else-if="
                        [
                          'banner',
                          'middle-banner',
                          'user-banner',
                          'nav',
                        ].includes(component.name)
                      "
                    >
                      <el-form-item
                        v-if="
                          component.name === 'banner' ||
                          component.name === 'nav'
                        "
                        label="样式"
                      >
                        <el-select v-model="content(component).style"
                          ><el-option
                            v-for="item in styleOptions"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value"
                        /></el-select>
                      </el-form-item>
                      <el-form-item
                        v-if="component.name === 'nav'"
                        label="每行数量"
                        ><el-input-number
                          v-model="content(component).per_line"
                          :min="1"
                          :max="5"
                      /></el-form-item>
                      <el-form-item
                        v-if="component.name === 'nav'"
                        label="显示行数"
                        ><el-input-number
                          v-model="content(component).show_line"
                          :min="1"
                          :max="2"
                      /></el-form-item>
                      <el-form-item
                        v-if="component.name === 'banner'"
                        label="背景样式"
                      >
                        <el-select v-model="content(component).bg_style"
                          ><el-option
                            v-for="item in styleOptions"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value"
                        /></el-select>
                      </el-form-item>
                      <ItemEditor
                        :items="items(component)"
                        :article-options="articleOptions"
                        :show-enabled="component.name !== 'banner'"
                        :show-background="component.name === 'banner'"
                        :max="component.name === 'nav' ? 100 : 5"
                        @image="(item, urls) => setItemImage(item, urls)"
                        @background="
                          (item, urls) => setItemBackground(item, urls)
                        "
                        @add="addItem(component)"
                        @remove="(index) => removeItem(component, index)"
                        @move="
                          (index, offset) => moveItem(component, index, offset)
                        "
                      />
                    </template>
                  </el-form>
                </el-card>
              </template>

              <el-space class="actions">
                <el-button
                  v-permission="['decoration/mobile/page/save']"
                  type="primary"
                  :loading="submitLoading"
                  @click="handleSubmit"
                  >保存</el-button
                >
                <el-button @click="loadPageByType(activeType)">重置</el-button>
              </el-space>
            </section>
          </div>
        </template>
      </el-card>
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { computed, defineComponent, reactive, ref, type PropType } from 'vue';
  import { ElMessage } from 'element-plus';
  import FilePicker from '@/components/file-picker/index.vue';
  import {
    getMobileDecorationLists,
    getMobileDecorationDetail,
    getDecorationArticleOptions,
    saveMobileDecoration,
    type DecorationComponent as MutableComponent,
    type DecorationContent as MutableContent,
    type DecorationArticleOption,
    type DecorationItem,
    type DecorationPage,
  } from '@/api/decoration';

  interface ThemeValue {
    themeColorId: number;
    topTextColor: 'white' | 'black';
    navigationBarColor: string;
    themeColor1: string;
    themeColor2: string;
    buttonColor: 'white' | 'black';
  }

  const ItemEditor = defineComponent({
    components: { FilePicker },
    props: {
      items: { type: Array as PropType<DecorationItem[]>, required: true },
      articleOptions: {
        type: Array as PropType<DecorationArticleOption[]>,
        default: () => [],
      },
      showEnabled: { type: Boolean, default: false },
      showBackground: { type: Boolean, default: false },
      max: { type: Number, default: 5 },
    },
    emits: ['image', 'background', 'add', 'remove', 'move'],
    methods: {
      ensureQuery(item: DecorationItem) {
        item.link.query ||= {};
      },
    },
    template: `
      <div class="item-editor">
        <div v-for="(item, index) in items" :key="index" class="item-row">
          <FilePicker :type="10" :limit="1" button-text="选择图片" @select="(urls) => $emit('image', item, urls)" />
          <img v-if="item.image" :src="item.image" alt="" />
          <FilePicker v-if="showBackground" :type="10" :limit="1" button-text="选择背景" @select="(urls) => $emit('background', item, urls)" />
          <img v-if="showBackground && item.bg" :src="item.bg" alt="" />
          <el-input v-model="item.name" placeholder="名称" />
              <el-select v-model="item.link.target_type" style="width: 130px" @change="ensureQuery(item)">
            <el-option label="站内页面" value="shop" />
            <el-option label="文章" value="article" />
            <el-option label="自定义链接" value="custom" />
            <el-option label="小程序" value="mini_program" />
              </el-select>
              <el-select v-if="item.link.target_type === 'article'" v-model="item.link.target" filterable placeholder="选择可见文章" style="width: 220px">
                <el-option v-for="article in articleOptions" :key="article.id" :label="article.title" :value="String(article.id)" />
              </el-select>
              <el-input v-else v-model="item.link.target" placeholder="目标" />
              <template v-if="item.link.target_type === 'mini_program' && item.link.query">
                <el-input v-model="item.link.query.app_id" placeholder="小程序 AppID" />
                <el-select v-model="item.link.query.env_version" style="width: 130px">
                  <el-option label="开发版" value="develop" />
                  <el-option label="体验版" value="trial" />
                  <el-option label="正式版" value="release" />
                </el-select>
          </template>
          <el-switch v-if="showEnabled" v-model="item.is_show" :active-value="1" :inactive-value="0" />
          <el-space class="item-actions">
            <el-button size="small" :disabled="index === 0" @click="$emit('move', index, -1)">上移</el-button>
            <el-button size="small" :disabled="index === items.length - 1" @click="$emit('move', index, 1)">下移</el-button>
            <el-button size="small" type="danger" :disabled="items.length <= 1" @click="$emit('remove', index)">删除</el-button>
          </el-space>
        </div>
        <el-button v-if="items.length < max" plain @click="$emit('add')">添加条目</el-button>
      </div>
        `,
  });

  const pageTypes = [
    { type: 1, label: '移动首页' },
    { type: 2, label: '个人中心' },
    { type: 3, label: '客服页面' },
    { type: 5, label: '系统风格' },
  ];
  const themeOptions = [1, 2, 3, 4, 5, 6, 7].map((value) => ({
    label: `主题 ${value}`,
    value,
  }));
  const textColorOptions = [
    { label: '白色', value: 'white' },
    { label: '黑色', value: 'black' },
  ];
  const binaryOptions = [
    { label: '样式 1', value: 1 },
    { label: '样式 2', value: 2 },
  ];
  const styleOptions = binaryOptions;
  const loading = ref(false);
  const submitLoading = ref(false);
  const articleOptions = ref<DecorationArticleOption[]>([]);
  const activeType = ref(1);
  const page = reactive<DecorationPage>({
    id: 0,
    type: 1,
    name: '',
    data: [],
    meta: [],
  });
  const theme = reactive<ThemeValue>({
    themeColorId: 3,
    topTextColor: 'white',
    navigationBarColor: '#A74BFD',
    themeColor1: '#A74BFD',
    themeColor2: '#CB60FF',
    buttonColor: 'white',
  });

  const components = computed<MutableComponent[]>(() =>
    Array.isArray(page.data) ? page.data : []
  );
  const metaContent = computed<MutableContent>(() => {
    const list = Array.isArray(page.meta) ? page.meta : [];
    const meta = list.find((item) => item.name === 'page-meta');
    if (meta) return meta.content;
    return {};
  });
  const hasMeta = computed(
    () =>
      Array.isArray(page.meta) &&
      page.meta.some((item) => item.name === 'page-meta')
  );

  const content = (component: MutableComponent) => component.content;
  const items = (component: MutableComponent) =>
    Array.isArray(component.content.data) ? component.content.data : [];
  const isFixed = (component: MutableComponent) =>
    ['search', 'news', 'user-info'].includes(component.name);
  const isMetaComponent = (component: MutableComponent) =>
    component.name === 'page-meta';
  const hasEnabled = (component: MutableComponent) =>
    ['banner', 'middle-banner', 'user-banner', 'nav', 'my-service'].includes(
      component.name
    );
  const ensureQueries = () => {
    components.value.forEach((component) => {
      items(component).forEach((item) => {
        if (item.link.target_type === 'mini_program' && !item.link.query)
          item.link.query = {};
      });
    });
  };
  const previewComponents = computed(() =>
    components.value.filter(
      (component) =>
        isFixed(component) || Number(component.content.enabled ?? 1) === 1
    )
  );
  const previewTitle = computed(() =>
    activeType.value === 5
      ? '系统风格'
      : String(metaContent.value.title || page.name || '页面预览')
  );
  const previewHeaderColor = computed(() =>
    activeType.value === 5
      ? theme.navigationBarColor
      : String(metaContent.value.bg_color || theme.navigationBarColor)
  );
  const previewBackgroundColor = computed(() =>
    activeType.value === 5
      ? theme.themeColor1
      : String(metaContent.value.bg_color || '#f5f7fa')
  );
  const previewBackgroundImage = computed(() => {
    const image = String(metaContent.value.bg_image || '');
    return image ? `url(${image})` : 'none';
  });
  const visibleItems = (component: MutableComponent) =>
    items(component).filter((item) => Number(item.is_show ?? 1) === 1);
  const bannerPreviewStyle = (component: MutableComponent) => {
    if (component.name !== 'banner') return {};
    const background = String(visibleItems(component)[0]?.bg || '');
    return background
      ? {
          backgroundImage: `url(${background})`,
          backgroundPosition: 'center',
          backgroundSize: 'cover',
        }
      : {};
  };

  const loadPageByType = async (type: number) => {
    activeType.value = type;
    loading.value = true;
    try {
      const { data: list } = await getMobileDecorationLists();
      const summary = list.find((item) => item.type === type) || list[0];
      if (!summary) {
        page.id = 0;
        return;
      }
      const { data } = await getMobileDecorationDetail(summary.id);
      Object.assign(page, data);
      ensureQueries();
      if (type === 5 && !Array.isArray(data.data))
        Object.assign(theme, data.data);
    } finally {
      loading.value = false;
    }
  };
  const loadArticleOptions = async () => {
    const { data } = await getDecorationArticleOptions();
    articleOptions.value = data;
  };
  loadPageByType(activeType.value);
  loadArticleOptions();

  const moveComponent = (index: number, offset: number) => {
    const list = components.value;
    const next = index + offset;
    if (next < 0 || next >= list.length) return;
    [list[index], list[next]] = [list[next], list[index]];
  };

  const setContentImage = (
    component: MutableComponent,
    key: string,
    urls: string[]
  ) => {
    component.content[key] = urls[0] || '';
  };
  const setItemImage = (item: DecorationItem, urls: string[]) => {
    item.image = urls[0] || '';
  };
  const setItemBackground = (item: DecorationItem, urls: string[]) => {
    item.bg = urls[0] || '';
  };
  const setMetaImage = (key: 'title_img' | 'bg_image', urls: string[]) => {
    metaContent.value[key] = urls[0] || '';
  };
  const newItem = (): DecorationItem => ({
    image: '',
    name: '',
    link: { target_type: 'shop', target: 'home' },
    is_show: 1,
  });
  const addItem = (component: MutableComponent) => {
    const list = items(component);
    const max =
      component.name === 'nav' || component.name === 'my-service' ? 100 : 5;
    if (list.length < max) list.push(newItem());
  };
  const removeItem = (component: MutableComponent, index: number) => {
    const list = items(component);
    if (list.length > 1) list.splice(index, 1);
  };
  const moveItem = (
    component: MutableComponent,
    index: number,
    offset: number
  ) => {
    const list = items(component);
    const next = index + offset;
    if (next < 0 || next >= list.length) return;
    [list[index], list[next]] = [list[next], list[index]];
  };

  const handleSubmit = async () => {
    if (!page.id) return;
    if ([1, 2].includes(activeType.value)) {
      const title = String(metaContent.value.title ?? '').trim();
      if (Array.from(title).length > 8) {
        ElMessage.error('页面标题最多 8 个字');
        return;
      }
    }
    submitLoading.value = true;
    try {
      const data = activeType.value === 5 ? { ...theme } : page.data;
      await saveMobileDecoration({
        id: page.id,
        type: activeType.value,
        data,
        meta: activeType.value === 5 ? [] : page.meta,
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
  .decoration-workbench {
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
    width: 320px;
    min-height: 610px;
    padding: 10px;
    overflow: hidden;
    border: 8px solid #1f2937;
    border-radius: 34px;
    background: #fff;
    box-shadow: 0 16px 38px rgb(15 23 42 / 16%);
  }
  .phone-header {
    padding: 14px 12px;
    border-radius: 18px 18px 0 0;
    color: #fff;
    font-weight: 600;
    text-align: center;
  }
  .phone-content {
    min-height: 540px;
    padding: 10px;
    background-position: center;
    background-size: cover;
  }
  .preview-component,
  .preview-block,
  .preview-service,
  .preview-news,
  .preview-user {
    margin-bottom: 9px;
  }
  .preview-search,
  .preview-block,
  .preview-service,
  .preview-news,
  .preview-user {
    padding: 10px;
    border-radius: 9px;
    background: rgb(255 255 255 / 92%);
  }
  .preview-search {
    color: #9ca3af;
  }
  .preview-banner {
    display: grid;
    height: 96px;
    place-items: center;
    overflow: hidden;
    border-radius: 9px;
    background: linear-gradient(135deg, #dbeafe, #ede9fe);
    color: #64748b;
  }
  .preview-banner img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .preview-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-top: 8px;
  }
  .preview-grid-item {
    display: flex;
    min-width: 0;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    text-align: center;
  }
  .preview-grid-item img,
  .preview-placeholder {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    object-fit: cover;
  }
  .preview-placeholder {
    display: grid;
    place-items: center;
    background: #eef2ff;
    color: #6366f1;
  }
  .preview-grid-item small {
    width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .preview-user {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .preview-avatar {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border-radius: 50%;
    background: #e0e7ff;
    color: #4f46e5;
    font-weight: 700;
  }
  .preview-news {
    display: flex;
    justify-content: space-between;
  }
  .preview-service {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .theme-preview-card {
    display: flex;
    min-height: 160px;
    padding: 22px;
    flex-direction: column;
    justify-content: center;
    gap: 8px;
    border-radius: 14px;
  }
  .component-card {
    margin: 14px 0;
  }
  .actions {
    margin-top: 18px;
  }
  .image-field {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .image-field img {
    width: 72px;
    height: 72px;
    object-fit: cover;
    border-radius: 4px;
  }
  .item-editor {
    display: grid;
    gap: 10px;
  }
  .item-row {
    display: grid;
    grid-template-columns:
      100px 72px minmax(120px, 1fr) 130px minmax(120px, 1fr)
      auto minmax(190px, auto);
    align-items: center;
    gap: 8px;
  }
  .item-row img {
    width: 68px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
  }
  .component-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  @media (max-width: 1200px) {
    .decoration-workbench {
      grid-template-columns: 1fr;
    }
    .preview-pane {
      position: static;
    }
  }
</style>
