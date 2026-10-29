import assert from 'node:assert/strict';
import test from 'node:test';
import {
  mkdtempSync,
  readFileSync,
  realpathSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  createPcRenderingOptions,
  createPcPageRules,
  pcPublicSsrPages,
  isPcPrivateRoute,
  pcPrivateRouteRoots,
} from '../../pc/utils/rendering-policy.ts';
import { readClientEnvironment } from '../client-environment.ts';

const root = fileURLToPath(new URL('../../', import.meta.url));

test('the default renders only registered public display routes on the server', () => {
  const config = createPcRenderingOptions(undefined);
  assert.equal(config.ssr, true);
  assert.deepEqual(
    [...pcPrivateRouteRoots],
    ['/user', '/account', '/login', '/oauth', '/recharge']
  );
  assert.equal(config.routeRules['/**'].ssr, false);
  assert.equal(
    Object.values(config.routeRules).some((rule) => rule.ssr === true),
    false
  );
  assert.equal(config.routeRules['/policy/**'], undefined);
});

test('hybrid is an explicit supported build choice', () => {
  assert.deepEqual(
    createPcRenderingOptions('hybrid'),
    createPcRenderingOptions(undefined)
  );
});

test('spa switches rendering, without claiming that a Node build becomes static', () => {
  const config = createPcRenderingOptions('spa');
  assert.equal(config.ssr, false);
  assert.deepEqual(config.routeRules['/'], { ssr: false, prerender: true });
  assert.equal('nitro' in config, false);
  assert.equal('app' in config, false);
});

test('invalid and ambiguous mode values are rejected, not silently replaced', () => {
  for (const mode of [
    '',
    'false',
    'true',
    'SSR',
    'HYBRID',
    'hybrid ',
    'auto',
    null,
    false,
  ]) {
    assert.throws(
      () => createPcRenderingOptions(mode),
      /PC_RENDER_MODE_INVALID/
    );
  }
});

test('private exact and nested routes disable SSR, prerendering and shared caching', () => {
  for (const mode of ['hybrid', 'spa']) {
    const { routeRules } = createPcRenderingOptions(mode);
    for (const base of pcPrivateRouteRoots) {
      for (const path of [base, `${base}/**`]) {
        assert.deepEqual(routeRules[path], {
          ssr: false,
          prerender: false,
          cache: false,
          headers: {
            'cache-control': 'private, no-store',
            'x-robots-tag': 'noindex, nofollow',
          },
        });
      }
    }
  }
});

test('Vue route matching covers slash variants and nested pages', () => {
  for (const base of pcPrivateRouteRoots) {
    for (const path of [
      base,
      `${base}/`,
      `${base}/info`,
      `${base}/orders/42`,
    ]) {
      assert.equal(isPcPrivateRoute(path), true, path);
    }
  }
});

test('similar public route names and backend paths are not captured', () => {
  for (const path of [
    '/',
    '/information',
    '/username',
    '/accounting',
    '/login-help',
    '/oauth2',
    '/recharge-plans',
    '/admin/user',
    '/api/user',
    '/platformapi/account',
  ]) {
    assert.equal(isPcPrivateRoute(path), false, path);
  }
});

test('returned rules do not share mutable objects across routes or config calls', () => {
  const a = createPcRenderingOptions('hybrid');
  const b = createPcRenderingOptions('hybrid');
  a.routeRules['/user'].headers['cache-control'] = 'bad';
  assert.equal(
    a.routeRules['/account'].headers['cache-control'],
    'private, no-store'
  );
  assert.equal(
    a.routeRules['/user/**'].headers['cache-control'],
    'private, no-store'
  );
  assert.equal(
    b.routeRules['/user'].headers['cache-control'],
    'private, no-store'
  );
});

test('the existing file loader reads mode from the explicitly selected file', () => {
  const dir = mkdtempSync(resolve(realpathSync(tmpdir()), 'peanut-pc-render-'));
  const path = resolve(dir, 'client.env');
  const previous = process.env.PEANUT_CLIENT_ENV_FILE;
  try {
    writeFileSync(path, 'NUXT_PC_RENDER_MODE=spa\n');
    process.env.PEANUT_CLIENT_ENV_FILE = path;
    assert.equal(readClientEnvironment('/not-used').NUXT_PC_RENDER_MODE, 'spa');
  } finally {
    if (previous === undefined) delete process.env.PEANUT_CLIENT_ENV_FILE;
    else process.env.PEANUT_CLIENT_ENV_FILE = previous;
    rmSync(dir, { recursive: true });
  }
});

test('ambient mode cannot override the selected client environment file', () => {
  const previous = process.env.NUXT_PC_RENDER_MODE;
  try {
    process.env.NUXT_PC_RENDER_MODE = 'spa';
    assert.throws(
      () => readClientEnvironment('/not-used'),
      /CLIENT_ENVIRONMENT_AMBIENT_VALUE_FORBIDDEN:NUXT_PC_RENDER_MODE/
    );
  } finally {
    if (previous === undefined) delete process.env.NUXT_PC_RENDER_MODE;
    else process.env.NUXT_PC_RENDER_MODE = previous;
  }
});

test('both distributed environment templates document and select hybrid', () => {
  for (const file of ['pc/.env.example', 'pc/.env.production']) {
    const text = readFileSync(resolve(root, file), 'utf8');
    assert.match(text, /^NUXT_PC_RENDER_MODE=hybrid$/m);
  }
});

// These remaining assertions inspect wiring, not a real Nuxt/browser render.
test('Nuxt uses the shared mode policy with the canonical root URL', () => {
  const source = readFileSync(resolve(root, 'pc/nuxt.config.ts'), 'utf8');
  assert.match(
    source,
    /createPcRenderingOptions\(fileEnv\.NUXT_PC_RENDER_MODE\)/
  );
  assert.match(source, /baseURL: '\/'/);
  assert.doesNotMatch(source, /\bssr:\s*true/);
});

test('client navigation updates the private-page indexing hint', () => {
  const source = readFileSync(resolve(root, 'pc/app.vue'), 'utf8');
  assert.match(source, /const route = useRoute\(\)/);
  assert.match(source, /useHead\(\(\) => \(\{/);
  assert.match(source, /isPcPrivateRoute\(route\.path\)/);
  assert.match(source, /name: 'robots', content: 'noindex, nofollow'/);
});

test('homepage hands data to Nuxt and distinguishes failure/loading from no articles', () => {
  const source = readFileSync(resolve(root, 'pc/pages/index.vue'), 'utf8');
  assert.match(source, /await useAsyncData\(/);
  assert.match(source, /'pc:public-index'/);
  assert.match(
    source,
    /getPcIndex<\{ data: DecorationComponent\[\] \}>\(request\)/
  );
  assert.match(source, /indexData\.value\?\.all/);
  assert.match(source, /v-if="indexPending"/);
  assert.match(source, /v-else-if="indexError"/);
  assert.doesNotMatch(source, /const indexData = await getPcIndex/);
  assert.doesNotMatch(source, /\.catch\(\(\) => null\)/);
});

const pagesDirectory = '/synthetic/pc/pages';
const publicPaths = [
  '/',
  '/information',
  '/information/:source()',
  '/information/detail/:id()',
  '/about',
];
const publicPages = pcPublicSsrPages.map((file, index) => ({
  file: `${pagesDirectory}/${file}`,
  path: publicPaths[index],
}));
test('resolved file routes explicitly register the public pages, with single-segment parameters', () => {
  const rules = createPcPageRules(publicPages, pagesDirectory, true);
  assert.deepEqual(Object.keys(rules), [
    '/',
    '/information',
    '/information/*',
    '/information/detail/*',
    '/about',
  ]);
  assert.ok(Object.values(rules).every((rule) => rule.ssr));
  assert.ok(
    Object.values(createPcPageRules(publicPages, pagesDirectory, false)).every(
      (rule) => !rule.ssr
    )
  );
});
test('new unregistered pages under public directories and private pages stay CSR', () => {
  const pages = [
    ...publicPages,
    ...[
      'information/settings.vue',
      'information/detail/orders.vue',
      'login.vue',
      'user/info.vue',
      'user/collection.vue',
      'account/security.vue',
      'recharge.vue',
      'policy/[type].vue',
    ].map((file) => ({
      file: `${pagesDirectory}/${file}`,
      path: '/' + file.replace(/\.vue$/u, '').replace('[type]', ':type()'),
    })),
  ];
  const rules = createPcPageRules(pages, pagesDirectory, true);
  for (const path of [
    '/information/settings',
    '/information/detail/orders',
    '/login',
    '/user/info',
    '/user/collection',
    '/account/security',
    '/recharge',
    '/policy/*',
  ])
    assert.equal(rules[path].ssr, false, path);
});
