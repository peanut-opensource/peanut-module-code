<template>
  <div class="container">
    <el-card v-loading="loading" class="general-card" :header="labels[kind]">
      <el-alert
        v-if="!canConfigure"
        title="当前账号只有查看权限，不能保存配置。"
        type="info"
        :closable="false"
        show-icon
        class="notice"
      />
      <template v-if="kind === 'provider'">
        <div class="toolbar"
          ><el-button
            type="primary"
            :disabled="!canConfigure"
            @click="open('provider')"
            >新增服务商</el-button
          ></div
        >
        <el-table :data="providers" row-key="key" empty-text="暂无服务商">
          <el-table-column prop="name" label="名称" min-width="150" />
          <el-table-column prop="key" label="标识" min-width="150" />
          <el-table-column prop="origin" label="来源" min-width="180" />
          <el-table-column prop="api_prefix" label="API 前缀" min-width="180" />
          <el-table-column prop="priority" label="优先级" width="100" />
          <el-table-column label="启用" width="100">
            <template #default="{ row }"
              ><el-switch
                v-model="row.enabled"
                :disabled="!canConfigure || saving"
                @change="saveRow('provider', row)"
            /></template>
          </el-table-column>
          <el-table-column label="操作" width="90">
            <template #default="{ row }"
              ><el-button
                link
                type="primary"
                :disabled="!canConfigure"
                @click="open('provider', row)"
                >编辑</el-button
              ></template
            >
          </el-table-column>
        </el-table>
      </template>

      <template v-else-if="kind === 'credential'">
        <div class="toolbar"
          ><el-button
            type="primary"
            :disabled="!canConfigure"
            @click="open('credential')"
            >新增令牌</el-button
          ></div
        >
        <el-table
          :data="credentials"
          :row-key="rowIdentity"
          empty-text="暂无令牌"
        >
          <el-table-column
            prop="name"
            label="名称"
            min-width="160"
          /><el-table-column
            prop="key"
            label="标识"
            min-width="150"
          /><el-table-column prop="provider" label="服务商" min-width="130" />
          <el-table-column label="模型范围" min-width="180"
            ><template #default="{ row }">{{
              row.models.join(', ') || '仅发现'
            }}</template></el-table-column
          >
          <el-table-column label="状态" width="110"
            ><template #default="{ row }"
              ><el-tag :type="row.configured ? 'success' : 'warning'">{{
                row.configured ? '已配置' : '未配置'
              }}</el-tag></template
            ></el-table-column
          >
          <el-table-column label="启用" width="90"
            ><template #default="{ row }"
              ><el-switch
                v-model="row.enabled"
                :disabled="!canConfigure || saving"
                @change="saveRow('credential', row)" /></template
          ></el-table-column>
          <el-table-column label="操作" width="90"
            ><template #default="{ row }"
              ><el-button
                link
                type="primary"
                :disabled="!canConfigure"
                @click="open('credential', row)"
                >编辑</el-button
              ></template
            ></el-table-column
          >
        </el-table>
      </template>

      <template v-else>
        <div class="toolbar model-toolbar">
          <el-button :disabled="!canConfigure" @click="open('author')"
            >新增模型厂商</el-button
          >
          <el-button :disabled="!canConfigure" @click="open('definition')"
            >新增目录模型</el-button
          >
          <el-button :disabled="!canConfigure" @click="discoveryOpen = true"
            >发现远端模型</el-button
          >
          <el-button
            type="primary"
            :disabled="!canConfigure"
            @click="open('model')"
            >新增模型接入</el-button
          >
        </div>
        <h3>模型目录</h3>
        <el-table :data="definitions" row-key="key" empty-text="暂无目录模型">
          <el-table-column prop="name" label="模型" min-width="150" />
          <el-table-column prop="key" label="标识" min-width="140" />
          <el-table-column label="厂商" min-width="140">
            <template #default="{ row }">{{
              authors.find((item) => item.key === row.author)?.name ??
              row.author
            }}</template>
          </el-table-column>
          <el-table-column label="能力" min-width="140">
            <template #default="{ row }">{{
              row.capabilities
                .map((item: Capability) => capabilityLabels[item])
                .join('、')
            }}</template>
          </el-table-column>
          <el-table-column label="操作" width="90">
            <template #default="{ row }">
              <el-button
                link
                type="primary"
                :disabled="!canConfigure"
                @click="open('definition', row)"
                >编辑</el-button
              >
            </template>
          </el-table-column>
        </el-table>
        <h3>默认模型</h3>
        <el-form inline>
          <el-form-item
            v-for="capability in capabilities"
            :key="capability"
            :label="capabilityLabels[capability]"
          >
            <el-select
              :model-value="defaultValues[capability]"
              :disabled="!canConfigure || saving"
              placeholder="按优先级自动选择"
              clearable
              style="width: 250px"
              @change="setDefault(capability, $event)"
            >
              <el-option
                v-for="item in models.filter(
                  (model) => model.enabled && model.protocols[capability]
                )"
                :key="rowIdentity(item)"
                :value="rowIdentity(item)"
                :label="`${item.name} · ${item.provider}`"
              />
            </el-select>
          </el-form-item>
        </el-form>
        <h3>模型接入</h3>
        <el-table :data="models" :row-key="rowIdentity" empty-text="暂无模型">
          <el-table-column
            prop="name"
            label="名称"
            min-width="150"
          /><el-table-column
            prop="key"
            label="标识"
            min-width="140"
          /><el-table-column
            prop="provider"
            label="服务商"
            min-width="120"
          /><el-table-column
            prop="remote_model"
            label="远端模型"
            min-width="150"
          />
          <el-table-column prop="definition" label="目录模型" min-width="130" />
          <el-table-column label="协议" min-width="150"
            ><template #default="{ row }">{{
              Object.values(row.protocols).join(', ')
            }}</template></el-table-column
          >
          <el-table-column
            prop="priority"
            label="优先级"
            width="90"
          /><el-table-column label="启用" width="90"
            ><template #default="{ row }"
              ><el-switch
                v-model="row.enabled"
                :disabled="!canConfigure || saving"
                @change="saveRow('model', row)" /></template
          ></el-table-column>
          <el-table-column label="操作" width="90"
            ><template #default="{ row }"
              ><el-button
                link
                type="primary"
                :disabled="!canConfigure"
                @click="open('model', row)"
                >编辑</el-button
              ></template
            ></el-table-column
          >
        </el-table>
      </template>
    </el-card>

    <el-dialog
      v-model="discoveryOpen"
      title="发现远端模型"
      width="min(640px, 95vw)"
    >
      <el-form label-position="top">
        <el-form-item label="服务商">
          <el-select
            v-model="discoveryProvider"
            @change="
              discoveryCredential = '';
              remoteModels = [];
            "
          >
            <el-option
              v-for="item in providers.filter((item) => item.enabled)"
              :key="item.key"
              :label="item.name"
              :value="item.key"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="令牌">
          <el-select v-model="discoveryCredential" @change="remoteModels = []">
            <el-option
              v-for="item in credentials.filter(
                (item) => item.provider === discoveryProvider && item.enabled
              )"
              :key="item.key"
              :label="item.name"
              :value="item.key"
            />
          </el-select>
        </el-form-item>
        <el-button
          type="primary"
          :loading="discovering"
          :disabled="!discoveryCredential"
          @click="discover"
          >读取模型列表</el-button
        >
      </el-form>
      <el-table
        v-if="remoteModels.length"
        :data="remoteModels"
        row-key="id"
        max-height="320"
      >
        <el-table-column prop="id" label="远端模型标识" />
        <el-table-column prop="owner" label="声明的归属" />
        <el-table-column label="操作" width="90">
          <template #default="{ row }">
            <el-button link type="primary" @click="addRemote(row)"
              >登记</el-button
            >
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <el-drawer
      v-model="drawerOpen"
      @closed="form.secret = ''"
      :title="drawerTitle"
      size="460px"
      destroy-on-close
    >
      <el-form :model="form" label-position="top" @submit.prevent="submit">
        <el-form-item label="标识" required
          ><el-input v-model="form.key" :disabled="!isNew"
        /></el-form-item>
        <el-form-item label="名称" required
          ><el-input v-model="form.name"
        /></el-form-item>
        <el-form-item
          v-if="form.kind === 'model' || form.kind === 'credential'"
          label="服务商"
          required
          ><el-select v-model="form.provider" :disabled="!isNew"
            ><el-option
              v-for="item in providers"
              :key="item.key"
              :label="item.name"
              :value="item.key" /></el-select
        ></el-form-item>
        <template v-if="form.kind === 'provider'"
          ><el-form-item label="来源"
            ><el-input v-model="form.origin" :disabled="!isNew" /></el-form-item
          ><el-form-item label="API 前缀"
            ><el-input
              v-model="form.api_prefix"
              :disabled="!isNew" /></el-form-item
          ><el-form-item label="优先级"
            ><el-input-number v-model="form.priority" :min="0" /></el-form-item
        ></template>
        <template v-else-if="form.kind === 'credential'"
          ><el-form-item label="模型范围"
            ><el-input
              v-model="form.modelsText"
              placeholder="接入标识逗号分隔，* 表示全部，留空仅用于发现" /></el-form-item
          ><el-form-item label="令牌"
            ><el-input
              v-model="form.secret"
              type="password"
              autocomplete="off"
              :placeholder="
                form.configured ? '已配置，留空不变' : '请输入令牌'
              " /></el-form-item
        ></template>
        <template v-else-if="form.kind === 'definition'">
          <el-form-item label="模型厂商" required>
            <el-select v-model="form.author">
              <el-option
                v-for="item in authors"
                :key="item.key"
                :label="item.name"
                :value="item.key"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="支持能力" required>
            <el-checkbox-group v-model="form.capabilities">
              <el-checkbox
                v-for="item in capabilities"
                :key="item"
                :value="item"
                >{{ capabilityLabels[item] }}</el-checkbox
              >
            </el-checkbox-group>
          </el-form-item>
        </template>
        <template v-else-if="form.kind === 'model'">
          <el-form-item label="目录模型" required>
            <el-select v-model="form.definition">
              <el-option
                v-for="item in definitions"
                :key="item.key"
                :label="item.name"
                :value="item.key"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="远端模型"
            ><el-input v-model="form.remote_model" /></el-form-item
          ><el-form-item label="文本协议"
            ><el-select v-model="form.textProtocol" clearable
              ><el-option label="OpenAI Chat Completions" value="openai_text" />
              <el-option
                label="OpenAI Responses"
                value="openai_responses" /><el-option
                label="Anthropic Messages"
                value="anthropic_messages" /></el-select></el-form-item
          ><el-form-item label="图片协议"
            ><el-select v-model="form.imageProtocol" clearable
              ><el-option
                label="OpenAI Image"
                value="openai_image" /></el-select></el-form-item
          ><el-form-item label="优先级"
            ><el-input-number v-model="form.priority" :min="0" /></el-form-item
          ><el-form-item label="输入字符上限"
            ><el-input-number
              v-model="form.input_limit_chars"
              :min="1" /></el-form-item
          ><el-form-item label="最大输出 Token"
            ><el-input-number
              v-model="form.max_output_tokens"
              :min="1" /></el-form-item
        ></template>
        <el-form-item label="启用"
          ><el-switch v-model="form.enabled"
        /></el-form-item>
        <el-button
          type="primary"
          :loading="saving"
          :disabled="!canConfigure"
          @click="submit"
          >保存</el-button
        >
      </el-form>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
  import { computed, onMounted, reactive, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import {
    discoverModels,
    getCatalog,
    saveConfig,
    type AiModel,
    type Author,
    type Capability,
    type Definition,
    type ModelDefault,
    type RemoteModel,
    type Credential,
    type Provider,
    type SavePayload,
  } from '../api';

  type Kind = Exclude<SavePayload['kind'], 'default'>;
  type Row = Provider | Credential | AiModel | Author | Definition;
  defineProps<{ kind: Kind }>();

  const loading = ref(false);
  const saving = ref(false);
  const drawerOpen = ref(false);
  const isNew = ref(false);
  const canConfigure = ref(false);
  const providers = ref<Provider[]>([]);
  const credentials = ref<Credential[]>([]);
  const models = ref<AiModel[]>([]);
  const authors = ref<Author[]>([]);
  const definitions = ref<Definition[]>([]);
  const defaults = ref<ModelDefault[]>([]);
  const capabilities: Capability[] = ['text', 'image'];
  const capabilityLabels = { text: '文字生成', image: '图片生成' };
  const discoveryOpen = ref(false);
  const discovering = ref(false);
  const discoveryProvider = ref('');
  const discoveryCredential = ref('');
  const remoteModels = ref<RemoteModel[]>([]);
  const defaultValues = computed(() => {
    const values: Record<Capability, string> = { text: '', image: '' };
    for (const item of defaults.value) {
      if (item.enabled) values[item.key] = `${item.provider}:${item.offering}`;
    }
    return values;
  });

  function values(kind: Kind, row?: Row) {
    const endpoint = row && 'origin' in row ? row : undefined;
    const token = row && 'configured' in row ? row : undefined;
    const model = row && 'protocols' in row ? row : undefined;
    const definition = row && 'capabilities' in row ? row : undefined;
    return {
      kind,
      author: definition?.author ?? '',
      capabilities: [...(definition?.capabilities ?? [])],
      definition: model?.definition ?? '',
      key: row?.key ?? '',
      name: row?.name ?? '',
      enabled: row?.enabled ?? true,
      provider: row && 'provider' in row ? row.provider : '',
      origin: endpoint?.origin ?? '',
      api_prefix: endpoint?.api_prefix ?? '/v1',
      priority: endpoint?.priority ?? model?.priority ?? 0,
      modelsText: token?.models.join(', ') ?? '',
      secret: '',
      configured: token?.configured ?? false,
      remote_model: model?.remote_model ?? '',
      textProtocol: model?.protocols.text ?? '',
      imageProtocol: model?.protocols.image ?? '',
      input_limit_chars: model?.input_limit_chars ?? 4000,
      max_output_tokens: model?.max_output_tokens ?? 1024,
    };
  }

  const form = reactive(values('provider'));
  const labels = {
    provider: '服务商',
    credential: '令牌',
    model: '模型接入',
    author: '模型厂商',
    definition: '目录模型',
  };
  const drawerTitle = computed(
    () => (isNew.value ? '新增' : '编辑') + labels[form.kind]
  );
  const rowIdentity = (row: Credential | AiModel) =>
    [row.provider, row.key].join(':');

  async function load() {
    loading.value = true;
    try {
      const { data } = await getCatalog();
      providers.value = data.providers;
      credentials.value = data.credentials;
      models.value = data.models;
      authors.value = data.authors;
      definitions.value = data.definitions;
      defaults.value = data.defaults;
      canConfigure.value = data.can_configure;
    } finally {
      loading.value = false;
    }
  }

  function open(kind: Kind, row?: Row) {
    Object.assign(form, values(kind, row));
    isNew.value = !row;
    drawerOpen.value = true;
  }

  function payload(input: ReturnType<typeof values>): SavePayload {
    const { kind, key, name, enabled, provider, priority } = input;
    const base = { kind, key, name, enabled };
    if (kind === 'author') return base;
    if (kind === 'definition')
      return {
        ...base,
        author: input.author,
        capabilities: input.capabilities,
      };
    if (kind === 'provider')
      return {
        ...base,
        priority,
        origin: input.origin,
        api_prefix: input.api_prefix,
      };
    if (kind === 'credential') {
      return {
        ...base,
        provider,
        models: input.modelsText
          .split(',')
          .map((key) => key.trim())
          .filter(Boolean),
        ...(input.secret ? { secret: input.secret } : {}),
      };
    }
    const protocols: Record<string, string> = {};
    if (input.textProtocol) protocols.text = input.textProtocol;
    if (input.imageProtocol) protocols.image = input.imageProtocol;
    return {
      ...base,
      provider,
      priority,
      protocols,
      definition: input.definition,
      remote_model: input.remote_model,
      input_limit_chars: input.input_limit_chars,
      max_output_tokens: input.max_output_tokens,
    };
  }

  async function submit() {
    saving.value = true;
    try {
      await saveConfig(payload(form));
      form.secret = '';
      drawerOpen.value = false;
      ElMessage.success('保存成功');
      await load();
    } finally {
      saving.value = false;
    }
  }

  async function saveRow(kind: Kind, row: Row) {
    saving.value = true;
    try {
      await saveConfig(payload(values(kind, row)));
      ElMessage.success('保存成功');
    } finally {
      saving.value = false;
      await load();
    }
  }

  async function discover() {
    discovering.value = true;
    try {
      const { data } = await discoverModels(
        discoveryProvider.value,
        discoveryCredential.value
      );
      remoteModels.value = data.items;
      if (!data.items.length) ElMessage.info('服务商没有返回模型');
    } finally {
      discovering.value = false;
    }
  }

  function addRemote(item: RemoteModel) {
    discoveryOpen.value = false;
    open('model');
    form.provider = discoveryProvider.value;
    form.remote_model = item.id;
    form.name = item.id;
    form.definition =
      definitions.value.find((candidate) => candidate.key === item.id)?.key ??
      '';
  }

  async function setDefault(capability: Capability, value?: string) {
    const previous = defaults.value.find((item) => item.key === capability);
    if (!value && !previous) return;
    const [provider, offering] = value
      ? value.split(':')
      : [previous?.provider, previous?.offering];
    saving.value = true;
    try {
      await saveConfig({
        kind: 'default',
        key: capability,
        name: capabilityLabels[capability],
        enabled: Boolean(value),
        provider,
        offering,
      });
      await load();
    } finally {
      saving.value = false;
    }
  }

  onMounted(load);
</script>

<style scoped>
  .model-toolbar {
    flex-wrap: wrap;
    gap: 8px;
  }
  .notice {
    margin-bottom: 16px;
  }
  .toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
  }
</style>
