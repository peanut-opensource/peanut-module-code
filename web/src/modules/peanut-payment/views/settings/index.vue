<template>
  <div class="container">
    <Breadcrumb :items="['menu.appSetting', 'menu.appSetting.pay']" />
    <el-card
      v-loading="loading"
      class="general-card"
      :header="$t('menu.appSetting.pay')"
    >
      <el-tabs v-model="activeTab">
        <el-tab-pane name="wechat" label="微信支付">
          <el-form :model="pay" label-position="top" class="form-width">
            <el-form-item label="启用微信支付">
              <el-switch
                v-model="pay.wx_pay_status"
                :active-value="1"
                :inactive-value="0"
              />
            </el-form-item>
            <el-form-item label="AppID">
              <el-input v-model="pay.wx_pay_appid" />
            </el-form-item>
            <el-form-item label="商户号">
              <el-input v-model="pay.wx_pay_mch_id" />
            </el-form-item>
            <el-form-item label="APIv3 密钥">
              <el-input
                v-model="pay.wx_pay_secret"
                type="password"
                show-password
                :placeholder="
                  pay.wx_pay_secret_configured
                    ? '已配置，保留 ****** 即不覆盖'
                    : '32 字节 APIv3 密钥'
                "
              />
            </el-form-item>
            <el-form-item label="商户证书路径">
              <el-input v-model="pay.wx_pay_cert_path" />
            </el-form-item>
            <el-form-item label="商户私钥路径">
              <el-input v-model="pay.wx_pay_cert_key_path" />
            </el-form-item>
            <el-form-item label="微信平台证书路径">
              <el-input v-model="pay.wx_pay_platform_cert_path" />
            </el-form-item>
            <el-button
              v-permission="['peanut.payment.settings.save']"
              type="primary"
              :loading="savingPay"
              @click="savePay"
            >
              保存支付配置
            </el-button>
          </el-form>
        </el-tab-pane>

        <el-tab-pane name="alipay" label="支付宝">
          <el-form :model="pay" label-position="top" class="form-width">
            <el-form-item label="启用支付宝">
              <el-switch
                v-model="pay.ali_pay_status"
                :active-value="1"
                :inactive-value="0"
              />
            </el-form-item>
            <el-form-item label="应用 AppID">
              <el-input v-model="pay.ali_pay_app_id" />
            </el-form-item>
            <el-form-item label="商户 Seller ID">
              <el-input v-model="pay.ali_pay_seller_id" />
            </el-form-item>
            <el-form-item label="应用私钥">
              <el-input
                v-model="pay.ali_pay_private_key"
                type="textarea"
                :autosize="{ minRows: 4, maxRows: 8 }"
                :placeholder="
                  pay.ali_pay_private_key_configured
                    ? '已配置，保留 ****** 即不覆盖'
                    : ''
                "
              />
            </el-form-item>
            <el-form-item label="支付宝公钥">
              <el-input
                v-model="pay.ali_pay_public_key"
                type="textarea"
                :autosize="{ minRows: 4, maxRows: 8 }"
              />
            </el-form-item>
            <el-button
              v-permission="['peanut.payment.settings.save']"
              type="primary"
              :loading="savingPay"
              @click="savePay"
            >
              保存支付配置
            </el-button>
          </el-form>
        </el-tab-pane>

        <el-tab-pane name="recharge" label="充值配置">
          <el-form :model="recharge" label-position="top" class="form-width">
            <el-form-item label="启用充值">
              <el-switch
                v-model="recharge.status"
                :active-value="1"
                :inactive-value="0"
              />
            </el-form-item>
            <el-form-item label="最低金额">
              <el-input-number
                v-model="recharge.min_amount"
                :min="0.01"
                :precision="2"
              />
            </el-form-item>
            <el-form-item label="最高金额">
              <el-input-number
                v-model="recharge.max_amount"
                :min="0.01"
                :precision="2"
              />
            </el-form-item>
          </el-form>

          <el-table :data="recharge.scenes" row-key="sceneKey">
            <el-table-column label="终端">
              <template #default="{ row }">
                {{ terminalName(row.terminal) }}
              </template>
            </el-table-column>
            <el-table-column label="支付方式">
              <template #default="{ row }">
                {{ row.pay_way === 2 ? '微信支付' : '支付宝' }}
              </template>
            </el-table-column>
            <el-table-column label="启用">
              <template #default="{ row }">
                <el-switch
                  v-model="row.status"
                  :active-value="1"
                  :inactive-value="0"
                  @change="() => onSceneStatus(row)"
                />
              </template>
            </el-table-column>
            <el-table-column label="默认">
              <template #default="{ row }">
                <el-radio
                  :model-value="row.is_default === 1"
                  :value="true"
                  :disabled="row.status !== 1"
                  @change="() => setDefault(row)"
                >
                  默认
                </el-radio>
              </template>
            </el-table-column>
          </el-table>

          <el-button
            v-permission="['peanut.payment.recharge-settings.save']"
            type="primary"
            :loading="savingRecharge"
            style="margin-top: 16px"
            @click="saveRecharge"
          >
            保存充值配置
          </el-button>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script setup lang="ts">
  import { reactive, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import useLoading from '@/hooks/loading';
  import {
    getPayConfig,
    getRechargeSetting,
    savePayConfig,
    saveRechargeSetting,
    type PayConfig,
    type RechargeScene,
    type RechargeSetting,
  } from '@/modules/peanut-payment/api';

  const { loading, setLoading } = useLoading(true);
  const activeTab = ref('wechat');
  const savingPay = ref(false);
  const savingRecharge = ref(false);
  const pay = reactive<PayConfig>({
    wx_pay_status: 0,
    wx_pay_appid: '',
    wx_pay_mch_id: '',
    wx_pay_secret: '',
    wx_pay_cert_path: '',
    wx_pay_cert_key_path: '',
    wx_pay_platform_cert_path: '',
    ali_pay_status: 0,
    ali_pay_app_id: '',
    ali_pay_private_key: '',
    ali_pay_public_key: '',
    ali_pay_seller_id: '',
  });

  type RechargeForm = Omit<RechargeSetting, 'min_amount' | 'max_amount'> & {
    min_amount: number;
    max_amount: number;
  };

  const recharge = reactive<RechargeForm>({
    status: 0,
    min_amount: 0.01,
    max_amount: 99999,
    scenes: [],
  });
  const terminals: Record<number, string> = {
    1: '小程序',
    2: '公众号',
    3: 'H5',
    4: 'PC',
    5: 'iOS',
    6: 'Android',
  };
  const terminalName = (terminal: number) =>
    terminals[terminal] || String(terminal);

  async function load() {
    setLoading(true);
    try {
      const [payResult, rechargeResult] = await Promise.all([
        getPayConfig(),
        getRechargeSetting(),
      ]);
      Object.assign(pay, payResult.data);
      Object.assign(recharge, rechargeResult.data, {
        min_amount: Number(rechargeResult.data.min_amount),
        max_amount: Number(rechargeResult.data.max_amount),
        scenes: rechargeResult.data.scenes.map((scene) => ({
          ...scene,
          sceneKey: `${scene.terminal}-${scene.pay_way}`,
        })),
      });
    } catch {
      // The shared interceptor displays the request error.
    } finally {
      setLoading(false);
    }
  }
  load();

  function setDefault(scene: RechargeScene) {
    recharge.scenes.forEach((item) => {
      if (item.terminal === scene.terminal) {
        item.is_default = item === scene ? 1 : 0;
      }
    });
  }

  function onSceneStatus(scene: RechargeScene) {
    if (scene.status !== 1) scene.is_default = 0;
    const enabled = recharge.scenes.filter(
      (item) => item.terminal === scene.terminal && item.status === 1
    );
    if (enabled.length && !enabled.some((item) => item.is_default === 1)) {
      enabled[0].is_default = 1;
    }
  }

  async function savePay() {
    if (savingPay.value) return;
    savingPay.value = true;
    try {
      await savePayConfig({ ...pay });
      ElMessage.success('支付配置已保存');
      await load();
    } catch {
      // Keep the form available after the interceptor displays the error.
    } finally {
      savingPay.value = false;
    }
  }

  async function saveRecharge() {
    if (savingRecharge.value) return;
    savingRecharge.value = true;
    try {
      await saveRechargeSetting({
        ...recharge,
        min_amount: String(recharge.min_amount),
        max_amount: String(recharge.max_amount),
        scenes: recharge.scenes.map((scene) => ({
          terminal: scene.terminal,
          pay_way: scene.pay_way,
          status: scene.status,
          is_default: scene.is_default,
        })),
      });
      ElMessage.success('充值配置已保存');
      await load();
    } catch {
      // Keep the form available after the interceptor displays the error.
    } finally {
      savingRecharge.value = false;
    }
  }
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .form-width {
    max-width: 620px;
    margin-top: 16px;
  }
</style>
