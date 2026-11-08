<template>
  <div class="content">
    <el-result
      class="result"
      icon="warning"
      title="404"
      sub-title="not found"
    />
    <div class="operation-row">
      <el-button key="back" type="primary" @click="back"> back </el-button>
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { useRouter } from 'vue-router';

  const router = useRouter();
  const back = () => {
    // warning： Go to the node that has the permission
    router.push({ name: 'Workplace' });
  };
</script>

<style scoped lang="less">
  .content {
    // padding-top: 100px;
    position: absolute;
    top: 50%;
    left: 50%;
    margin-left: -95px;
    margin-top: -121px;
    text-align: center;
  }
</style>
