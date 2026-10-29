<template>
  <div class="max-w-6xl mx-auto px-6 py-10">
    <div class="flex items-center justify-between mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">账户充值</h1>
        <p class="text-gray-500 text-sm mt-2"
          >仅创建充值订单并唤起真实支付渠道，支付结果以渠道回调为准。</p
        >
      </div>
      <NuxtLink to="/user/info" class="text-primary text-sm hover:underline"
        >返回个人中心</NuxtLink
      >
    </div>

    <el-alert
      v-if="config && config.status !== 1"
      type="warning"
      :closable="false"
      title="充值功能暂未开启"
      class="mb-6"
    />

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <el-card shadow="never">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-semibold">发起充值</span>
            <span v-if="config" class="text-sm text-gray-500"
              >当前余额 ¥{{ config.balance }}</span
            >
          </div>
        </template>
        <el-form label-width="96px" size="large" @submit.prevent="handleCreate">
          <el-form-item label="充值金额" required>
            <el-input
              v-model="amount"
              type="number"
              min="0.01"
              step="0.01"
              placeholder="请输入金额"
            >
              <template #prepend>¥</template>
            </el-input>
            <div v-if="config" class="text-xs text-gray-400 mt-1"
              >最低充值 ¥{{ config.min_amount }}</div
            >
          </el-form-item>
          <el-form-item label="支付方式" required>
            <el-radio-group v-model="payWay">
              <el-radio
                v-for="channel in config?.channels || []"
                :key="channel.pay_way"
                :label="channel.pay_way"
              >
                {{ channel.name
                }}<span
                  v-if="channel.is_default"
                  class="text-xs text-gray-400 ml-1"
                  >默认</span
                >
              </el-radio>
            </el-radio-group>
            <span
              v-if="config && !config.channels.length"
              class="text-sm text-gray-400"
              >暂无可用支付方式</span
            >
          </el-form-item>
          <el-form-item>
            <el-button
              type="primary"
              native-type="submit"
              :loading="creating"
              :disabled="
                !config || config.status !== 1 || !config.channels.length
              "
              >创建充值订单</el-button
            >
          </el-form-item>
        </el-form>
      </el-card>

      <el-card v-if="order" shadow="never">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-semibold">当前订单</span>
            <el-tag :type="order.pay_status === 1 ? 'success' : 'warning'">{{
              order.pay_status_text
            }}</el-tag>
          </div>
        </template>
        <dl class="space-y-3 text-sm">
          <div class="flex justify-between"
            ><dt class="text-gray-500">订单号</dt><dd>{{ order.sn }}</dd></div
          >
          <div class="flex justify-between"
            ><dt class="text-gray-500">金额</dt
            ><dd class="font-semibold">¥{{ order.order_amount }}</dd></div
          >
          <div class="flex justify-between"
            ><dt class="text-gray-500">支付方式</dt
            ><dd>{{ order.pay_way_text }}</dd></div
          >
          <div class="flex justify-between"
            ><dt class="text-gray-500">创建时间</dt
            ><dd>{{ order.create_time || '-' }}</dd></div
          >
        </dl>
        <el-button
          v-if="order.pay_status !== 1"
          type="primary"
          class="mt-6"
          :loading="prepaying"
          @click="handlePrepay"
          >继续支付</el-button
        >
      </el-card>
    </div>

    <el-card v-if="payment" shadow="never" class="mt-6">
      <template #header><span class="font-semibold">支付指引</span></template>
      <el-alert
        :title="
          payment.scene === 'NATIVE'
            ? '请使用微信扫码完成支付'
            : '请在支付页面完成支付'
        "
        type="info"
        :closable="false"
      />
      <div
        v-if="payment.scene === 'NATIVE'"
        class="mt-4 flex flex-wrap items-center gap-3"
      >
        <el-input
          :model-value="payment.payload.code_url || ''"
          readonly
          class="max-w-xl"
        />
        <el-button @click="copyPaymentValue(payment.payload.code_url || '')"
          >复制 code_url</el-button
        >
        <el-link
          v-if="isHttpUrl(payment.payload.code_url)"
          :href="payment.payload.code_url"
          target="_blank"
          type="primary"
          >打开支付链接</el-link
        >
      </div>
      <div v-else-if="payment.scene === 'MWEB'" class="mt-4">
        <el-button type="primary" @click="openPayment(payment.payload.h5_url)"
          >打开微信支付</el-button
        >
      </div>
      <div v-else-if="payment.scene === 'PAGE'" class="mt-4">
        <el-button
          type="primary"
          @click="openPayment(payment.payload.gateway_url)"
          >打开支付宝</el-button
        >
      </div>
      <p v-else class="text-sm text-gray-500 mt-4"
        >当前支付场景由服务端返回，PC 页面不模拟或伪造支付回调。</p
      >
    </el-card>

    <el-card shadow="never" class="mt-6">
      <template #header><span class="font-semibold">充值订单</span></template>
      <el-table
        v-loading="listLoading"
        :data="orders"
        empty-text="暂无充值订单"
      >
        <el-table-column prop="sn" label="订单号" min-width="180" />
        <el-table-column prop="order_amount" label="金额" width="120">
          <template #default="scope">¥{{ scope.row.order_amount }}</template>
        </el-table-column>
        <el-table-column prop="pay_way_text" label="支付方式" width="120" />
        <el-table-column prop="pay_status_text" label="状态" width="100" />
        <el-table-column prop="create_time" label="创建时间" min-width="170" />
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="scope">
            <el-button
              link
              type="primary"
              @click="selectOrder(scope.row as RechargeOrder)"
              >查看</el-button
            >
          </template>
        </el-table-column>
      </el-table>
      <div v-if="listCount > pageSize" class="flex justify-center mt-5">
        <el-pagination
          v-model:current-page="pageNo"
          :page-size="pageSize"
          :total="listCount"
          layout="prev, pager, next"
          @current-change="loadOrders"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
  import {
    createRechargeOrder,
    getRechargeConfig,
    getRechargeDetail,
    getRechargeLists,
    prepayRecharge,
    type RechargeConfig,
    type RechargeOrder,
    type RechargePayment,
  } from '~/modules/peanut-payment/api/recharge';

  definePageMeta({ layout: 'default', middleware: 'auth' });

  const request = useRequest();
  const config = ref<RechargeConfig | null>(null);
  const amount = ref('');
  const payWay = ref<number | undefined>();
  const order = ref<RechargeOrder | null>(null);
  const payment = ref<RechargePayment | null>(null);
  const orders = ref<RechargeOrder[]>([]);
  const listCount = ref(0);
  const pageNo = ref(1);
  const pageSize = 10;
  const creating = ref(false);
  const prepaying = ref(false);
  const listLoading = ref(false);

  onMounted(async () => {
    try {
      config.value = await getRechargeConfig(request);
      payWay.value =
        config.value.channels.find((item) => item.is_default === 1)?.pay_way ||
        config.value.channels[0]?.pay_way;
      await loadOrders();
    } catch {
      // useRequest already reports the server message.
    }
  });

  async function handleCreate() {
    const value = amount.value.trim();
    if (!/^\d+(?:\.\d{1,2})?$/.test(value) || Number(value) <= 0) {
      ElMessage.warning('请输入正确的充值金额');
      return;
    }
    if (!payWay.value) {
      ElMessage.warning('请选择支付方式');
      return;
    }
    creating.value = true;
    payment.value = null;
    try {
      const created = await createRechargeOrder(request, value);
      // Read back the server-owned order before offering payment controls.
      order.value = await getRechargeDetail(request, created.id);
      payWay.value = order.value.pay_way;
      await loadOrders();
      ElMessage.success('充值订单已创建');
    } catch {
      // useRequest already reports the server message.
    } finally {
      creating.value = false;
    }
  }

  async function handlePrepay() {
    if (!order.value) return;
    prepaying.value = true;
    try {
      const result = await prepayRecharge(
        request,
        order.value.id,
        order.value.pay_way
      );
      order.value = result.order;
      payment.value = result.payment;
    } catch {
      // useRequest already reports the server message.
    } finally {
      prepaying.value = false;
    }
  }

  async function loadOrders() {
    listLoading.value = true;
    try {
      const result = await getRechargeLists(request, pageNo.value, pageSize);
      orders.value = result.lists;
      listCount.value = result.count;
    } catch {
      // useRequest already reports the server message.
    } finally {
      listLoading.value = false;
    }
  }

  async function selectOrder(selected: RechargeOrder) {
    try {
      order.value = await getRechargeDetail(request, selected.id);
      payment.value = null;
    } catch {
      // useRequest already reports the server message.
    }
  }

  function isHttpUrl(value: string | undefined): boolean {
    return typeof value === 'string' && /^https?:\/\//i.test(value);
  }

  function openPayment(url: string | undefined) {
    if (!isHttpUrl(url)) {
      ElMessage.warning('支付链接不可用');
      return;
    }
    window.open(url, '_blank', 'noopener,noreferrer');
  }

  async function copyPaymentValue(value: string) {
    if (!value) return;
    try {
      await navigator.clipboard.writeText(value);
      ElMessage.success('已复制 code_url');
    } catch {
      ElMessage.warning('复制失败，请手动复制');
    }
  }
</script>
