<template>
  <section v-if="canUse" class="ai-draft">
    <el-button :disabled="disabled" @click="show">AI 起草正文</el-button>
    <el-dialog v-model="visible" title="AI 起草正文" width="min(680px, 94vw)" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent>
        <el-form-item label="文本服务">
          <el-select v-model="routeId" :disabled="!!original || busy" placeholder="选择已启用的文本路由">
            <el-option v-for="r in routes" :key="r.id" :value="r.id" :label="r.title" />
          </el-select>
          <el-button v-if="more" link :disabled="loading || busy || !!original" @click="readRoutes">读取更多路由</el-button>
          <small v-if="!loading && !routes.length">当前没有获准的文本路由。请联系 AI 配置管理员。</small>
        </el-form-item>
        <el-form-item label="写作主题"><el-input v-model="topic" maxlength="2000" :disabled="!!original || busy" /></el-form-item>
        <el-form-item label="提纲或写作要求（可选）"><el-input v-model="outline" type="textarea" :rows="3" maxlength="4000" :disabled="!!original || busy" /></el-form-item>
        <el-checkbox v-model="confirmed" :disabled="!!original || busy">同意将本次主题和提纲发送给所选服务</el-checkbox>
      </el-form>
      <p v-if="error" role="alert">{{ error }}</p>
      <section v-if="preview"><h3>候选正文</h3><p class="candidate">{{ preview }}</p></section>
      <template #footer>
        <el-button v-if="original && !busy" @click="resetInput">{{ preview ? '重新起草' : '放弃原请求' }}</el-button>
        <el-button v-if="!preview" type="primary" :loading="busy" :disabled="disabled || !confirmed || !routeId || !topic.trim()" @click="generate">
          {{ original ? '核对原请求' : '生成候选正文' }}
        </el-button>
        <el-button v-else type="primary" :disabled="disabled || busy || adopted" @click="adopt">
          {{ adopted ? '已采用' : '追加到当前正文' }}
        </el-button>
      </template>
    </el-dialog>
  </section>
</template>
<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
  import { useUserStore } from '@/store';
  import { hasPermission } from '@/hooks/permission';
  import { registerTenantDisposer } from '@peanut-admin/vue';
  import { getSessionSnapshot } from '@/utils/auth';
  import randomUuid from '@/utils/random-uuid';
  import { generateText, textRoutes, type GenerateTextInput, type Lookup } from '../api';

  const props = defineProps<{ consumerModule: string; contextKey: string; disabled: boolean }>();
  const emit = defineEmits<{ (event: 'adopt', text: string, acknowledge: (applied: boolean) => void): void }>();
  const user = useUserStore();
  const canUse = computed(() => hasPermission('custom.ai-assistant.use', undefined, user.permissions));
  const visible = ref(false), busy = ref(false), loading = ref(false), confirmed = ref(false), adopted = ref(false);
  const topic = ref(''), outline = ref(''), preview = ref(''), error = ref('');
  const routes = ref<Lookup[]>([]), routeId = ref<number>(), more = ref(false);
  const original = shallowRef<GenerateTextInput>();
  let page = 0, controller = new AbortController();
  function resetInput() {
    original.value = undefined; preview.value = ''; error.value = ''; confirmed.value = false; adopted.value = false;
  }
  function clear() {
    controller.abort(); controller = new AbortController(); visible.value = false; busy.value = false; loading.value = false;
    page = 0; routes.value = []; routeId.value = undefined; more.value = false; topic.value = ''; outline.value = ''; resetInput();
  }
  watch(() => [props.contextKey, props.consumerModule, props.disabled, canUse.value], clear, { flush: 'sync' });
  const dispose = registerTenantDisposer('custom.ai-assistant.draft.' + randomUuid(), clear);
  onBeforeUnmount(() => { clear(); dispose(); });
  async function show() {
    if (props.disabled || !canUse.value) return;
    visible.value = true;
    if (!page && !loading.value) await readRoutes();
  }
  async function readRoutes() {
    if (loading.value || props.disabled || !canUse.value) return;
    const { signal } = controller;
    loading.value = true;
    try {
      const result = await textRoutes(props.consumerModule, page + 1, signal);
      if (signal.aborted) return;
      page++;
      routes.value.push(...result.list.filter(r => r.capability === 'text' && r.consumer_module === props.consumerModule));
      more.value = page * result.page_size < result.count;
      if (!routeId.value && routes.value.length === 1) routeId.value = routes.value[0].id;
    } catch (cause) {
      if (!signal.aborted) error.value = cause instanceof Error ? cause.message : '文本路由读取失败';
    } finally { if (!signal.aborted) loading.value = false; }
  }
  async function generate() {
    if (!canUse.value || props.disabled || busy.value || !routeId.value || !topic.value.trim() || !confirmed.value) return;
    const { signal } = controller, generation = getSessionSnapshot().generation;
    const request = original.value ?? { route_id: routeId.value, consumer_module: props.consumerModule,
      topic: topic.value.trim(), outline: outline.value.trim(), request_id: randomUuid(), confirm_send: true as const };
    original.value = request; busy.value = true; error.value = '';
    try {
      const result = await generateText(request, signal);
      if (!signal.aborted && generation === getSessionSnapshot().generation && canUse.value && !props.disabled) preview.value = result.text;
    } catch (cause) {
      if (!signal.aborted) error.value = cause instanceof Error ? cause.message : '尚未取得候选，请核对原请求';
    } finally { if (!signal.aborted) busy.value = false; }
  }
  function adopt() {
    if (!canUse.value || !preview.value || props.disabled || adopted.value || busy.value) return;
    emit('adopt', preview.value, applied => {
      if (applied) { adopted.value = true; visible.value = false; }
      else error.value = '编辑状态已改变，正文尚未追加。请核对当前稿件。';
    });
  }
</script>
<style scoped>
  .ai-draft { margin-block: 0.5rem; }
  .candidate { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 40vh; overflow: auto; line-height: 1.7; }
  small { display: block; }
</style>
