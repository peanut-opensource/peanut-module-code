<template>
  <div v-loading="loading" class="channel-panel">
    <el-alert v-if="!canView" type="warning" :closable="false">
      {{ $t('channel.officialAccount.permissionDenied') }}
    </el-alert>
    <template v-else>
      <div class="toolbar">
        <el-select
          v-model="filterType"
          clearable
          style="width: 180px"
          :placeholder="$t('channel.reply.filterType')"
          @change="onTypeChange"
        >
          <el-option :label="$t('channel.reply.allTypes')" :value="''" />
          <el-option :label="$t('channel.reply.subscribe')" :value="1" />
          <el-option :label="$t('channel.reply.keyword')" :value="2" />
          <el-option :label="$t('channel.reply.default')" :value="3" />
        </el-select>
        <el-button
          v-permission="['peanut.oauth.official-account.reply.add']"
          type="primary"
          @click="handleAdd"
        >
          {{ $t('channel.reply.add') }}
        </el-button>
      </div>
      <el-table row-key="id" :data="rows" border>
        <el-table-column
          prop="name"
          :label="$t('channel.reply.columns.name')"
        />
        <el-table-column :label="$t('channel.reply.columns.type')" width="110"
          ><template #default="{ row }">{{
            replyTypeLabel(row.reply_type)
          }}</template></el-table-column
        >
        <el-table-column
          prop="keyword"
          :label="$t('channel.reply.columns.keyword')"
          width="160"
        />
        <el-table-column
          :label="$t('channel.reply.columns.matchingType')"
          width="110"
          ><template #default="{ row }"
            ><span v-if="row.reply_type === 2">{{
              matchingTypeLabel(row.matching_type)
            }}</span
            ><span v-else>-</span></template
          ></el-table-column
        >
        <el-table-column
          :label="$t('channel.reply.columns.contentType')"
          width="90"
          ><template #default>{{
            $t('channel.reply.text')
          }}</template></el-table-column
        >
        <el-table-column
          prop="content"
          :label="$t('channel.reply.columns.content')"
          show-overflow-tooltip
        />
        <el-table-column :label="$t('channel.reply.columns.status')" width="100"
          ><template #default="{ row }">
            <el-switch
              :model-value="row.status === 1"
              v-permission="[
                'peanut.oauth.official-account.reply.update-status',
              ]"
              @change="(value: boolean) => handleStatus(row, value)"
            />
            <span v-if="!hasStatusPermission">{{
              row.status === 1
                ? $t('channel.reply.enabled')
                : $t('channel.reply.disabled')
            }}</span>
          </template></el-table-column
        >
        <el-table-column
          prop="sort"
          :label="$t('channel.reply.columns.sort')"
          width="80"
        />
        <el-table-column
          :label="$t('channel.reply.columns.operations')"
          width="170"
          fixed="right"
          ><template #default="{ row }">
            <el-space>
              <el-button
                v-permission="['peanut.oauth.official-account.reply.edit']"
                link
                type="primary"
                size="small"
                @click="handleEdit(row)"
              >
                {{ $t('channel.reply.edit') }}
              </el-button>
              <el-popconfirm
                :title="$t('channel.reply.deleteConfirm')"
                @confirm="handleDelete(row)"
              >
                <template #reference
                  ><el-button
                    v-permission="[
                      'peanut.oauth.official-account.reply.delete',
                    ]"
                    link
                    type="danger"
                    size="small"
                  >
                    {{ $t('channel.reply.delete') }}
                  </el-button></template
                >
              </el-popconfirm>
            </el-space>
          </template></el-table-column
        >
      </el-table>
      <div class="pagination-wrapper"
        ><el-pagination
          :current-page="pagination.current"
          :page-size="pagination.pageSize"
          :total="pagination.total"
          layout="total, prev, pager, next"
          @current-change="onPageChange"
      /></div>
    </template>
  </div>

  <el-dialog
    v-model="modalVisible"
    :title="
      isEdit ? $t('channel.reply.editTitle') : $t('channel.reply.addTitle')
    "
    :close-on-click-modal="false"
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
      <el-form-item prop="reply_type" :label="$t('channel.reply.type')"
        ><el-select v-model="form.reply_type"
          ><el-option
            :label="$t('channel.reply.subscribe')"
            :value="1" /><el-option
            :label="$t('channel.reply.keyword')"
            :value="2" /><el-option
            :label="$t('channel.reply.default')"
            :value="3" /></el-select
      ></el-form-item>
      <el-form-item prop="name" :label="$t('channel.reply.name')"
        ><el-input v-model="form.name" :maxlength="100"
      /></el-form-item>
      <template v-if="form.reply_type === 2">
        <el-form-item prop="keyword" :label="$t('channel.reply.keywordValue')"
          ><el-input v-model="form.keyword" :maxlength="255"
        /></el-form-item>
        <el-form-item
          prop="matching_type"
          :label="$t('channel.reply.matchingType')"
        >
          <el-radio-group v-model="form.matching_type"
            ><el-radio :value="1">{{ $t('channel.reply.exact') }}</el-radio
            ><el-radio :value="2">{{
              $t('channel.reply.fuzzy')
            }}</el-radio></el-radio-group
          >
        </el-form-item>
      </template>
      <el-form-item :label="$t('channel.reply.contentType')"
        ><el-tag type="primary">{{
          $t('channel.reply.text')
        }}</el-tag></el-form-item
      >
      <el-form-item prop="content" :label="$t('channel.reply.content')">
        <el-input
          type="textarea"
          v-model="form.content"
          :maxlength="5000"
          show-word-limit
          :autosize="{ minRows: 4, maxRows: 10 }"
        />
      </el-form-item>
      <el-form-item prop="status" :label="$t('channel.reply.status')">
        <el-switch
          v-model="form.status"
          :active-value="1"
          :inactive-value="0"
        />
      </el-form-item>
      <el-form-item
        v-if="form.reply_type === 2"
        prop="sort"
        :label="$t('channel.reply.sort')"
      >
        <el-input-number v-model="form.sort" :min="0" :precision="0" />
      </el-form-item>
    </el-form>
    <template #footer
      ><el-button @click="modalVisible = false">取消</el-button
      ><el-button type="primary" :loading="submitLoading" @click="handleSubmit"
        >确定</el-button
      ></template
    >
  </el-dialog>
</template>

<script lang="ts" setup>
  import { computed, onMounted, reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage, type FormInstance, type FormRules } from 'element-plus';
  import { hasPermission } from '@/hooks/permission';
  import {
    addOfficialAccountReply,
    deleteOfficialAccountReply,
    editOfficialAccountReply,
    getOfficialAccountReplyDetail,
    getOfficialAccountReplyList,
    updateOfficialAccountReplyStatus,
    type OfficialAccountMatchingType,
    type OfficialAccountReplyForm,
    type OfficialAccountReplyRecord,
    type OfficialAccountReplyType,
  } from '@/modules/peanut-oauth/api';

  const { t } = useI18n();
  const canView = computed(() =>
    hasPermission('peanut.oauth.official-account.reply.list')
  );
  const hasStatusPermission = computed(() =>
    hasPermission('peanut.oauth.official-account.reply.update-status')
  );
  const loading = ref(false);
  const rows = ref<OfficialAccountReplyRecord[]>([]);
  const filterType = ref<OfficialAccountReplyType | ''>('');
  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
  });
  const defaultForm = (): OfficialAccountReplyForm => ({
    name: '',
    keyword: '',
    reply_type: 1,
    matching_type: 1,
    content_type: 1,
    content: '',
    status: 1,
    sort: 0,
  });
  const form = reactive<OfficialAccountReplyForm>(defaultForm());
  const formRef = ref<FormInstance>();
  const modalVisible = ref(false);
  const isEdit = ref(false);
  const submitLoading = ref(false);
  const detailLoading = ref(false);
  const rules: FormRules = {
    reply_type: [{ required: true }],
    name: [{ required: true, message: t('channel.reply.nameRequired') }],
    keyword: [
      {
        validator: (
          _rule: unknown,
          value: string,
          callback: (error?: Error) => void
        ) => {
          if (form.reply_type === 2 && !value.trim()) {
            callback(new Error(t('channel.reply.keywordRequired')));
            return;
          }
          callback();
        },
      },
    ],
    content: [{ required: true, message: t('channel.reply.contentRequired') }],
    status: [{ required: true }],
    sort: [
      {
        validator: (
          _rule: unknown,
          value: number,
          callback: (error?: Error) => void
        ) => {
          if (
            form.reply_type === 2 &&
            (!Number.isInteger(value) || value < 0)
          ) {
            callback(new Error(t('channel.reply.sortInvalid')));
            return;
          }
          callback();
        },
      },
    ],
  };

  const fetchData = async (page = 1) => {
    if (!canView.value) return;
    loading.value = true;
    try {
      const { data } = await getOfficialAccountReplyList({
        reply_type: filterType.value || undefined,
        page_no: page,
        page_size: pagination.pageSize,
      });
      rows.value = data.lists;
      pagination.current = data.pageNo;
      pagination.pageSize = data.pageSize;
      pagination.total = data.count;
    } finally {
      loading.value = false;
    }
  };
  onMounted(() => fetchData());

  const onTypeChange = (value: unknown) => {
    if (value === '') filterType.value = '';
    else if (typeof value === 'string' || typeof value === 'number') {
      filterType.value = Number(value) as OfficialAccountReplyType;
    } else filterType.value = '';
    fetchData(1);
  };
  const onPageChange = (page: number) => fetchData(page);

  const replyTypeLabel = (value: OfficialAccountReplyType) => {
    if (value === 1) return t('channel.reply.subscribe');
    if (value === 2) return t('channel.reply.keyword');
    return t('channel.reply.default');
  };
  const matchingTypeLabel = (value: OfficialAccountMatchingType) =>
    t(value === 1 ? 'channel.reply.exact' : 'channel.reply.fuzzy');

  const resetForm = (patch: Partial<OfficialAccountReplyForm> = {}) => {
    Object.assign(form, defaultForm(), patch);
  };

  const handleAdd = () => {
    isEdit.value = false;
    resetForm();
    modalVisible.value = true;
  };

  const handleEdit = async (record: OfficialAccountReplyRecord) => {
    isEdit.value = true;
    detailLoading.value = true;
    try {
      const { data } = await getOfficialAccountReplyDetail(record.id);
      resetForm({ ...data });
      modalVisible.value = true;
    } finally {
      detailLoading.value = false;
    }
  };

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    if (!form.name.trim() || !form.content.trim()) {
      ElMessage.error(t('channel.reply.contentRequired'));
      return;
    }
    if (form.reply_type === 2) {
      if (!form.keyword.trim()) {
        ElMessage.error(t('channel.reply.keywordRequired'));
        return;
      }
      if (!Number.isInteger(form.sort) || form.sort < 0) {
        ElMessage.error(t('channel.reply.sortInvalid'));
        return;
      }
    }
    const payload: OfficialAccountReplyForm = {
      ...(form.id ? { id: form.id } : {}),
      name: form.name.trim(),
      keyword: form.reply_type === 2 ? form.keyword.trim() : '',
      reply_type: form.reply_type,
      matching_type: form.reply_type === 2 ? form.matching_type : 1,
      content_type: 1,
      content: form.content.trim(),
      status: form.status,
      sort: form.reply_type === 2 ? form.sort : 0,
    };
    submitLoading.value = true;
    try {
      if (isEdit.value) await editOfficialAccountReply(payload);
      else await addOfficialAccountReply(payload);
      ElMessage.success(t('channel.tip.success'));
      modalVisible.value = false;
      await fetchData(pagination.current);
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: OfficialAccountReplyRecord) => {
    await deleteOfficialAccountReply(record.id);
    ElMessage.success(t('channel.tip.success'));
    await fetchData(pagination.current);
  };

  const handleStatus = async (
    record: OfficialAccountReplyRecord,
    enabled: boolean
  ) => {
    const status: 0 | 1 = enabled ? 1 : 0;
    await updateOfficialAccountReplyStatus(record.id, status);
    record.status = status;
    ElMessage.success(t('channel.tip.success'));
  };
</script>

<style scoped>
  .channel-panel {
    min-height: 180px;
  }
  .toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 16px 0;
  }
  .pagination-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
</style>

<script lang="ts">
  export default { name: 'OfficialAccountReply' };
</script>
