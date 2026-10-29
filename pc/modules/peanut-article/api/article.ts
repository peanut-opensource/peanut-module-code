export type ArticleRequestClient = {
  get<T = unknown>(
    url: string,
    params?: Record<string, unknown>,
    auth?: boolean
  ): Promise<T>;
  post<T = unknown>(
    url: string,
    body?: Record<string, unknown> | null,
    auth?: boolean
  ): Promise<T>;
};

export interface PageData<T> {
  lists: T[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export interface Article {
  id: number;
  cid: number;
  cate_name?: string;
  title: string;
  image: string;
  desc: string;
  abstract?: string;
  author?: string;
  click: number;
  create_time: string;
}

export interface ArticleDetail extends Article {
  content: string;
}

export interface ArticleCategory {
  id: number;
  name: string;
}

export interface ArticleCollectionItem {
  id: number;
  title: string;
  image: string;
  desc: string;
  click: number;
  create_time: string;
  collect_time: string;
}

export interface ArticleListParams {
  cid?: number;
  keyword?: string;
  pageNo?: number;
  pageSize?: number;
}

interface ArticleCollectionWireItem extends Omit<ArticleCollectionItem, 'id'> {
  id: number;
  article_id: number;
}

function publicArticle(article: Article): Article {
  // 仅投影公开内容；上游新增字段也不能被自动写入公开 payload。
  return {
    id: article.id,
    cid: article.cid,
    cate_name: article.cate_name,
    title: article.title,
    image: article.image,
    desc: article.desc,
    abstract: article.abstract,
    author: article.author,
    click: article.click,
    create_time: article.create_time,
  };
}

export function getPcIndex<TDecoration>(client: ArticleRequestClient) {
  return client
    .get<{
      all?: Article[];
      article?: Article[];
      decorate?: TDecoration;
    }>('api/pc/index', undefined, false)
    .then((data) => ({
      all: data.all?.map(publicArticle),
      article: data.article?.map(publicArticle),
      decorate: data.decorate,
    }));
}

export function getArticleCategories(client: ArticleRequestClient) {
  return client.get<ArticleCategory[]>('api/article/cate', undefined, false);
}

export function getArticles(
  client: ArticleRequestClient,
  params: ArticleListParams = {}
) {
  return client
    .get<PageData<Article>>(
      'api/article/lists',
      {
        cid: params.cid,
        keyword: params.keyword,
        page_no: params.pageNo,
        page_size: params.pageSize,
      },
      false
    )
    .then((page) => ({ ...page, lists: page.lists.map(publicArticle) }));
}

export function getArticleDetail(client: ArticleRequestClient, id: number) {
  return client
    .get<ArticleDetail & { collect?: boolean }>(
      'api/article/detail',
      { id },
      false
    )
    .then((article) => ({
      ...publicArticle(article),
      content: article.content,
    }));
}

/** 仅浏览器个人扩展消费既有鉴权详情接口，不将个人响应保存到公开内容。 */
export async function getArticleCollectState(
  client: ArticleRequestClient,
  id: number
) {
  const detail = await client.get<unknown>('api/article/detail', { id });
  if (
    typeof detail !== 'object' ||
    detail === null ||
    !('collect' in detail) ||
    typeof detail.collect !== 'boolean'
  ) {
    throw new Error('ARTICLE_COLLECT_STATE_INVALID');
  }
  return detail.collect;
}

/** 只有明确的不存在响应转为空状态；服务、权限、网络和解析异常继续交给页面错误处理。 */
export async function getArticleDetailOrNull(
  client: ArticleRequestClient,
  id: number
) {
  try {
    return await getArticleDetail(client, id);
  } catch (error) {
    if (
      typeof error === 'object' &&
      error !== null &&
      'kind' in error &&
      error.kind === 'business' &&
      'code' in error &&
      error.code === '40400'
    )
      return null;
    throw error;
  }
}

export function addArticleCollect(client: ArticleRequestClient, id: number) {
  return client.post('api/article/addCollect', { id });
}

export function cancelArticleCollect(client: ArticleRequestClient, id: number) {
  return client.post('api/article/cancelCollect', { id });
}

export async function getArticleCollections(
  client: ArticleRequestClient,
  pageNo = 1,
  pageSize = 12
): Promise<PageData<ArticleCollectionItem>> {
  const page = await client.get<PageData<ArticleCollectionWireItem>>(
    'api/article/collect',
    { page_no: pageNo, page_size: pageSize }
  );
  return {
    ...page,
    lists: page.lists.map(({ article_id: id, ...item }) => ({ ...item, id })),
  };
}
