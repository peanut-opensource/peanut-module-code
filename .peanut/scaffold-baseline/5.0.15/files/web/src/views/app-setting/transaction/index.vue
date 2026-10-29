<template>
  <div class="container">
    <el-card shadow="never">
      <el-form
        :model="form"
        label-position="top"
        style="max-width: 560px"
        @submit.prevent="handleSave"
      >
        <!-- 自动取消未付款订单 -->
        <el-form-item>
          <template #label>
            <span>{{ $t('transaction.cancelUnpaid') }}</span>
          </template>
          <div style="display: flex; align-items: center; gap: 12px">
            <el-switch
              v-model="form.cancel_unpaid_orders"
              :active-value="1"
              :inactive-value="0"
            />
            <el-text type="info" size="small">
              {{ $t('transaction.cancelUnpaid.enable') }}
            </el-text>
          </div>
        </el-form-item>

        <el-form-item
          v-if="form.cancel_unpaid_orders === 1"
          :label="$t('transaction.cancelUnpaidTimes')"
        >
          <el-input-number
            v-model="form.cancel_unpaid_orders_times"
            :min="1"
            :precision="0"
            style="width: 200px"
          >
            <template #suffix>分钟</template>
          </el-input-number>
        </el-form-item>

        <el-divider style="margin: 8px 0 20px" />

        <!-- 自动核销订单 -->
        <el-form-item>
          <template #label>
            <span>{{ $t('transaction.verificationOrders') }}</span>
          </template>
          <div style="display: flex; align-items: center; gap: 12px">
            <el-switch
              v-model="form.verification_orders"
              :active-value="1"
              :inactive-value="0"
            />
            <el-text type="info" size="small">
              {{ $t('transaction.verificationOrders.enable') }}
            </el-text>
          </div>
        </el-form-item>

        <el-form-item
          v-if="form.verification_orders === 1"
          :label="$t('transaction.verificationOrdersTimes')"
        >
          <el-input-number
            v-model="form.verification_orders_times"
            :min="1"
            :precision="0"
            style="width: 200px"
          >
            <template #suffix>小时</template>
          </el-input-number>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="saving">
            {{ $t('transaction.save') }}
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref, onMounted } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import {
    getTransactionConfig,
    saveTransactionConfig,
    type TransactionConfig,
  } from '@/api/app';

  const { t } = useI18n();

  const saving = ref(false);

  const form = reactive<TransactionConfig>({
    cancel_unpaid_orders: 1,
    cancel_unpaid_orders_times: 30,
    verification_orders: 1,
    verification_orders_times: 24,
  });

  const fetchConfig = async () => {
    const res = await getTransactionConfig();
    // The shared response interceptor already returns the business envelope.
    Object.assign(form, res.data);
  };

  const handleSave = async () => {
    saving.value = true;
    try {
      await saveTransactionConfig({ ...form });
      ElMessage.success(t('transaction.tip.success'));
    } finally {
      saving.value = false;
    }
  };

  onMounted(fetchConfig);
</script>
