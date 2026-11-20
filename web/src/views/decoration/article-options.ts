import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import {
  getDecorationArticleOptions,
  type DecorationArticleOption,
} from '@/api/decoration';
import { hasPermission } from '@/hooks/permission';

export default function useDecorationArticleOptions() {
  const route = useRoute();
  const articleOptions = ref<DecorationArticleOption[]>([]);
  const canRead = computed(() =>
    hasPermission('decoration/mobile/article', route.path)
  );
  const articleOptionLabel = computed(() => {
    if (!canRead.value) return '文章（无权查看可选文章）';
    return articleOptions.value.length ? '文章' : '文章（暂无可选项）';
  });

  const loadArticleOptions = async () => {
    if (!canRead.value) return;
    const { data } = await getDecorationArticleOptions();
    articleOptions.value = data;
  };

  return { articleOptions, articleOptionLabel, loadArticleOptions };
}
