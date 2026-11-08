<template>
  <section class="block" :aria-label="title">
    <h3 class="title">{{ title }}</h3>
    <p v-if="description" class="description">{{ description }}</p>
    <slot />
    <div v-for="option in options" :key="option.name" class="switch-wrapper">
      <span>{{ $t(option.name) }}</span>
      <form-wrapper
        :type="option.type || 'switch'"
        :name="option.key"
        :label="$t(option.name)"
        :default-value="option.defaultVal"
        @input-change="handleChange"
      />
    </div>
  </section>
</template>

<script lang="ts" setup>
  import { PropType } from 'vue';
  import { useAppStore } from '@/store';
  import FormWrapper from './form-wrapper.vue';

  interface OptionsProps {
    name: string;
    key: string;
    type?: string;
    defaultVal?: boolean | string | number;
  }
  defineProps({
    title: {
      type: String,
      default: '',
    },
    description: {
      type: String,
      default: '',
    },
    options: {
      type: Array as PropType<OptionsProps[]>,
      default() {
        return [];
      },
    },
  });
  const appStore = useAppStore();
  const handleChange = async ({
    key,
    value,
  }: {
    key: string;
    value: unknown;
  }) => {
    if (key === 'colorWeak') {
      document.body.style.filter = value ? 'invert(80%)' : 'none';
    }
    if (key === 'menuFromServer' && value) {
      await appStore.fetchServerMenuConfig();
    }
    appStore.updateSettings({ [key]: value });
  };
</script>

<style scoped lang="less">
  .block {
    padding-block: 24px;
    border-bottom: 1px solid var(--el-border-color-lighter);
    &:first-child {
      padding-top: 0;
    }
  }

  .title {
    margin: 0 0 16px;
    padding: 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }
  .description {
    margin: -8px 0 20px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    line-height: 20px;
  }

  .switch-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    min-height: 40px;
    padding-block: 4px;
    line-height: 22px;
  }
</style>
