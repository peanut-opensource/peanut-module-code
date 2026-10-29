import { http } from '@/utils/request';
import type { Article } from './index';

export type { Article };

export interface ArticleCate {
  id: number;
  name: string;
  image: string;
  sort: number;
}

export interface ArticleDetail extends Article {
  content: string;
  collect: boolean;
}

export interface ArticleListResult {
  lists: Article[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export interface ArticleListParams {
  cid?: number;
  keyword?: string;
  pageNo?: number;
  pageSize?: number;
}

export interface ArticleCollection {
  id: number;
  title: string;
  image: string;
  desc: string;
  author?: string;
  click: number;
  create_time: string;
  collect_time: string;
}

export interface CollectListResult {
  lists: ArticleCollection[];
  count: number;
  pageNo: number;
  pageSize: number;
}

interface ArticleCollectionWire extends Omit<ArticleCollection, 'id'> {
  id: number;
  article_id: number;
}

/** GET api/article/cate */
export function getArticleCate() {
  return http.get<ArticleCate[]>('api/article/cate', undefined, false);
}

/** GET api/article/lists */
export function getArticleLists(params: ArticleListParams = {}) {
  return http.get<ArticleListResult>(
    'api/article/lists',
    {
      ...(params.cid !== undefined ? { cid: params.cid } : {}),
      ...(params.keyword !== undefined ? { keyword: params.keyword } : {}),
      ...(params.pageNo !== undefined ? { page_no: params.pageNo } : {}),
      ...(params.pageSize !== undefined ? { page_size: params.pageSize } : {}),
    },
    false
  );
}

/** GET api/article/detail?id=xxx */
export function getArticleDetail(id: number) {
  return http.get<ArticleDetail>('api/article/detail', { id }, false);
}

/** POST api/article/addCollect */
export function addCollect(id: number) {
  return http.post('api/article/addCollect', { id });
}

/** POST api/article/cancelCollect */
export function cancelCollect(id: number) {
  return http.post('api/article/cancelCollect', { id });
}

/** GET api/article/collect */
export async function getCollectLists(
  params: { pageNo?: number; pageSize?: number } = {}
) {
  const page = await http.get<
    Omit<CollectListResult, 'lists'> & { lists: ArticleCollectionWire[] }
  >('api/article/collect', {
    ...(params.pageNo !== undefined ? { page_no: params.pageNo } : {}),
    ...(params.pageSize !== undefined ? { page_size: params.pageSize } : {}),
  });
  return {
    ...page,
    lists: page.lists.map(({ article_id: id, ...item }) => ({ ...item, id })),
  };
}

/** GET api/search/hotLists */
export function getHotSearch() {
  return http.get<{
    status: number;
    data: Array<{ id: number; name: string }>;
  }>('api/search/hotLists', undefined, false);
}
