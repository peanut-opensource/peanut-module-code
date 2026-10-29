<template>
  <el-form-item :label="$t('channel.menu.type')">
    <el-select v-model="type">
      <el-option :label="$t('channel.menu.typeClick')" value="click" />
      <el-option :label="$t('channel.menu.typeView')" value="view" />
      <el-option
        :label="$t('channel.menu.typeMiniprogram')"
        value="miniprogram"
      />
    </el-select>
  </el-form-item>
  <el-form-item v-if="type === 'click'" :label="$t('channel.menu.key')">
    <el-input v-model="keyValue" :maxlength="128" />
  </el-form-item>
  <el-form-item v-if="type === 'view'" :label="$t('channel.menu.url')">
    <el-input v-model="url" :maxlength="1024" />
  </el-form-item>
  <template v-if="type === 'miniprogram'">
    <el-form-item :label="$t('channel.menu.fallbackUrl')">
      <el-input v-model="url" :maxlength="1024" />
    </el-form-item>
    <el-form-item :label="$t('channel.menu.appId')">
      <el-input v-model="appid" :maxlength="128" />
    </el-form-item>
    <el-form-item :label="$t('channel.menu.pagePath')">
      <el-input v-model="pagepath" :maxlength="512" />
    </el-form-item>
  </template>
</template>

<script lang="ts" setup>
  import { computed } from 'vue';
  import type {
    OfficialAccountMenuItem,
    OfficialAccountMenuType,
  } from '@/modules/peanut-oauth/api';

  const props = defineProps<{ modelValue: OfficialAccountMenuItem }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: OfficialAccountMenuItem): void;
  }>();

  const update = <K extends keyof OfficialAccountMenuItem>(
    key: K,
    value: OfficialAccountMenuItem[K]
  ) => {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
  };

  const type = computed<OfficialAccountMenuType>({
    get: () => props.modelValue.type || 'click',
    set: (value) => update('type', value),
  });
  const keyValue = computed({
    get: () => props.modelValue.key || '',
    set: (value: string) => update('key', value),
  });
  const url = computed({
    get: () => props.modelValue.url || '',
    set: (value: string) => update('url', value),
  });
  const appid = computed({
    get: () => props.modelValue.appid || '',
    set: (value: string) => update('appid', value),
  });
  const pagepath = computed({
    get: () => props.modelValue.pagepath || '',
    set: (value: string) => update('pagepath', value),
  });
</script>

<script lang="ts">
  export default { name: 'MenuLeafFields' };
</script>
