/** PC 的渲染方式与页面登记；不保存任何请求、用户或租户状态。 */
export const pcPrivateRouteRoots = Object.freeze([
  '/user',
  '/account',
  '/login',
  '/oauth',
  '/recharge',
] as const);

/** 接受 Vue Router 的规范化 path，不将 /username 等相似名称视为用户中心。 */
export function isPcPrivateRoute(path: string): boolean {
  return pcPrivateRouteRoots.some(
    (root) => path === root || path.startsWith(`${root}/`)
  );
}

// 唯一 SSR 登记按真实页面文件匹配，新增同目录页面不会继承公开资格。
export const pcPublicSsrPages = Object.freeze([
  'index.vue',
  '../modules/peanut-article/pages/information/index.vue',
  '../modules/peanut-article/pages/information/[source].vue',
  '../modules/peanut-article/pages/information/detail/[id].vue',
  'about.vue',
] as const);

export interface PcPageRoute {
  path: string;
  file?: string;
  children?: PcPageRoute[];
}

export function createPcPageRules(
  pages: PcPageRoute[],
  pagesDirectory: string,
  hybrid: boolean
) {
  const rules: Record<string, { ssr: boolean }> = {};
  function visit(routes: PcPageRoute[], parent = '') {
    for (const page of routes) {
      const path = page.path.startsWith('/')
        ? page.path
        : `${parent}/${page.path}`.replace(/\/+$/u, '') || '/';
      // Nuxt 的文件路由参数映射到 Nitro 原生的单段/多段匹配。
      const pattern = path.replace(/:[^/]+/gu, (parameter) =>
        parameter.includes('.*') ? '**' : '*'
      );
      const publicPage = pcPublicSsrPages.some(
        (file) =>
          page.file ===
          (file.startsWith('../')
            ? `${pagesDirectory.slice(
                0,
                pagesDirectory.lastIndexOf('/')
              )}/${file.slice(3)}`
            : `${pagesDirectory}/${file}`)
      );
      if (page.file)
        rules[pattern] = {
          ssr: hybrid && publicPage && !isPcPrivateRoute(path),
        };
      if (page.children) visit(page.children, path);
    }
  }
  visit(pages);
  return rules;
}

/**
 * hybrid：仅登记的展示页 SSR，其余页面 CSR；spa：全部客户端渲染。
 * 这是构建配置，不是运行时随意关闭 Node 的开关。静态部署仍需独立生成
 * 并验证页面回退和 API 代理；禁索引与禁缓存也不代替后端鉴权。
 */
export function createPcRenderingOptions(value: string | undefined) {
  const mode = value === undefined ? 'hybrid' : value;
  if (mode !== 'hybrid' && mode !== 'spa') {
    throw new Error('PC_RENDER_MODE_INVALID: expected hybrid or spa');
  }
  const routeRules: Record<string, Record<string, unknown>> = {
    '/**': { ssr: false },
  };
  if (mode === 'spa') {
    routeRules['/'] = { ssr: false, prerender: true };
  }
  for (const root of pcPrivateRouteRoots) {
    for (const path of [root, `${root}/**`]) {
      // 每个规则使用独立对象，避免后续配置合并修改其他规则。
      routeRules[path] = {
        ssr: false,
        prerender: false,
        cache: false,
        headers: {
          'cache-control': 'private, no-store',
          'x-robots-tag': 'noindex, nofollow',
        },
      };
    }
  }
  return { ssr: mode === 'hybrid', routeRules };
}
