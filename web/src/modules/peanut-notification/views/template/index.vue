<template>
  <div class="container">
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" row-key="id" border>
        <el-table-column
          :label="$t('noticeScene.columns.name')"
          prop="name"
          width="180"
        />
        <el-table-column
          :label="$t('noticeScene.columns.description')"
          prop="description"
        />
        <el-table-column
          :label="$t('noticeScene.columns.recipient')"
          prop="recipient"
          width="100"
        />
        <el-table-column
          :label="$t('noticeScene.columns.sms')"
          prop="sms_status"
          width="100"
        >
          <template #default="{ row }">
            <el-tag :type="row.sms_status === 1 ? 'success' : 'danger'">
              {{
                row.sms_status === 1
                  ? $t('noticeScene.status.enabled')
                  : $t('noticeScene.status.disabled')
              }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          :label="$t('noticeScene.columns.content')"
          prop="sms_content"
          show-overflow-tooltip
        />
        <el-table-column
          :label="$t('noticeScene.columns.operations')"
          align="center"
          width="100"
          fixed="right"
        >
          <template #default="{ row }">
            <el-button
              v-permission="[
                'peanut.notification.scene.detail',
                'peanut.notification.scene.save',
              ]"
              link
              type="primary"
              size="small"
              @click="openSettings(row)"
            >
              {{ $t('noticeScene.operation.settings') }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog
      v-model="modalVisible"
      :title="currentScene?.name || $t('noticeScene.operation.settings')"
      width="620px"
      @closed="closeModal"
    >
      <el-form ref="formRef" :model="form" label-position="top">
        <el-form-item :label="$t('noticeScene.field.variables')">
          <el-space wrap>
            <el-tag v-for="item in currentScene?.variables || []" :key="item">
              {{ '$' + '{' + item + '}' }}
            </el-tag>
          </el-space>
        </el-form-item>
        <el-form-item
          prop="sms_template_id"
          :label="$t('noticeScene.field.templateId')"
          :rules="[{ max: 100 }]"
        >
          <el-input v-model="form.sms_template_id" clearable />
        </el-form-item>
        <el-form-item
          prop="sms_content"
          :label="$t('noticeScene.field.content')"
          :rules="[{ max: 500 }]"
        >
          <el-input
            v-model="form.sms_content"
            type="textarea"
            :autosize="{ minRows: 4, maxRows: 8 }"
          />
          <div class="form-tip">
            {{ $t('noticeScene.field.contentTip') + '{code}' }}
          </div>
        </el-form-item>
        <el-form-item :label="$t('noticeScene.field.enabled')">
          <el-switch
            v-model="form.sms_status"
            :active-value="1"
            :inactive-value="0"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="closeModal">取消</el-button>
        <el-button type="primary" @click="saveSettings">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { onMounted, reactive, ref } from 'vue';
  import { ElMessage, type FormInstance } from 'element-plus';
  import { useI18n } from 'vue-i18n';
  import {
    getNoticeSceneDetail,
    getNoticeSceneList,
    saveNoticeScene,
    type NoticeSceneRecord,
  } from '@/modules/peanut-notification/api';

  const { t } = useI18n();
  const loading = ref(false);
  const tableData = ref<NoticeSceneRecord[]>([]);
  const modalVisible = ref(false);
  const currentScene = ref<NoticeSceneRecord>();
  const formRef = ref<FormInstance>();
  const form = reactive({
    id: 0,
    sms_template_id: '',
    sms_content: '',
    sms_status: 0,
  });

  const fetchData = async () => {
    loading.value = true;
    try {
      const { data } = await getNoticeSceneList();
      tableData.value = data.list;
    } finally {
      loading.value = false;
    }
  };

  const openSettings = async (record: NoticeSceneRecord) => {
    const { data } = await getNoticeSceneDetail(record.id);
    currentScene.value = data;
    Object.assign(form, {
      id: data.id,
      sms_template_id: data.sms_template_id || '',
      sms_content: data.sms_content || '',
      sms_status: data.sms_status,
    });
    modalVisible.value = true;
  };

  const closeModal = () => {
    modalVisible.value = false;
    currentScene.value = undefined;
    formRef.value?.clearValidate();
  };

  const saveSettings = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    await saveNoticeScene({ ...form });
    ElMessage.success(t('noticeScene.tip.success'));
    closeModal();
    await fetchData();
  };

  onMounted(fetchData);
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .form-tip {
    margin-top: 4px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
</style>
