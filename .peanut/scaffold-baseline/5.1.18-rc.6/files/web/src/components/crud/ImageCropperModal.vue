<template>
  <el-dialog
    :model-value="modelValue"
    title="裁剪图片"
    destroy-on-close
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <div class="image-cropper"
      ><VueCropper
        v-if="modelValue && src"
        ref="cropper"
        :img="src"
        :auto-crop="true"
        output-type="png"
    /></div>
    <template #footer
      ><el-button @click="$emit('update:modelValue', false)">取消</el-button
      ><el-button type="primary" @click="confirm">确定</el-button></template
    >
  </el-dialog>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { VueCropper } from 'vue-cropper';
  import 'vue-cropper/dist/index.css';

  defineProps<{ modelValue: boolean; src: string }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: boolean): void;
    (event: 'confirm', blob: Blob): void;
  }>();
  const cropper = ref<{
    getCropBlob: (callback: (blob: Blob) => void) => void;
  }>();
  const confirm = () =>
    cropper.value?.getCropBlob((blob) => {
      emit('confirm', blob);
      emit('update:modelValue', false);
    });
</script>

<style scoped>
  .image-cropper {
    height: 400px;
  }
</style>
