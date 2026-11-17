<template>
  <div class="container">
    <el-card class="general-card" header="AI 调用测试">
      <el-form class="test-form" label-position="top" @submit.prevent="run">
        <el-form-item label="能力">
          <el-radio-group v-model="capability" :disabled="running">
            <el-radio-button value="text">文字生成</el-radio-button>
            <el-radio-button value="image">图片生成</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="提示词" required>
          <el-input
            v-model="prompt"
            type="textarea"
            :rows="5"
            :disabled="running"
          />
        </el-form-item>
        <el-collapse>
          <el-collapse-item title="指定服务商、模型或令牌" name="target">
            <el-form-item label="服务商标识">
              <el-input v-model.trim="provider" placeholder="留空自动选择" />
            </el-form-item>
            <el-form-item label="目录模型标识">
              <el-input v-model.trim="model" placeholder="留空使用默认模型" />
            </el-form-item>
            <el-form-item label="令牌标识">
              <el-input
                v-model.trim="credential"
                placeholder="留空轮询可用令牌"
              />
            </el-form-item>
          </el-collapse-item>
        </el-collapse>
        <div class="actions">
          <el-button
            type="primary"
            native-type="submit"
            :loading="running"
            :disabled="!prompt.trim()"
          >
            {{ capability === 'text' ? '生成文字' : '生成图片' }}
          </el-button>
          <el-button
            v-if="requestKey && !result"
            :loading="checking"
            :disabled="running"
            @click="check"
          >
            查询调用状态
          </el-button>
        </div>
      </el-form>
      <div
        v-if="result || status || requestKey"
        class="result"
        aria-live="polite"
      >
        <h3>结果</h3>
        <p v-if="result?.text" class="result-text">{{ result.text }}</p>
        <img
          v-if="result?.image"
          class="generated-image"
          :src="`data:${result.mime_type};base64,${result.image}`"
          alt="AI 生成图片"
        />
        <p v-if="result"
          >{{ result.request.provider_key }} / {{ result.request.model_key }} /
          {{ result.request.credential_key }}</p
        >
        <p v-if="status">请求状态 {{ status }}</p>
        <p v-if="requestKey">请求标识 {{ requestKey }}</p>
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import {
    generate,
    requestStatus,
    type Capability,
    type GenerationResult,
  } from '../api';

  const capability = ref<Capability>('text');
  const prompt = ref('');
  const provider = ref('');
  const model = ref('');
  const credential = ref('');
  const running = ref(false);
  const checking = ref(false);
  const result = ref<GenerationResult>();
  const requestKey = ref('');
  const status = ref('');
  const statusLabels: Record<string, string> = {
    sending: '发送中',
    succeeded: '成功',
    rejected: '服务商拒绝',
    unknown: '结果未确认',
  };

  async function run() {
    if (running.value || !prompt.value.trim()) return;
    running.value = true;
    result.value = undefined;
    status.value = '';
    requestKey.value = crypto.randomUUID();
    try {
      const { data } = await generate({
        capability: capability.value,
        prompt: prompt.value,
        request_key: requestKey.value,
        model: model.value || undefined,
        provider: provider.value || undefined,
        credential: credential.value || undefined,
      });
      result.value = data;
    } catch {
      status.value = '调用未返回结果';
    } finally {
      running.value = false;
    }
  }

  async function check() {
    checking.value = true;
    try {
      const { data } = await requestStatus(requestKey.value);
      status.value = statusLabels[data.status] ?? data.status;
    } finally {
      checking.value = false;
    }
  }
</script>

<style scoped>
  .test-form {
    max-width: 680px;
  }
  .actions {
    margin-top: 16px;
  }
  .result {
    margin-top: 24px;
    overflow-wrap: anywhere;
  }
  .result-text {
    white-space: pre-wrap;
  }
  .generated-image {
    display: block;
    width: min(100%, 480px);
    height: auto;
  }
</style>
