import { defineAsyncComponent } from 'vue';

export const RichTextFilePicker = defineAsyncComponent(
  () => import('../components/RichTextFilePicker.vue')
);
export type { StableMediaReference } from '@/modules/peanut-content-tools/src/contracts';
