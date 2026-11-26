import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import axios from 'axios';
import * as vue from 'vue';
import { compileScript, parse } from 'vue/compiler-sfc';
import * as peanutClient from '@peanut-admin/client';
import * as peanutVue from '@peanut-admin/vue';
import { transformWithEsbuild } from 'vite';

const updates = '/platformapi/instance-tools/modules/updates';
const refresh = '/platformapi/session/refresh';
const catalog = '/platformapi/developer-center/catalog';
const transportModules = await Promise.all(
  ['platform-shell-definitions', 'platform-access', 'tenant-license', 'permission-bundles', 'role-authorization-preview', 'platform'].map(async (name) => {
    const directory = name.startsWith('platform-') ? 'core' : 'api';
    const filename = fileURLToPath(
      new URL(`../src/${directory}/${name}.ts`, import.meta.url)
    );
    const { code } = await transformWithEsbuild(
      readFileSync(filename, 'utf8'),
      filename,
      {
        format: 'cjs',
        define: {
          'import.meta.env.VITE_API_BASE_URL': 'undefined',
          'import.meta.hot': 'undefined',
        },
      }
    );
    return { name, filename, code };
  })
);

const developerFilename = fileURLToPath(new URL('../src/components/DeveloperCenterPage.vue', import.meta.url));
const developer = parse(readFileSync(developerFilename, 'utf8'), { filename: developerFilename });
assert.deepEqual(developer.errors, []);
const developerModule = {
  name: 'developer-panel',
  filename: developerFilename,
  code: (await transformWithEsbuild(
    compileScript(developer.descriptor, { id: 'g18-developer-gates' }).content,
    developerFilename,
    { loader: 'ts', format: 'cjs' },
  )).code,
};

// Run the platform transport with its real Axios interceptors and Core
// session code. Only the network adapter and browser storage/lock are fixtures.
function fixture(t, adapter, loadDeveloper = false) {
  const storage = new Map();
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key) => storage.get(key) ?? null,
      setItem: (key, value) => storage.set(key, value),
      removeItem: (key) => storage.delete(key),
    },
  });
  Object.defineProperty(navigator, 'locks', {
    configurable: true,
    value: { request: (_name, operation) => operation() },
  });
  t.after(() => {
    delete globalThis.localStorage;
    delete navigator.locks;
  });

  const dependencies = {
    axios: {
      ...axios,
      create: (config) => axios.create({ ...config, adapter }),
    },
    '@peanut-admin/client': peanutClient,
    '@peanut-admin/vue': peanutVue,
    vue: { ...vue, onMounted: () => {} },
    'element-plus': { ElMessageBox: { confirm: async () => 'confirm' } },
    './StructuredDetails.vue': {},
  };
  const modules = loadDeveloper ? [...transportModules, developerModule] : transportModules;
  for (const { name, filename, code } of modules) {
    const module = { exports: {} };
    runInNewContext(
      code,
      {
        module,
        FormData,
        crypto,
        require(dependency) {
          if (dependency === '../core/platform-shell-definitions')
            return dependencies['./platform-shell-definitions'];
          if (dependency === '../api/platform') return dependencies['./platform'];
          assert.ok(Object.hasOwn(dependencies, dependency), dependency);
          return dependencies[dependency];
        },
      },
      { filename }
    );
    dependencies[`./${name}`] = module.exports;
  }
  const { api } = dependencies['./platform'];
  if (!loadDeveloper) return api;
  return {
    mountDeveloper(access) {
      const scope = vue.effectScope();
      t.after(() => scope.stop());
      return scope.run(() => dependencies['./developer-panel'].default.setup({
        canReadUpdates: true,
        canReadRuntime: false,
        canReadTasks: false,
        ...dependencies['./platform-access'].platformDeveloperModuleAccess(access),
      }, { expose: () => {} }));
    },
  };
}

function envelope(config, data, code = 20000, status = 200) {
  return { config, status, headers: {}, data: { code, msg: '', data } };
}

test('only the official catalog extends the platform request timeout', async (t) => {
  const calls = [];
  const api = fixture(t, async (config) => {
    const { method, url, timeout, params } = config;
    calls.push([method, url, timeout, params?.channel]);
    return envelope(config, { catalog: [] });
  });
  for (const channel of ['stable', 'prerelease']) {
    assert.deepEqual(await api.officialModuleUpdates(channel), { catalog: [] });
  }
  await api.developerCatalog();
  assert.deepEqual(calls, [
    ['get', updates, 60_000, 'stable'],
    ['get', updates, 60_000, 'prerelease'],
    ['get', catalog, 15_000, undefined],
  ]);
});

test('catalog timeouts and other network failures have distinct messages', async (t) => {
  const failures = [
    ['ECONNABORTED', '请求超时，请稍后重试'],
    ['ETIMEDOUT', '请求超时，请稍后重试'],
    ['ERR_NETWORK', '网络请求失败，请检查网络连接后重试'],
  ];
  for (const [code, message] of failures) {
    const api = fixture(t, async (config) => {
      throw new axios.AxiosError('transport error', code, config);
    });
    await assert.rejects(api.officialModuleUpdates('stable'), { code, message });
  }
});

test('concurrent catalog reads share session refresh and retain their timeout on retry', async (t) => {
  const attempts = [];
  const refreshCalls = [];
  const api = fixture(t, async (config) => {
    const { url, timeout, params, headers } = config;
    if (url === '/platformapi/session/login') {
      return envelope(config, { access_token: 'initial-token' });
    }
    if (url === refresh) {
      refreshCalls.push(timeout);
      return envelope(config, { access_token: 'refreshed-token' });
    }
    assert.equal(url, updates);
    attempts.push([params.channel, timeout, headers.Authorization]);
    if (headers.Authorization === 'Bearer initial-token') {
      throw new axios.AxiosError(
        'session expired',
        'ERR_BAD_REQUEST',
        config,
        undefined,
        envelope(config, null, 40100, 401)
      );
    }
    return envelope(config, { channel: params.channel });
  });

  await api.login('operator@example.test', 'synthetic');
  const channels = ['stable', 'prerelease'];
  const snapshots = await Promise.all(
    channels.map((channel) => api.officialModuleUpdates(channel))
  );
  assert.deepEqual(snapshots, channels.map((channel) => ({ channel })));
  assert.deepEqual(refreshCalls, [15_000]);
  for (const channel of channels) {
    assert.deepEqual(attempts.filter(([attempt]) => attempt === channel), [
      [channel, 60_000, 'Bearer initial-token'],
      [channel, 60_000, 'Bearer refreshed-token'],
    ]);
  }
});

const selection = {
  module_key: 'peanut.file',
  version: '1.0.2',
  sha256: 'a'.repeat(64),
  catalog_tag: 'v1.0.2',
  channel: 'stable',
};

test('official preflight explains only known module rejection codes', async (t) => {
  let errorCode;
  const fallback = 'Module runtime request was rejected.';
  const api = fixture(t, async (config) => {
    const response = envelope(config, { error_code: errorCode }, 42200, 422);
    response.data.msg = fallback;
    response.headers['X-Trace-Id'] = 'module-preview-trace';
    throw new axios.AxiosError(
      'rejected',
      'ERR_BAD_REQUEST',
      config,
      undefined,
      response
    );
  });
  for (const [code, message] of [
    [
      'MODULE_RELEASE_WITHDRAWN',
      '所选模块版本已撤回，请刷新官方目录并选择其他版本。',
    ],
    [
      'MODULE_KERNEL_INCOMPATIBLE',
      '所选模块版本与当前应用的 Kernel 版本不兼容。',
    ],
    [
      'MODULE_PACKAGE_DEPENDENCY_MISSING',
      '所选模块版本缺少必需的前置模块，请核对模块依赖。',
    ],
    ['MODULE_FUTURE_REJECTION', fallback],
  ]) {
    errorCode = code;
    await assert.rejects(api.previewOfficialModule(selection, 'install'), {
      message: message + '（追踪 ID：module-preview-trace）',
    });
  }
  errorCode = 'MODULE_RELEASE_WITHDRAWN';
  await assert.rejects(api.developerCatalog(), {
    message: fallback + '（追踪 ID：module-preview-trace）',
  });
});

test('module failures with a 5xx status retain the safe message and trace ID', async (t) => {
  const api = fixture(t, async (config) => {
    const response = envelope(
      config,
      { error_code: 'MODULE_KERNEL_INCOMPATIBLE' },
      50300,
      503
    );
    response.data.msg = 'Private upstream details';
    response.headers['X-Trace-Id'] = 'module-service-trace';
    throw new axios.AxiosError(
      'unavailable',
      'ERR_BAD_RESPONSE',
      config,
      undefined,
      response
    );
  });
  await assert.rejects(api.previewOfficialModule(selection), {
    message: '服务暂时不可用，请稍后重试（追踪 ID：module-service-trace）',
  });
});

test('official install and update preserve dry run, confirmation and task lookup', async (t) => {
  const digest = 'b'.repeat(64);
  const idempotencyKey = 'test-fixed-intent';
  const preview = { dry_run: true, selection_sha256: digest, plan: {} };
  const task = { task_key: 'job_module_update', status: 'queued' };
  const receipt = {
    ...task,
    status: 'succeeded',
    result: { operation: 'upgraded' },
  };
  let url;
  let applied;
  const api = fixture(t, async (config) => {
    assert.equal(config.url, url);
    if (config.method === 'get') return envelope(config, receipt);
    assert.equal(config.method, 'post');
    assert.deepEqual(JSON.parse(config.data), {
      ...selection,
      dry_run: !applied,
      ...(applied ? { confirm_selection_sha256: digest } : {}),
    });
    assert.equal(
      (config.headers || {})['Idempotency-Key'],
      applied ? idempotencyKey : undefined
    );
    return envelope(config, applied ? task : preview);
  });

  for (const operation of ['install', 'update']) {
    url = '/platformapi/instance-tools/modules/official-' + operation;
    applied = false;
    assert.deepEqual(
      await api.previewOfficialModule(selection, operation),
      preview
    );
    applied = true;
    assert.deepEqual(
      await api.applyOfficialModule(
        selection,
        digest,
        idempotencyKey,
        operation
      ),
      task
    );
    url = '/platformapi/instance-tools/modules/tasks/' + task.task_key;
    assert.deepEqual(await api.moduleOperationTask(task.task_key), receipt);
  }
});

test('the Developer Center independently gates uploaded and official install endpoints', async (t) => {
  const uploadPath = '/platformapi/instance-tools/modules/install';
  const officialPath = '/platformapi/instance-tools/modules/official-install';
  const digest = 'd'.repeat(64);
  const preview = { dry_run: true, selection_sha256: digest, plan: {} };
  const task = { task_key: 'job_synthetic', status: 'queued' };
  const calls = [];
  const { mountDeveloper } = fixture(t, async (config) => {
    assert.equal(config.method, 'post');
    const idempotency = config.headers?.['Idempotency-Key'];
    if (config.url === uploadPath) {
      assert.equal(config.data.get('package').name, 'synthetic.tar');
      assert.equal(config.data.get('expected_sha256'), digest);
      assert.match(idempotency, /^module-/);
      calls.push([uploadPath, 'multipart']);
      return envelope(config, task);
    }
    assert.equal(config.url, officialPath);
    const body = JSON.parse(config.data);
    assert.deepEqual(body, {
      ...selection,
      dry_run: body.dry_run,
      ...(body.dry_run ? {} : { confirm_selection_sha256: digest }),
    });
    if (body.dry_run) {
      assert.equal(config.timeout, 60_000);
      assert.equal(idempotency, undefined);
    } else {
      assert.match(idempotency, /^official-module-/);
    }
    calls.push([officialPath, body.dry_run]);
    return envelope(config, body.dry_run ? preview : task);
  }, true);
  const officialRow = {
    key: selection.module_key, version: selection.version,
    sha256: selection.sha256, catalog_tag: selection.catalog_tag,
    channel: selection.channel, availability: 'available',
  };
  const prepareUpload = (panel) => {
    panel.packageOperation.value = 'install';
    panel.packageFile.value = new File(['synthetic'], 'synthetic.tar');
    panel.packageSha.value = digest;
  };

  const uploadOnly = mountDeveloper([{ method: 'POST', path: uploadPath }]);
  await uploadOnly.previewCatalog(officialRow);
  assert.deepEqual(calls, []);
  uploadOnly.officialOperation.value = 'install';
  uploadOnly.officialSelection.value = selection;
  uploadOnly.officialPreview.value = preview;
  assert.equal(uploadOnly.canSubmitOfficial.value, false);
  await uploadOnly.applyOfficial();
  assert.deepEqual(calls, []);
  prepareUpload(uploadOnly);
  await uploadOnly.submitPackage();
  assert.deepEqual(calls, [[uploadPath, 'multipart']]);

  const officialOnly = mountDeveloper([{ method: 'POST', path: officialPath }]);
  prepareUpload(officialOnly);
  await officialOnly.submitPackage();
  assert.deepEqual(calls, [[uploadPath, 'multipart']]);
  await officialOnly.previewCatalog(officialRow);
  assert.equal(officialOnly.canSubmitOfficial.value, true);
  await officialOnly.applyOfficial();
  assert.deepEqual(calls, [[uploadPath, 'multipart'], [officialPath, true], [officialPath, false]]);
});

test('official preflight delivers a dependency rejection after the default timeout', async (t) => {
  const rejectionAtMs = 29_000;
  const requests = [];
  const api = fixture(t, async (config) => {
    const { dry_run: dryRun } = JSON.parse(config.data);
    requests.push([config.url, dryRun, config.timeout]);
    if (!dryRun) return envelope(config, { task_key: 'job_module_update' });

    // Model the XHR deadline without waiting or sending a network request.
    if (config.timeout > 0 && config.timeout <= rejectionAtMs) {
      throw new axios.AxiosError('timeout', 'ECONNABORTED', config);
    }
    const response = envelope(
      config,
      { error_code: 'MODULE_PACKAGE_DEPENDENCY_INCOMPATIBLE' },
      42200,
      422
    );
    response.headers['X-Trace-Id'] = 'preflight-trace';
    throw new axios.AxiosError(
      'rejected',
      'ERR_BAD_REQUEST',
      config,
      undefined,
      response
    );
  });

  for (const operation of ['install', 'update']) {
    await assert.rejects(api.previewOfficialModule(selection, operation), {
      message:
        '前置模块的当前版本不满足所选模块版本的要求。（追踪 ID：preflight-trace）',
    });
    await api.applyOfficialModule(
      selection,
      'b'.repeat(64),
      'fixed-intent',
      operation
    );
  }

  const route = '/platformapi/instance-tools/modules/official-';
  assert.deepEqual(requests, [
    [route + 'install', true, 60_000],
    [route + 'install', false, 15_000],
    [route + 'update', true, 60_000],
    [route + 'update', false, 15_000],
  ]);
});
