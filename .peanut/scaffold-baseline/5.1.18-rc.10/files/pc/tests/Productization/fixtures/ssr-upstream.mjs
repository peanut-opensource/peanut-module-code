import { createServer } from 'node:http';

// Explicit HTTP fixture only: no global fetch patch and no real tenant data.
export function createSsrUpstream() {
  const requests = [];
  const state = { mode: 'normal' };
  const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://fixture.invalid');
    // Model the installed ThinkPHP Request::host contract at this trusted
    // loopback upstream: forwarded host first, transport Host otherwise.
    const host =
      request.headers['x-forwarded-host'] || request.headers.host || '';
    const tenant = host === 'tenant-a.example.test' ? 'Tenant A' : 'Tenant B';
    requests.push({
      path: `${url.pathname}${url.search}`,
      host: request.headers.host || '',
      forwardedHost: request.headers['x-forwarded-host'] || null,
      forwardedProto: request.headers['x-forwarded-proto'] || null,
      cookie: request.headers.cookie || null,
      authorization: request.headers.authorization || null,
      token: request.headers.token || null,
    });
    const send = (status, code, data) => {
      response.writeHead(status, { 'content-type': 'application/json' });
      response.end(JSON.stringify({ code, msg: 'synthetic', data }));
    };
    if (!['tenant-a.example.test', 'tenant-b.example.test'].includes(host)) {
      return send(421, 42100, null);
    }
    if (url.pathname === '/api/index/config') {
      if (state.mode === 'config-failure') return send(503, 50300, null);
      return send(200, 20000, {
        domain: host,
        website: {
          name: tenant,
          web_favicon: '/brand/favicon.svg',
          web_logo: '/brand/logo.svg',
          login_image: '/brand/login-background.svg',
          shop_name: `${tenant} shop`,
          shop_logo: '/brand/logo.svg',
          pc_logo: '/brand/logo.svg',
          pc_title: `${tenant} portal`,
          pc_ico: '/brand/favicon.svg',
          pc_desc: `${tenant} description`,
          pc_keywords: tenant,
          h5_favicon: '/brand/favicon.svg',
          slogan: tenant,
          copyright: tenant,
          official_url: '',
          github_url: '',
        },
        login: { login_way: [1] },
        version: 'synthetic',
      });
    }
    if (url.pathname === '/api/article/detail') {
      if (request.headers.token || request.headers.authorization) {
        if (state.mode === 'favorite-failure') return send(503, 50300, null);
        if (state.mode === 'favorite-expired') return send(401, 40100, null);
      }
      if (state.mode === 'missing') return send(404, 40400, null);
      if (state.mode === 'service-failure') return send(503, 50300, null);
      // Different delays make concurrent request identity crossover observable.
      await new Promise((resolve) =>
        setTimeout(resolve, tenant === 'Tenant A' ? 30 : 5)
      );
      return send(200, 20000, {
        id: 1,
        cid: 1,
        cate_name: 'News',
        title: `${tenant} article`,
        image: '/brand/logo.svg',
        desc: `${tenant} summary`,
        abstract: `${tenant} abstract`,
        author: tenant,
        click: 1,
        create_time: '2026-09-22 00:00:00',
        collect: true,
        content: `<p>${tenant} 正文</p><img src=x onerror=alert(1)><script>alert('xss')</script>`,
      });
    }
    const article = {
      id: 1,
      cid: 1,
      cate_name: 'News',
      title: `${tenant} article`,
      image: '/brand/logo.svg',
      desc: `${tenant} summary`,
      author: tenant,
      click: 1,
      create_time: '2026-09-22 00:00:00',
      collect: true,
    };
    if (url.pathname === '/api/pc/index')
      return send(200, 20000, { all: [article], decorate: { data: [] } });
    if (url.pathname === '/api/article/cate')
      return send(200, 20000, [{ id: 1, name: 'News' }]);
    if (url.pathname === '/api/article/lists')
      return send(200, 20000, {
        lists: [article],
        count: 1,
        pageNo: 1,
        pageSize: 12,
      });
    if (
      url.pathname === '/api/article/addCollect' ||
      url.pathname === '/api/article/cancelCollect'
    ) {
      if (state.mode === 'favorite-write-failure')
        return send(503, 50300, null);
      return send(200, 20000, null);
    }
    if (url.pathname === '/api/index/policy')
      return send(200, 20000, {
        title: 'Synthetic policy',
        content: '<p>Public policy</p>',
      });
    if (url.pathname === '/api/user/info')
      return send(200, 20000, {
        id: 77,
        nickname: 'Synthetic Private Member',
        avatar: '/brand/avatar-member.svg',
        mobile: '15500000077',
        real_name: 'Private Name',
        sex: 0,
        birthday: '',
        user_money: '12.34',
      });
    if (url.pathname === '/api/article/collect')
      return send(200, 20000, { lists: [], count: 0, pageNo: 1, pageSize: 12 });
    if (url.pathname === '/api/recharge/template') return send(200, 20000, []);
    if (url.pathname === '/api/recharge/config')
      return send(200, 20000, { status: 1, min_amount: 1 });
    return send(404, 40400, null);
  });
  return { server, state, requests };
}
