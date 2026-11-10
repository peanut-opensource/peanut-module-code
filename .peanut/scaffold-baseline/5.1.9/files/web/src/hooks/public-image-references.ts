import { ref } from 'vue';
import { ElMessage } from 'element-plus';
import type { FileRecord } from '@/modules/peanut-file/api';

/** Editors save stable references and keep delivery URLs only for preview. */
export default function usePublicImageReferences() {
  const urls = ref<Record<string, string>>({});
  return {
    replace: (value: Record<string, string> = {}) => {
      urls.value = { ...value };
    },
    preview: (value: unknown): string =>
      typeof value === 'string' ? urls.value[value] || '' : '',
    select: (
      selectedUrls: string[],
      files: FileRecord[],
      current = ''
    ): string => {
      const file = files[0];
      if (!file?.file_key || file.access_type !== 'public') {
        ElMessage.error('请选择公开的图片素材');
        return current;
      }
      urls.value[file.file_key] = selectedUrls[0] || '';
      return file.file_key;
    },
  };
}
