<template>
  <div v-loading="loading" class="channel-panel">
    <el-alert v-if="!canView" type="warning" :closable="false">
      {{ $t('channel.officialAccount.permissionDenied') }}
    </el-alert>
    <template v-else>
      <el-alert
        type="info"
        show-icon
        :closable="false"
        style="margin-top: 16px"
      >
        {{ $t('channel.menu.ruleNotice') }}
      </el-alert>
      <el-space style="margin: 16px 0">
        <el-button
          v-permission="['peanut.oauth.official-account.menu.save']"
          type="primary"
          :loading="saving"
          @click="save(false)"
        >
          {{ $t('channel.menu.save') }}
        </el-button>
        <el-button
          v-permission="['peanut.oauth.official-account.menu.publish']"
          :loading="publishing"
          @click="save(true)"
        >
          {{ $t('channel.menu.publish') }}
        </el-button>
        <el-button
          v-permission="['peanut.oauth.official-account.menu.save']"
          :disabled="menu.length >= 3"
          @click="addTopMenu"
        >
          {{ $t('channel.menu.addTop') }}
        </el-button>
      </el-space>

      <el-empty
        v-if="menu.length === 0"
        :description="$t('channel.menu.empty')"
      />
      <el-space v-else direction="vertical" fill :size="16">
        <el-card
          v-for="(item, index) in menu"
          :key="index"
          :header="topTitle(index)"
        >
          <el-form :model="item" label-position="top">
            <el-form-item :label="$t('channel.menu.nameTop')">
              <el-input
                v-model="item.name"
                :maxlength="4"
                show-word-limit
                :placeholder="$t('channel.menu.namePlaceholder')"
              />
            </el-form-item>
            <template v-if="hasChildren(item)">
              <el-divider content-position="left">
                {{ $t('channel.menu.children') }}
              </el-divider>
              <el-space direction="vertical" fill :size="12">
                <el-card
                  v-for="(child, childIndex) in item.sub_button"
                  :key="childIndex"
                  size="small"
                  :header="childTitle(childIndex)"
                >
                  <el-form :model="child" label-position="top">
                    <el-form-item :label="$t('channel.menu.nameChild')">
                      <el-input
                        v-model="child.name"
                        :maxlength="8"
                        show-word-limit
                        :placeholder="$t('channel.menu.namePlaceholder')"
                      />
                    </el-form-item>
                    <MenuLeafFields
                      :model-value="child"
                      @update:model-value="
                        (value) => updateChild(item, childIndex, value)
                      "
                    />
                    <el-button
                      v-permission="[
                        'peanut.oauth.official-account.menu.save',
                      ]"
                      type="danger"
                      link
                      @click="removeChild(item, childIndex)"
                    >
                      {{ $t('channel.menu.removeChild') }}
                    </el-button>
                  </el-form>
                </el-card>
              </el-space>
              <el-button
                v-permission="['peanut.oauth.official-account.menu.save']"
                style="margin-top: 12px"
                :disabled="(item.sub_button?.length || 0) >= 5"
                @click="addChild(item)"
              >
                {{ $t('channel.menu.addChild') }}
              </el-button>
            </template>
            <MenuLeafFields
              v-else
              :model-value="item"
              @update:model-value="(value) => updateTop(index, value)"
            />
            <el-space style="margin-top: 12px">
              <el-button
                v-if="!hasChildren(item)"
                v-permission="['peanut.oauth.official-account.menu.save']"
                @click="makeGroup(item)"
              >
                {{ $t('channel.menu.makeGroup') }}
              </el-button>
              <el-button
                v-if="hasChildren(item)"
                v-permission="['peanut.oauth.official-account.menu.save']"
                @click="makeLeaf(item)"
              >
                {{ $t('channel.menu.makeLeaf') }}
              </el-button>
              <el-popconfirm
                :title="$t('channel.menu.removeTopConfirm')"
                @confirm="removeTopMenu(index)"
              >
                <template #reference
                  ><el-button
                    v-permission="['peanut.oauth.official-account.menu.save']"
                    type="danger"
                    link
                  >
                    {{ $t('channel.menu.removeTop') }}
                  </el-button></template
                >
              </el-popconfirm>
            </el-space>
          </el-form>
        </el-card>
      </el-space>
    </template>
  </div>
</template>

<script lang="ts" setup>
  import { computed, onMounted, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import { hasPermission } from '@/hooks/permission';
  import {
    getOfficialAccountMenu,
    publishOfficialAccountMenu,
    saveOfficialAccountMenu,
    type OfficialAccountMenuItem,
  } from '@/modules/peanut-oauth/api';
  import MenuLeafFields from './components/MenuLeafFields.vue';

  const { t } = useI18n();
  const canView = computed(() =>
    hasPermission('peanut.oauth.official-account.menu.detail')
  );
  const loading = ref(false);
  const saving = ref(false);
  const publishing = ref(false);
  const menu = ref<OfficialAccountMenuItem[]>([]);

  const newLeaf = (): OfficialAccountMenuItem => ({
    name: '',
    type: 'click',
    key: '',
    url: '',
    appid: '',
    pagepath: '',
  });

  const normaliseItem = (
    item: OfficialAccountMenuItem
  ): OfficialAccountMenuItem => {
    const children = Array.isArray(item.sub_button)
      ? item.sub_button.map(normaliseItem)
      : [];
    if (children.length > 0) {
      return { name: String(item.name || ''), sub_button: children };
    }
    return {
      ...newLeaf(),
      ...item,
      sub_button: [],
    };
  };

  const fetchData = async () => {
    if (!canView.value) return;
    loading.value = true;
    try {
      const { data } = await getOfficialAccountMenu();
      menu.value = Array.isArray(data.menu) ? data.menu.map(normaliseItem) : [];
    } finally {
      loading.value = false;
    }
  };

  onMounted(fetchData);

  const hasChildren = (item: OfficialAccountMenuItem) =>
    Array.isArray(item.sub_button) && item.sub_button.length > 0;

  const addTopMenu = () => {
    if (menu.value.length >= 3) {
      ElMessage.warning(t('channel.menu.topLimit'));
      return;
    }
    menu.value.push(newLeaf());
  };

  const addChild = (parent: OfficialAccountMenuItem) => {
    if (!Array.isArray(parent.sub_button)) parent.sub_button = [];
    if (parent.sub_button.length >= 5) {
      ElMessage.warning(t('channel.menu.childLimit'));
      return;
    }
    parent.sub_button.push(newLeaf());
  };

  const removeChild = (parent: OfficialAccountMenuItem, index: number) => {
    parent.sub_button?.splice(index, 1);
    if (parent.sub_button?.length === 0) makeLeaf(parent);
  };

  const updateChild = (
    parent: OfficialAccountMenuItem,
    index: number,
    value: OfficialAccountMenuItem
  ) => {
    if (parent.sub_button) parent.sub_button[index] = value;
  };

  const updateTop = (index: number, value: OfficialAccountMenuItem) => {
    menu.value[index] = value;
  };

  const removeTopMenu = (index: number) => {
    menu.value.splice(index, 1);
  };

  const makeGroup = (item: OfficialAccountMenuItem) => {
    delete item.type;
    delete item.key;
    delete item.url;
    delete item.appid;
    delete item.pagepath;
    item.sub_button = [];
    addChild(item);
  };

  const makeLeaf = (item: OfficialAccountMenuItem) => {
    item.sub_button = [];
    Object.assign(item, newLeaf(), { name: item.name });
  };

  const topTitle = (index: number) =>
    `${t('channel.menu.topTitle')} ${index + 1}`;
  const childTitle = (index: number) =>
    `${t('channel.menu.childTitle')} ${index + 1}`;

  const absoluteHttpUrl = (value: string) => {
    try {
      const url = new URL(value.trim());
      return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
      return false;
    }
  };

  const validateLeaf = (item: OfficialAccountMenuItem) => {
    const { type } = item;
    if (!type || !['click', 'view', 'miniprogram'].includes(type)) {
      return t('channel.menu.invalidType');
    }
    if (type === 'click' && !String(item.key || '').trim()) {
      return t('channel.menu.clickKeyRequired');
    }
    if (type === 'view' && !absoluteHttpUrl(String(item.url || ''))) {
      return t('channel.menu.viewUrlRequired');
    }
    if (
      type === 'miniprogram' &&
      (!absoluteHttpUrl(String(item.url || '')) ||
        !String(item.appid || '').trim() ||
        !String(item.pagepath || '').trim())
    ) {
      return t('channel.menu.miniprogramRequired');
    }
    return '';
  };

  const toPayload = (
    item: OfficialAccountMenuItem
  ): OfficialAccountMenuItem => {
    const name = String(item.name || '').trim();
    if (hasChildren(item)) {
      return {
        name,
        sub_button: (item.sub_button || []).map(toPayload),
      };
    }
    const payload: OfficialAccountMenuItem = { name, type: item.type };
    if (item.type === 'click') payload.key = String(item.key || '').trim();
    if (item.type === 'view') payload.url = String(item.url || '').trim();
    if (item.type === 'miniprogram') {
      payload.url = String(item.url || '').trim();
      payload.appid = String(item.appid || '').trim();
      payload.pagepath = String(item.pagepath || '').trim();
    }
    return payload;
  };

  const validateMenu = () => {
    if (menu.value.length > 3) return t('channel.menu.topLimit');
    const invalid = menu.value.some((top) => {
      const name = String(top.name || '').trim();
      if (!name || Array.from(name).length > 4) return true;
      if (hasChildren(top)) {
        if ((top.sub_button || []).length > 5) return true;
        return (top.sub_button || []).some((child) => {
          const childName = String(child.name || '').trim();
          if (!childName || Array.from(childName).length > 8) return true;
          const error = validateLeaf(child);
          return Boolean(error);
        });
      }
      const error = validateLeaf(top);
      return Boolean(error);
    });
    if (!invalid) return '';
    const topNameInvalid = menu.value.some((top) => {
      const name = String(top.name || '').trim();
      return !name || Array.from(name).length > 4;
    });
    if (topNameInvalid) return t('channel.menu.topNameInvalid');
    const childLimit = menu.value.some(
      (top) => hasChildren(top) && (top.sub_button || []).length > 5
    );
    if (childLimit) return t('channel.menu.childLimit');
    const childNameInvalid = menu.value.some((top) =>
      (top.sub_button || []).some((child) => {
        const name = String(child.name || '').trim();
        return !name || Array.from(name).length > 8;
      })
    );
    if (childNameInvalid) return t('channel.menu.childNameInvalid');
    const leafError = menu.value
      .flatMap((top) => (hasChildren(top) ? top.sub_button || [] : [top]))
      .map((item) => validateLeaf(item))
      .find(Boolean);
    return leafError || t('channel.menu.invalidType');
  };

  const save = async (publish: boolean) => {
    const validationError = validateMenu();
    if (validationError) {
      ElMessage.error(validationError);
      return;
    }
    const payload = menu.value.map(toPayload);
    if (publish) publishing.value = true;
    else saving.value = true;
    try {
      if (publish) await publishOfficialAccountMenu(payload);
      else await saveOfficialAccountMenu(payload);
      ElMessage.success(
        t(publish ? 'channel.menu.publishSuccess' : 'channel.tip.success')
      );
      await fetchData();
    } finally {
      publishing.value = false;
      saving.value = false;
    }
  };
</script>

<style scoped>
  .channel-panel {
    min-height: 180px;
  }
</style>

<script lang="ts">
  export default { name: 'OfficialAccountMenu' };
</script>
