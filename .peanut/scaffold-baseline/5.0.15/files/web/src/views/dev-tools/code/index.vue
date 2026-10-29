<template>
  <div class="container">
    <Breadcrumb :items="['menu.devTools', 'menu.devTools.code']" />
    <el-card class="general-card">
      <template #header>代码生成器</template>
      <el-row
        align="middle"
        justify="space-between"
        style="margin-bottom: 16px"
      >
        <el-col :span="12">
          <el-space>
            <el-input
              v-model="keyword"
              clearable
              placeholder="按表名、说明或实体筛选"
              style="width: 300px"
              @keyup.enter="fetchData(1)"
            />
            <el-button @click="fetchData(1)">查询</el-button>
          </el-space>
        </el-col>
        <el-col>
          <el-space>
            <el-button
              v-permission="['generator/import']"
              type="primary"
              @click="openSourceTables"
            >
              <template #icon><Plus /></template>
              导入数据表
            </el-button>
            <el-button
              v-permission="['generator/generate']"
              :disabled="selectedKeys.length === 0"
              :loading="generateLoading"
              @click="generateSelected"
            >
              <template #icon><icon-code /></template>
              生成并下载
            </el-button>
          </el-space>
        </el-col>
      </el-row>

      <el-table
        ref="generatorTable"
        row-key="id"
        :loading="loading"
        :data="renderData"
        border
        @selection-change="onSelectionChange"
      >
        <el-table-column type="selection" width="55" reserve-selection />
        <el-table-column prop="id" label="编号" width="70" />
        <el-table-column prop="table_name" label="数据表" width="180" />
        <el-table-column prop="table_comment" label="表说明" width="220" />
        <el-table-column prop="module_name" label="模块" width="140" />
        <el-table-column prop="entity_name" label="实体" width="160" />
        <el-table-column label="模板" width="90"
          ><template #default="{ row }"
            ><el-tag>{{
              row.template_type === 'tree' ? '树形' : 'CRUD'
            }}</el-tag></template
          ></el-table-column
        >
        <el-table-column label="操作" width="300"
          ><template #default="{ row }"
            ><el-space
              ><el-button
                v-permission="['generator/detail']"
                link
                size="small"
                @click="openEdit(row)"
                >配置</el-button
              ><el-button
                v-permission="['generator/sync']"
                link
                size="small"
                @click="handleSync(row)"
                >同步字段</el-button
              ><el-button
                v-permission="['generator/preview']"
                link
                size="small"
                @click="openPreview(row)"
                >预览</el-button
              ><el-popconfirm
                title="确定删除该生成配置吗？"
                @confirm="handleDelete(row)"
                ><template #reference
                  ><el-button
                    v-permission="['generator/delete']"
                    link
                    type="danger"
                    size="small"
                    >删除</el-button
                  ></template
                ></el-popconfirm
              ></el-space
            ></template
          ></el-table-column
        >
      </el-table>
      <el-pagination
        v-model:current-page="pagination.current"
        v-model:page-size="pagination.pageSize"
        :total="pagination.total"
        layout="total, prev, pager, next"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="onPageChange"
      />
    </el-card>

    <el-dialog v-model="sourceVisible" title="导入数据表" width="760px">
      <el-input
        v-model="sourceKeyword"
        clearable
        placeholder="按表名或说明筛选"
        style="margin-bottom: 12px"
        @keyup.enter="fetchSourceTables(1)"
      />
      <el-table
        row-key="table_name"
        :loading="sourceLoading"
        :data="sourceTables"
        border
        @selection-change="onSourceSelectionChange"
        ><el-table-column
          type="selection"
          width="55"
          reserve-selection /><el-table-column
          prop="table_name"
          label="表名" /><el-table-column
          prop="table_comment"
          label="表说明" /><el-table-column
          prop="engine"
          label="引擎"
          width="100" /><el-table-column
          prop="table_rows"
          label="估计行数"
          width="100" /><el-table-column
          prop="create_time"
          label="创建时间"
          width="180"
      /></el-table>
      <el-pagination
        v-model:current-page="sourcePagination.current"
        v-model:page-size="sourcePagination.pageSize"
        :total="sourcePagination.total"
        layout="total, prev, pager, next"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="fetchSourceTables"
      />
      <template #footer
        ><el-button @click="sourceVisible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="importLoading"
          @click="handleImport"
          >导入</el-button
        ></template
      >
    </el-dialog>

    <el-dialog
      v-model="editVisible"
      title="配置生成规则"
      width="1120px"
      :close-on-click-modal="false"
    >
      <el-form
        ref="formRef"
        :model="editForm"
        :rules="formRules"
        label-position="top"
      >
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item prop="table_comment" label="表说明">
              <el-input v-model="editForm.table_comment" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item prop="module_name" label="模块名称">
              <el-input v-model="editForm.module_name" />
              <div class="form-tip"
                >仅可选择已登记且声明 admin-web 前端贡献的模块；请先用
                module:create/module:check 建立并检查模块。</div
              >
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item prop="entity_name" label="实体名称">
              <el-input v-model="editForm.entity_name" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item prop="template_type" label="模板类型">
              <el-select v-model="editForm.template_type">
                <el-option value="crud">CRUD</el-option>
                <el-option value="tree">树形</el-option>
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item prop="data_owner" label="数据所有权">
              <el-select v-model="editForm.data_owner">
                <el-option value="tenant-orm" label="租户业务数据" />
                <el-option value="platform" label="平台控制数据" />
                <el-option value="instance" label="实例配置数据" />
                <el-option value="shared" label="全局共享数据" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item prop="target_edition" label="目标 Edition">
              <el-select v-model="editForm.target_edition">
                <el-option value="standalone" label="Standalone" />
                <el-option value="multi-tenant" label="Multi-tenant" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item prop="author" label="作者">
              <el-input v-model="editForm.author" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="软删除能力">
              <el-switch v-model="editForm.soft_delete.enabled" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="软删除字段">
              <el-select
                v-model="editForm.soft_delete.field"
                :disabled="!editForm.soft_delete.enabled"
                clearable
                placeholder="启用后必须选择"
              >
                <el-option
                  v-for="column in softDeleteColumns"
                  :key="column.id"
                  :value="column.column_name"
                  :label="`${column.column_name}（${column.column_type}）`"
                />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-divider content-position="left">字段配置</el-divider>
        <el-table row-key="id" :data="editForm.columns" border>
          <el-table-column label="字段" prop="column_name" :width="130" />
          <el-table-column label="类型" prop="column_type" :width="130" />
          <el-table-column label="说明" :width="220">
            <template #default="{ row: record }">
              <el-input v-model="record.column_comment" />
            </template>
          </el-table-column>
          <el-table-column label="列表" :width="80">
            <template #default="{ row: record }">
              <el-switch
                v-model="record.is_lists"
                :active-value="1"
                :inactive-value="0"
              />
            </template>
          </el-table-column>
          <el-table-column label="查询" :width="80">
            <template #default="{ row: record }">
              <el-switch
                v-model="record.is_query"
                :active-value="1"
                :inactive-value="0"
              />
            </template>
          </el-table-column>
          <el-table-column label="新增" :width="80">
            <template #default="{ row: record }">
              <el-switch
                v-model="record.is_insert"
                :active-value="1"
                :inactive-value="0"
              />
            </template>
          </el-table-column>
          <el-table-column label="编辑" :width="80">
            <template #default="{ row: record }">
              <el-switch
                v-model="record.is_update"
                :active-value="1"
                :inactive-value="0"
              />
            </template>
          </el-table-column>
          <el-table-column label="查询条件" :width="130">
            <template #default="{ row: record }">
              <el-select v-model="record.query_type" style="width: 115px">
                <el-option
                  v-for="item in queryTypes"
                  :key="item"
                  :value="item"
                  >{{ item }}</el-option
                >
              </el-select>
            </template>
          </el-table-column>
          <el-table-column label="控件" :width="130">
            <template #default="{ row: record }">
              <el-select v-model="record.view_type" style="width: 115px">
                <el-option
                  v-for="item in viewTypes"
                  :key="item"
                  :value="item"
                  >{{ item }}</el-option
                >
              </el-select>
            </template>
          </el-table-column>
          <el-table-column label="字典标识" :width="160">
            <template #default="{ row: record }">
              <el-input v-model="record.dict_type" />
            </template>
          </el-table-column>
        </el-table>

        <template v-if="editForm.template_type === 'tree'">
          <el-divider content-position="left">树形配置</el-divider>
          <el-row :gutter="16">
            <el-col :span="8">
              <el-form-item label="主键字段">
                <el-select v-model="editForm.tree_config.id_field">
                  <el-option
                    v-for="column in editForm.columns"
                    :key="column.id"
                    :value="column.column_name"
                    >{{ column.column_name }}</el-option
                  >
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="父级字段">
                <el-select v-model="editForm.tree_config.parent_field">
                  <el-option
                    v-for="column in editForm.columns"
                    :key="column.id"
                    :value="column.column_name"
                    >{{ column.column_name }}</el-option
                  >
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="名称字段">
                <el-select v-model="editForm.tree_config.name_field">
                  <el-option
                    v-for="column in editForm.columns"
                    :key="column.id"
                    :value="column.column_name"
                    >{{ column.column_name }}</el-option
                  >
                </el-select>
              </el-form-item>
            </el-col>
          </el-row>
        </template>

        <el-divider content-position="left">模型关系</el-divider>
        <el-space direction="vertical" fill>
          <el-space
            v-for="(relation, index) in editForm.relations"
            :key="relationKey(relation, index)"
            fill
          >
            <el-select
              v-model="relation.target_table_id"
              placeholder="目标配置"
              style="width: 200px"
            >
              <el-option
                v-for="model in models"
                :key="model.id"
                :value="model.id"
                :label="`${model.entity_name}（${model.table_name}）`"
                >{{ model.entity_name }}（{{ model.table_name }}）</el-option
              >
            </el-select>
            <el-input
              v-model="relation.name"
              placeholder="关系名称"
              style="width: 150px"
            />
            <el-select v-model="relation.type" style="width: 130px">
              <el-option value="belongsTo">belongsTo</el-option>
              <el-option value="hasOne">hasOne</el-option>
              <el-option value="hasMany">hasMany</el-option>
            </el-select>
            <el-input
              v-model="relation.local_key"
              placeholder="本地字段"
              style="width: 130px"
            />
            <el-input
              v-model="relation.foreign_key"
              placeholder="目标字段"
              style="width: 130px"
            />
            <el-button link type="danger" @click="removeRelation(index)"
              >删除</el-button
            >
          </el-space>
          <el-button plain @click="addRelation">新增关系</el-button>
        </el-space>
      </el-form>
      <template #footer
        ><el-button @click="editVisible = false">取消</el-button
        ><el-button type="primary" :loading="saveLoading" @click="handleSave"
          >保存</el-button
        ></template
      >
    </el-dialog>

    <el-dialog v-model="previewVisible" title="代码预览" width="1080px">
      <el-tabs
        v-if="previewFiles.length"
        v-model="previewActiveKey"
        type="card"
      >
        <el-tab-pane
          v-for="file in previewFiles"
          :key="file.path"
          :name="file.path"
          :label="file.path"
        >
          <el-text style="display: block; margin-bottom: 8px">{{
            file.path
          }}</el-text>
          <el-button
            link
            size="small"
            style="margin-bottom: 8px"
            @click="copyPreview(file.content)"
          >
            复制
          </el-button>
          <el-tag type="primary" style="margin-bottom: 8px">
            {{ file.language }}
          </el-tag>
          <pre class="code-preview"><code>{{ file.content }}</code></pre>
        </el-tab-pane>
      </el-tabs>
      <el-empty v-else description="暂无预览" />
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import { Plus } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    deleteGenerator,
    downloadGenerator,
    generateGenerator,
    getGeneratorDetail,
    getGeneratorList,
    getGeneratorModels,
    getGeneratorSourceTables,
    importGeneratorTables,
    previewGenerator,
    syncGenerator,
    updateGenerator,
    type GeneratorColumn,
    type GeneratorGenerateResult,
    type GeneratorModel,
    type GeneratorPreviewFile,
    type GeneratorRecord,
    type GeneratorRelation,
    type GeneratorSourceTable,
    type GeneratorUpdateForm,
  } from '@/api/system/generator';

  const { loading, setLoading } = useLoading(true);
  const renderData = ref<GeneratorRecord[]>([]);
  const keyword = ref('');
  const selectedKeys = ref<number[]>([]);
  const generatorTable = ref<{ clearSelection: () => void }>();
  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
  });

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const { data } = await getGeneratorList({
        keyword: keyword.value || undefined,
        page_no: page,
        page_size: pagination.pageSize,
      });
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
      selectedKeys.value = selectedKeys.value.filter((id) =>
        renderData.value.some((row) => row.id === id)
      );
    } catch {
      // The shared API interceptor already reports the request failure.
    } finally {
      setLoading(false);
    }
  };
  fetchData();
  const onPageChange = (page: number) => fetchData(page);
  const onSelectionChange = (rows: GeneratorRecord[]) => {
    selectedKeys.value = rows.map((row) => row.id);
  };

  const sourceVisible = ref(false);
  const sourceLoading = ref(false);
  const importLoading = ref(false);
  const sourceKeyword = ref('');
  const sourceTables = ref<GeneratorSourceTable[]>([]);
  const sourceSelectedKeys = ref<string[]>([]);
  const sourcePagination = reactive({
    current: 1,
    pageSize: 10,
    total: 0,
    showTotal: true,
  });
  const fetchSourceTables = async (page = 1) => {
    sourceLoading.value = true;
    try {
      const { data } = await getGeneratorSourceTables({
        keyword: sourceKeyword.value || undefined,
        page_no: page,
        page_size: sourcePagination.pageSize,
      });
      sourceTables.value = data.lists;
      sourcePagination.current = data.pageNo;
      sourcePagination.total = data.count;
    } catch {
      // The shared API interceptor already reports the request failure.
    } finally {
      sourceLoading.value = false;
    }
  };
  const onSourceSelectionChange = (rows: GeneratorSourceTable[]) => {
    sourceSelectedKeys.value = rows.map((row) => row.table_name);
  };
  const openSourceTables = async () => {
    sourceVisible.value = true;
    sourceSelectedKeys.value = [];
    await fetchSourceTables(1);
  };
  const handleImport = async () => {
    if (!sourceSelectedKeys.value.length) {
      ElMessage.warning('请选择至少一张数据表');
      return false;
    }
    importLoading.value = true;
    try {
      await importGeneratorTables(sourceSelectedKeys.value);
      ElMessage.success('导入成功');
      sourceVisible.value = false;
      await fetchData(1);
      return true;
    } catch {
      return false;
    } finally {
      importLoading.value = false;
    }
  };

  const editVisible = ref(false);
  const saveLoading = ref(false);
  const formRef = ref<FormInstance>();
  const models = ref<GeneratorModel[]>([]);
  const editForm = reactive<
    GeneratorUpdateForm & {
      columns: GeneratorColumn[];
      relations: GeneratorRelation[];
      tree_config: Record<string, string>;
    }
  >({
    id: 0,
    table_comment: '',
    module_name: '',
    entity_name: '',
    template_type: 'crud',
    data_owner: '' as GeneratorUpdateForm['data_owner'],
    target_edition: '' as GeneratorUpdateForm['target_edition'],
    author: '',
    tree_config: { id_field: '', parent_field: '', name_field: '' },
    relations: [],
    soft_delete: { enabled: false, field: '' },
    columns: [],
  });
  const formRules = {
    table_comment: [{ required: true, message: '表说明不能为空' }],
    module_name: [{ required: true, message: '模块名称不能为空' }],
    entity_name: [{ required: true, message: '实体名称不能为空' }],
    template_type: [{ required: true, message: '模板类型不能为空' }],
    data_owner: [{ required: true, message: '请选择数据所有权' }],
    target_edition: [{ required: true, message: '请选择目标 Edition' }],
  };
  const openEdit = async (record: GeneratorRecord) => {
    try {
      const [{ data }, modelResult] = await Promise.all([
        getGeneratorDetail(record.id),
        getGeneratorModels(),
      ]);
      Object.assign(editForm, {
        id: data.id,
        table_comment: data.table_comment,
        module_name: data.module_name,
        entity_name: data.entity_name,
        template_type: data.template_type === 'tree' ? 'tree' : 'crud',
        data_owner: data.data_owner,
        target_edition: data.target_edition,
        author: data.author || '',
        tree_config: {
          id_field: data.tree_config?.id_field || '',
          parent_field: data.tree_config?.parent_field || '',
          name_field: data.tree_config?.name_field || '',
        },
        relations: (data.relations || []).map((relation) => ({ ...relation })),
        soft_delete: {
          enabled: data.soft_delete?.enabled === true,
          field: data.soft_delete?.field || '',
        },
        columns: (data.columns || []).map((column) => ({ ...column })),
      });
      models.value = modelResult.data.filter(
        (model) =>
          model.id !== data.id && model.module_name === data.module_name
      );
      editVisible.value = true;
    } catch {
      // The shared API interceptor already reports the request failure.
    }
  };
  const handleSave = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return false;
    saveLoading.value = true;
    try {
      await updateGenerator({
        ...editForm,
        columns: editForm.columns.map((column) => ({
          id: column.id,
          column_comment: column.column_comment,
          is_required: column.is_required,
          is_insert: column.is_insert,
          is_update: column.is_update,
          is_lists: column.is_lists,
          is_query: column.is_query,
          query_type: column.query_type,
          view_type: column.view_type,
          dict_type: column.dict_type,
        })),
      });
      ElMessage.success('保存成功');
      editVisible.value = false;
      await fetchData(pagination.current);
      return true;
    } catch {
      return false;
    } finally {
      saveLoading.value = false;
    }
  };
  const addRelation = () => {
    const first = models.value[0];
    if (!first) {
      ElMessage.warning('暂无可关联的生成配置');
      return;
    }
    editForm.relations.push({
      target_table_id: first.id,
      name: '',
      type: 'belongsTo',
      local_key: 'id',
      foreign_key: 'id',
    });
  };
  const removeRelation = (index: number) => editForm.relations.splice(index, 1);
  const relationKey = (relation: GeneratorRelation, index: number) =>
    `${relation.target_table_id}-${index}`;
  const softDeleteColumns = computed(() =>
    editForm.columns.filter(
      (column) => column.is_pk !== 1 && column.column_name !== 'tenant_id'
    )
  );

  const handleSync = async (record: GeneratorRecord) => {
    try {
      await syncGenerator(record.id);
      ElMessage.success('同步成功');
      await fetchData(pagination.current);
    } catch {
      // The shared API interceptor already reports the request failure.
    }
  };
  const handleDelete = async (record: GeneratorRecord) => {
    try {
      await deleteGenerator([record.id]);
      ElMessage.success('删除成功');
      selectedKeys.value = selectedKeys.value.filter((id) => id !== record.id);
      await fetchData(pagination.current);
    } catch {
      // The shared API interceptor already reports the request failure.
    }
  };

  const previewVisible = ref(false);
  const previewFiles = ref<GeneratorPreviewFile[]>([]);
  const previewActiveKey = ref('');
  const openPreview = async (record: GeneratorRecord) => {
    try {
      const { data } = await previewGenerator(record.id);
      previewFiles.value = data;
      previewActiveKey.value = data[0]?.path || '';
      previewVisible.value = true;
    } catch {
      previewFiles.value = [];
      previewActiveKey.value = '';
    }
  };
  const copyPreview = async (content: string) => {
    await navigator.clipboard.writeText(content);
    ElMessage.success('已复制');
  };

  const generateLoading = ref(false);
  const generateSelected = async () => {
    if (!selectedKeys.value.length) return;
    generateLoading.value = true;
    try {
      const { data } = await generateGenerator(selectedKeys.value);
      // The download is deliberately consumed immediately; the server token is one-shot.
      // eslint-disable-next-line no-use-before-define
      await downloadGenerated(data);
      ElMessage.success('代码已生成并下载');
      generatorTable.value?.clearSelection();
      selectedKeys.value = [];
    } catch {
      // The shared API interceptor already reports the request failure.
    } finally {
      generateLoading.value = false;
    }
  };
  const downloadGenerated = async (result: GeneratorGenerateResult) => {
    const blob = await downloadGenerator(result.download_token);
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = result.file_name;
    anchor.rel = 'noopener';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
  };

  const queryTypes = ['=', '<>', '>', '>=', '<', '<=', 'like', 'between'];
  const viewTypes = [
    'input',
    'textarea',
    'select',
    'radio',
    'checkbox',
    'switch',
    'date',
    'datetime',
    'number',
  ];
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .code-preview {
    max-height: 62vh;
    margin: 0;
    padding: 16px;
    overflow: auto;
    color: var(--color-text-1);
    background: var(--color-fill-2);
    border-radius: 4px;
    white-space: pre;
  }
</style>
