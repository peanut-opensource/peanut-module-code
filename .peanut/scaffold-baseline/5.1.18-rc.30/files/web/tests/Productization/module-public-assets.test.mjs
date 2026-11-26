import assert from 'node:assert/strict';
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  realpathSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { build, resolveConfig } from 'vite';

const sourceWeb = fileURLToPath(new URL('../../', import.meta.url));

test('Admin build publishes only locked module public assets in their own namespace', async () => {
  const project = realpathSync(
    mkdtempSync(resolve(tmpdir(), 'peanut-admin-module-assets-'))
  );
  const web = resolve(project, 'web');
  const output = resolve(project, 'server/public/admin');
  const makeFile = (relative, contents) => {
    const path = resolve(web, relative);
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, contents);
    return path;
  };
  const moduleEntry = (slug) => `src/modules/acme-${slug}/contribution.ts`;
  const modulePublic = (slug) => `src/modules/acme-${slug}/public`;
  const publicOutput = (slug) =>
    resolve(output, `module-assets/acme.${slug}/nested/catalog.json`);
  const writeLock = (...modules) =>
    writeFileSync(
      resolve(project, 'modules.lock'),
      JSON.stringify({
        schema_version: 1,
        modules: modules.map((slug) => ({
          key: `acme.${slug}`,
          frontend: [
            { client_key: 'admin-web', entry: `web/${moduleEntry(slug)}` },
          ],
        })),
      })
    );
  const runBuild = () =>
    build({
      root: web,
      configFile: resolve(web, 'vite.config.ts'),
      configLoader: 'runner',
      logLevel: 'silent',
      mode: 'multi-tenant',
      build: { outDir: output, emptyOutDir: true },
    });
  try {
    for (const path of [
      'vite.config.ts',
      'config/plugin/compress.ts',
      'config/plugin/visualizer.ts',
    ]) {
      const target = resolve(web, path);
      mkdirSync(dirname(target), { recursive: true });
      copyFileSync(resolve(sourceWeb, path), target);
    }
    symlinkSync(
      resolve(sourceWeb, 'node_modules'),
      resolve(web, 'node_modules')
    );
    makeFile('index.html', '<script type="module" src="/main.ts"></script>');
    makeFile(
      'main.ts',
      "import modules from 'virtual:peanut-module-contributions'; window.modules = modules;"
    );
    const first = Buffer.from('{"value":"first"}\n');
    const second = Buffer.from('{"value":"second"}\n');
    makeFile(moduleEntry('first'), 'export default "first";');
    makeFile(moduleEntry('second'), 'export default "second";');
    makeFile(`${modulePublic('first')}/nested/catalog.json`, first);
    makeFile(`${modulePublic('second')}/nested/catalog.json`, second);
    const script = "import './uncompiled-dependency.js';\n";
    makeFile(`${modulePublic('second')}/literal.js`, script);
    const sidecar = Buffer.from('explicit Module-owned bytes\n');
    makeFile(
      `${modulePublic('second')}/large.json`,
      JSON.stringify('a'.repeat(3000))
    );
    makeFile(`${modulePublic('second')}/large.json.gz`, sidecar);
    makeFile('public/brand/keep.txt', 'host bytes\n');

    writeLock('first', 'second');
    const config = await resolveConfig(
      {
        root: web,
        configFile: resolve(web, 'vite.config.ts'),
        configLoader: 'runner',
      },
      'serve'
    );
    const plugin = config.plugins.find(
      ({ name }) => name === 'peanut-module-public-assets'
    );
    let middleware;
    const installMiddleware = plugin.configureServer({
      config,
      middlewares: { use: (handler) => (middleware = handler) },
    });
    assert.equal(middleware, undefined);
    installMiddleware();
    const request = (method, url) => {
      const headers = {};
      const response = {
        statusCode: 200,
        setHeader(name, value) {
          headers[name] = value;
        },
        end(body) {
          this.body = body;
        },
      };
      middleware(
        { method, url: '/index.html', originalUrl: url },
        response,
        () => assert.fail('SPA fallback')
      );
      return { status: response.statusCode, headers, body: response.body };
    };
    const url = '/admin/module-assets/acme.second/literal.js';
    const result = request('GET', `${url}?rev=1`);
    assert.equal(result.status, 200);
    assert.equal(result.headers['Content-Type'], 'text/javascript');
    assert.equal(result.headers['Cache-Control'], 'no-store');
    assert.equal(result.body.toString(), script);
    assert.equal(request('HEAD', url).body, undefined);
    assert.equal(request('POST', url).status, 405);

    await runBuild();
    assert.deepEqual(readFileSync(publicOutput('first')), first);
    assert.deepEqual(readFileSync(publicOutput('second')), second);
    assert.deepEqual(
      readFileSync(resolve(output, 'module-assets/acme.second/large.json.gz')),
      sidecar
    );
    assert.equal(
      readFileSync(resolve(output, 'brand/keep.txt'), 'utf8'),
      'host bytes\n'
    );

    writeLock('second');
    assert.equal(
      request('GET', '/admin/module-assets/acme.first/nested/catalog.json')
        .status,
      404
    );
    const upgraded = Buffer.from('{"value":"upgrade"}\n');
    makeFile(`${modulePublic('second')}/nested/catalog.json`, upgraded);
    await runBuild();
    assert.equal(existsSync(publicOutput('first')), false);
    assert.deepEqual(readFileSync(publicOutput('second')), upgraded);

    const collision = makeFile('public/Module-Assets/host.txt', 'host copy');
    await assert.rejects(runBuild(), /reserved for locked Admin modules/);
    assert.deepEqual(readFileSync(publicOutput('second')), upgraded);
    rmSync(dirname(collision), { recursive: true });
    const linked = resolve(web, modulePublic('second'), 'outside.txt');
    symlinkSync(resolve(web, 'public/brand/keep.txt'), linked);
    await assert.rejects(
      runBuild(),
      /Invalid or conflicting Module public asset/
    );
  } finally {
    rmSync(project, { recursive: true, force: true });
  }
});
