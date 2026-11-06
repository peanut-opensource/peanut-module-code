<template>
  <div class="config-select">
    <el-select
      :model-value="modelValue"
      filterable
      remote
      clearable
      :remote-method="search"
      :loading="loading"
      :disabled="!available"
      placeholder="搜索当前获准且已启用的配置"
      @update:model-value="emit('update:modelValue', $event || undefined)"
    >
      <el-option
        v-if="modelValue && !options.some((item) => item.id === modelValue)"
        :value="modelValue"
        :label="'原引用 #' + modelValue + '（未在当前结果中）'"
        disabled
      />
      <el-option
        v-for="item in options"
        :key="item.id"
        :value="item.id"
        :label="item.title + ' #' + item.id + ' · 修订 ' + item.version"
        :disabled="!!capability && item.capability !== capability"
      >
        {{ item.title }} #{{ item.id }} · 修订 {{ item.version }}
        <small v-if="item.capability"> · {{ item.capability }}</small>
      </el-option>
    </el-select>
    <el-button
      link
      :loading="loading"
      :disabled="!available || loading"
      @click="load(false)"
    >
      {{ error ? '重试读取' : '刷新选项' }}
    </el-button>
    <el-button
      v-if="options.length < total"
      link
      :disabled="loading || !available"
      @click="load(true)"
    >
      继续读取（{{ options.length }}/{{ total }}）
    </el-button>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-else>
      仅列已启用配置；能力不匹配不可选。原引用不会自动替换，保存时服务端重新核权与校验。
    </p>
  </div>
</template>
<script setup lang="ts">
  import { onBeforeUnmount, onMounted, ref } from 'vue';
  import { registerTenantDisposer } from '@peanut-admin/vue';
  import { getSessionSnapshot } from '@/utils/auth';
  import { RequestLane } from './request-recovery';
  import { lookups, type Lookup } from './api';

  const props = defineProps<{
    modelValue?: number;
    kind: 'connection' | 'model' | 'template';
    capability?: string;
  }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: number | undefined): void;
  }>();
  const options = ref<Lookup[]>([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref('');
  const available = ref(true);
  const generation = getSessionSnapshot().generation;
  const lane = new RequestLane(() => getSessionSnapshot().generation);
  let query = '';
  let page = 0;
  function close() {
    lane.cancel();
    available.value = false;
    options.value = [];
    total.value = 0;
    loading.value = false;
    error.value = '会话已改变，请重新进入配置表单。';
  }
  const unregister = registerTenantDisposer(
    'custom.ai-assistant.selector:' + crypto.randomUUID(),
    close
  );
  async function load(more: boolean) {
    if (generation !== getSessionSnapshot().generation) close();
    if (!available.value) return;
    const ticket = lane.start();
    const next = more ? page + 1 : 1;
    loading.value = true;
    error.value = '';
    if (!more) {
      options.value = [];
      total.value = 0;
    }
    try {
      const result = await lookups(
        props.kind,
        'configure',
        ticket.signal,
        query,
        next
      );
      if (!lane.current(ticket) || !available.value) return;
      options.value = more
        ? [
            ...options.value,
            ...result.list.filter(
              (item) => !options.value.some((old) => old.id === item.id)
            ),
          ]
        : result.list;
      total.value = result.count;
      page = next;
    } catch (cause) {
      if (lane.current(ticket) && available.value)
        error.value = cause instanceof Error ? cause.message : '获准配置读取失败';
    } finally {
      if (lane.current(ticket) && available.value) loading.value = false;
    }
  }
  function search(value: string) {
    query = value;
    void load(false);
  }
  onMounted(() => load(false));
  onBeforeUnmount(() => {
    close();
    unregister();
  });
</script>
<style scoped>
  .config-select {
    width: 100%;
  }
  .el-select {
    width: 100%;
  }
  p {
    margin: 6px 0;
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
</style>
