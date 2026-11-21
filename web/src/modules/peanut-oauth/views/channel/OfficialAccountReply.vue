<template>
  <div v-loading="loading" class="channel-panel">
    <el-alert v-if="!canView" type="warning" :closable="false">
      {{ $t('channel.officialAccount.permissionDenied') }}
    </el-alert>
    <template v-else>
      <el-alert
        v-if="tableError"
        :title="tableError"
        type="error"
        :closable="false"
      />
      <CrudPage
        :loading="loading"
        :data="rows"
        :pagination="pagination"
        row-key="id"
        border
        pagination-layout="total, prev, pager, next"
        @page-change="onPageChange"
      >
        <template #toolbar>
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
        </template>
        <template #columns>
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
          <el-table-column
            :label="$t('channel.reply.columns.status')"
            width="100"
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
                  v-if="canDetail"
                  link
                  size="small"
                  @click="openDetail(row)"
                  >详情</el-button
                >
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
        </template>
      </CrudPage>
    </template>
  </div>

  <CrudDetailDrawer v-model="detailVisible" title="公众号回复详情">
    <el-descriptions v-if="detailRecord" :column="1" border>
      <el-descriptions-item label="ID">{{
        detailRecord.id
      }}</el-descriptions-item>
      <el-descriptions-item :label="$t('channel.reply.columns.name')">{{
        detailRecord.name
      }}</el-descriptions-item>
      <el-descriptions-item :label="$t('channel.reply.columns.type')">{{
        replyTypeLabel(detailRecord.reply_type)
      }}</el-descriptions-item>
      <el-descriptions-item
        v-if="detailRecord.reply_type === 2"
        :label="$t('channel.reply.columns.keyword')"
        >{{ detailRecord.keyword }}</el-descriptions-item
      >
      <el-descriptions-item
        v-if="detailRecord.reply_type === 2"
        :label="$t('channel.reply.columns.matchingType')"
        >{{
          matchingTypeLabel(detailRecord.matching_type)
        }}</el-descriptions-item
      >
      <el-descriptions-item :label="$t('channel.reply.columns.content')">{{
        detailRecord.content
      }}</el-descriptions-item>
      <el-descriptions-item :label="$t('channel.reply.columns.status')">{{
        detailRecord.status === 1
          ? $t('channel.reply.enabled')
          : $t('channel.reply.disabled')
      }}</el-descriptions-item>
      <el-descriptions-item :label="$t('channel.reply.columns.sort')">{{
        detailRecord.sort
      }}</el-descriptions-item>
    </el-descriptions>
  </CrudDetailDrawer>
  <el-dialog
    v-model="modalVisible"
    :title="
      isEdit ? $t('channel.reply.editTitle') : $t('channel.reply.addTitle')
    "
    :close-on-click-modal="false"
  >
    <el-alert
      v-if="submitError"
      :title="submitError"
      type="error"
      :closable="false"
    />
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
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue';
  import { getTabKey } from '@peanut-admin/ui-vue/tabs';
  import { registerTenantDisposer, useAsyncAction } from '@peanut-admin/vue';
  import useCrudTab from '@/hooks/useCrudTab';
  import CrudDetailDrawer from '@/components/crud/CrudDetailDrawer.vue';
  import CrudPage from '@/components/crud/CrudPage.vue';
  import useCrudTable from '@/hooks/useCrudTable';
  import useCrudModal from '@/hooks/useCrudModal';
  import { useI18n } from 'vue-i18n';
  import { useRoute } from 'vue-router';
  import { ElMessage, type FormRules } from 'element-plus';
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

  const props = defineProps<{ active: boolean }>();
  const route = useRoute();
  const { t } = useI18n();
  const canView = computed(() =>
    hasPermission('peanut.oauth.official-account.reply.list', route.path)
  );
  const canDetail = computed(() =>
    hasPermission('peanut.oauth.official-account.reply.detail', route.path)
  );
  const detailVisible = ref(false);
  const detailRecord = ref<OfficialAccountReplyRecord>();
  const detailAction = useAsyncAction((error) =>
    error instanceof Error ? error.message : String(error)
  );
  const clearDetail = () => {
    detailAction.cancel();
    detailVisible.value = false;
    detailRecord.value = undefined;
  };
  const currentTab = useCrudTab(clearDetail);
  watch(
    () => props.active,
    (active) => {
      if (!active) clearDetail();
    }
  );
  const unregisterDetail = registerTenantDisposer(
    `peanut.oauth.reply.detail:${getTabKey(route)}`,
    clearDetail
  );
  onScopeDispose(unregisterDetail);
  const openDetail = async (record: OfficialAccountReplyRecord) => {
    if (!canDetail.value || !currentTab.value || !props.active) return;
    const result = await detailAction.run(
      async () => (await getOfficialAccountReplyDetail(record.id)).data
    );
    if (
      result.status !== 'completed' ||
      !currentTab.value ||
      !props.active ||
      !canDetail.value
    )
      return;
    detailRecord.value = result.data;
    detailVisible.value = true;
  };
  const hasStatusPermission = computed(() =>
    hasPermission(
      'peanut.oauth.official-account.reply.update-status',
      route.path
    )
  );
  const filterType = ref<OfficialAccountReplyType | ''>('');
  const {
    loading,
    data: rows,
    pagination,
    error: tableError,
    fetchList,
  } = useCrudTable<OfficialAccountReplyRecord, Record<string, never>>({
    initialFilters: {},
    initialPageSize: 15,
    errorMessage: (error) =>
      error instanceof Error ? error.message : String(error),
    load: async ({ page, pageSize }) => {
      const { data } = await getOfficialAccountReplyList({
        reply_type: filterType.value || undefined,
        page_no: page,
        page_size: pageSize,
      });
      return {
        items: data.lists,
        total: data.count,
        page: data.pageNo,
        pageSize: data.pageSize,
      };
    },
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
  const {
    create: handleAdd,
    edit: openEdit,
    submit: handleSubmit,
    form,
    formRef,
    visible: modalVisible,
    mode,
    submitLoading,
    submitError,
  } = useCrudModal<OfficialAccountReplyForm, OfficialAccountReplyRecord>({
    canOpen: (intent) =>
      intent !== 'view' &&
      hasPermission(
        `peanut.oauth.official-account.reply.${
          intent === 'create' ? 'add' : 'edit'
        }`,
        route.path
      ),
    createForm: defaultForm,
    editForm: (record) => ({ ...defaultForm(), ...record }),
    save: (values, intent, signal) => {
      const payload: OfficialAccountReplyForm = {
        ...(values.id ? { id: values.id } : {}),
        name: values.name.trim(),
        keyword: values.reply_type === 2 ? values.keyword.trim() : '',
        reply_type: values.reply_type,
        matching_type: values.reply_type === 2 ? values.matching_type : 1,
        content_type: 1,
        content: values.content.trim(),
        status: values.status,
        sort: values.reply_type === 2 ? values.sort : 0,
      };
      return intent === 'edit'
        ? editOfficialAccountReply(payload, signal)
        : addOfficialAccountReply(payload, signal);
    },
    onSaved: async () => {
      ElMessage.success(t('channel.tip.success'));
      await fetchList(pagination.page);
    },
  });
  const isEdit = computed(() => mode.value === 'edit');
  const rules: FormRules = {
    reply_type: [{ required: true }],
    name: [
      {
        required: true,
        whitespace: true,
        message: t('channel.reply.nameRequired'),
      },
    ],
    keyword: [
      {
        validator: (
          _rule: unknown,
          value: string,
          callback: (error?: Error) => void
        ) => {
          if (form.value.reply_type === 2 && !value.trim()) {
            callback(new Error(t('channel.reply.keywordRequired')));
            return;
          }
          callback();
        },
      },
    ],
    content: [
      {
        required: true,
        whitespace: true,
        message: t('channel.reply.contentRequired'),
      },
    ],
    status: [{ required: true }],
    sort: [
      {
        validator: (
          _rule: unknown,
          value: number,
          callback: (error?: Error) => void
        ) => {
          if (
            form.value.reply_type === 2 &&
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

  const fetchData = (page = 1) =>
    canView.value ? fetchList(page) : Promise.resolve(false);
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

  const handleEdit = async (record: OfficialAccountReplyRecord) => {
    const { data } = await getOfficialAccountReplyDetail(record.id);
    await openEdit(data);
  };

  const handleDelete = async (record: OfficialAccountReplyRecord) => {
    await deleteOfficialAccountReply(record.id);
    ElMessage.success(t('channel.tip.success'));
    await fetchData(pagination.page);
  };

  const handleStatus = async (
    record: OfficialAccountReplyRecord,
    enabled: boolean
  ) => {
    const status: 0 | 1 = enabled ? 1 : 0;
    await updateOfficialAccountReplyStatus(record.id, status);
    await fetchData(pagination.page);
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
