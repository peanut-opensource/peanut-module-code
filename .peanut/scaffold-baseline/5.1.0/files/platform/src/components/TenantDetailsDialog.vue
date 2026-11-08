<script setup lang="ts">
  defineProps<{
    modelValue: boolean;
    detail: Record<string, unknown> | null;
  }>();
  defineEmits<{ (event: 'update:modelValue', value: boolean): void }>();
  const fields = [
    ['display_name', '显示名称'],
    ['name', '内部名称'],
    ['code', '租户编码'],
    ['status', '状态'],
    ['locale', '语言'],
    ['timezone', '时区'],
    ['activated_at', '启用时间'],
    ['suspended_at', '暂停时间'],
    ['closed_at', '关闭时间'],
    ['created_at', '创建时间'],
    ['updated_at', '更新时间'],
  ] as const;
  function display(key: string, value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (key === 'status' && typeof value === 'string')
      return (
        (
          {
            active: '运行中',
            provisioning: '开通中',
            suspended: '已暂停',
            closed: '已关闭',
          } as Record<string, string>
        )[value] || value
      );
    return typeof value === 'string' || typeof value === 'number'
      ? String(value)
      : '—';
  }
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    title="租户详情"
    width="min(760px, 94vw)"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <el-descriptions v-if="detail" :column="1" border>
      <el-descriptions-item
        v-for="[key, label] in fields"
        :key="key"
        :label="label"
        >{{ display(key, detail[key]) }}</el-descriptions-item
      >
    </el-descriptions>
    <el-collapse v-if="detail" class="tenant-technical">
      <el-collapse-item title="技术信息" name="technical">
        <el-descriptions :column="1">
          <el-descriptions-item label="记录编号">{{
            display('id', detail.id)
          }}</el-descriptions-item>
          <el-descriptions-item label="数据修订">{{
            display('revision', detail.revision)
          }}</el-descriptions-item>
          <el-descriptions-item label="安全修订">{{
            display('security_revision', detail.security_revision)
          }}</el-descriptions-item>
          <el-descriptions-item label="授权修订">{{
            display('authorization_revision', detail.authorization_revision)
          }}</el-descriptions-item>
        </el-descriptions>
      </el-collapse-item>
    </el-collapse>
    <template #footer
      ><el-button @click="$emit('update:modelValue', false)"
        >关闭</el-button
      ></template
    >
  </el-dialog>
</template>

<style scoped>
  .tenant-technical {
    margin-top: 18px;
  }
</style>
