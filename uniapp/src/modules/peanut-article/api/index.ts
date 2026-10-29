import { http } from '@/utils/request';
import type { DecorationPage } from '@/utils/decoration';

export interface Article {
  id: number;
  cid: number;
  cate_name?: string;
  title: string;
  image: string;
  desc: string;
  author?: string;
  click: number;
  create_time: string;
  collect?: boolean;
}

export interface IndexData {
  article: Article[];
  decorate: DecorationPage;
}

/** GET api/index/index — home page data */
export function getIndexData() {
  return http.get<IndexData>('api/index/index', undefined, false);
}
