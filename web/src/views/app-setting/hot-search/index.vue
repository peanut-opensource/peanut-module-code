<template>
  <div class="container">
    <Breadcrumb :items="['menu.appSetting', 'menu.appSetting.hotSearch']" />
    <el-card
      v-loading="loading"
      class="general-card"
      :header="$t('menu.appSetting.hotSearch')"
    >
      <el-form :model="form" label-position="top" style="max-width: 640px">
        <el-form-item :label="$t('hotSearch.field.status')">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
          />
          <span class="tip">{{ $t('hotSearch.field.status.tip') }}</span>
        </el-form-item>

        <el-form-item :label="$t('hotSearch.field.words')">
          <div style="width: 100%">
            <div
              v-for="(item, index) in form.data"
              :key="index"
              class="word-row"
            >
              <el-input
                v-model="item.name"
                :placeholder="$t('hotSearch.field.word.placeholder')"
                style="width: 260px"
                clearable
              />
              <el-input-number
                v-model="item.sort"
                :min="0"
                :placeholder="$t('hotSearch.field.sort')"
                style="width: 140px"
              />
              <el-button
                type="danger"
                link
                :icon="Delete"
                @click="removeRow(index)"
              />
            </div>
            <el-button plain size="small" :icon="Plus" @click="addRow">
              {{ $t('hotSearch.operation.addWord') }}
            </el-button>
          </div>
        </el-form-item>

        <el-form-item>
          <el-button
            type="primary"
            :loading="submitLoading"
            @click="handleSubmit"
          >
            {{ $t('hotSearch.operation.save') }}
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import { Delete, Plus } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getHotSearchConfig,
    saveHotSearchConfig,
    type HotSearchConfig,
  } from '@/api/app';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const submitLoading = ref(false);

  const form = reactive<HotSearchConfig>({
    status: 0,
    data: [],
  });

  const fetchData = async () => {
    setLoading(true);
    try {
      const { data } = await getHotSearchConfig();
      form.status = data.status;
      form.data = data.data ?? [];
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  const addRow = () => {
    form.data.push({ name: '', sort: 0 });
  };
  const removeRow = (index: number) => {
    form.data.splice(index, 1);
  };

  const handleSubmit = async () => {
    submitLoading.value = true;
    try {
      const payload: HotSearchConfig = {
        status: form.status,
        data: form.data.filter((item) => item.name.trim() !== ''),
      };
      await saveHotSearchConfig(payload);
      ElMessage.success(t('hotSearch.tip.success'));
      await fetchData();
    } finally {
      submitLoading.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'AppSettingHotSearch',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .tip {
    margin-left: 12px;
    color: var(--color-text-3);
    font-size: 12px;
  }

  .word-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
  }
</style>
