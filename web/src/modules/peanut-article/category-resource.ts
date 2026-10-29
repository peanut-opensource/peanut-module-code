import type { AsyncListQuery } from '@peanut-admin/vue';
import {
  addArticleCate,
  deleteArticleCate,
  editArticleCate,
  getArticleCateDetail,
  getArticleCateList,
  updateArticleCateStatus,
  type ArticleCateRecord,
} from './api';

export type ArticleCategoryFilters = Record<never, never>;

/** Business-owned declaration consumed by the ordinary Vue page. */
export const articleCategoryResource = {
  key: 'peanut.article.category',
  filters: [] as const,
  columns: [
    { key: 'name', labelKey: 'articleCate.columns.name', width: undefined },
    {
      key: 'article_count',
      labelKey: 'articleCate.columns.articleCount',
      width: 120,
    },
    { key: 'sort', labelKey: 'articleCate.columns.sort', width: 100 },
  ] as const,
  async list(query: AsyncListQuery<ArticleCategoryFilters>) {
    const { data } = await getArticleCateList(
      {
        page_no: query.page,
        page_size: query.pageSize,
      },
      query.signal
    );
    return {
      items: data.lists,
      total: data.count,
      page: data.pageNo,
      pageSize: data.pageSize,
    };
  },
  detail: (id: number, signal: AbortSignal) =>
    getArticleCateDetail(id, signal).then(({ data }) => data),
  create: (record: Partial<ArticleCateRecord>, signal: AbortSignal) =>
    addArticleCate(record, signal),
  update: (record: Partial<ArticleCateRecord>, signal: AbortSignal) =>
    editArticleCate(record, signal),
  remove: (id: number, signal: AbortSignal) => deleteArticleCate(id, signal),
  updateStatus: (id: number, isShow: number, signal: AbortSignal) =>
    updateArticleCateStatus(id, isShow, signal),
};
