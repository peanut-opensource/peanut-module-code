import { defineAsyncComponent } from 'vue';

export const RichTextFilePicker = defineAsyncComponent(
  () => import('../components/RichTextFilePicker.vue')
);
export type { StableMediaReference } from '@peanut-admin/peanut-content-tools/surface';
