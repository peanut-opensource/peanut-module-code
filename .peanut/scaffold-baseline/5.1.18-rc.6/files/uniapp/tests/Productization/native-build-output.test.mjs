import assert from 'node:assert/strict';
import {
  existsSync,
  lstatSync,
  readFileSync,
  readdirSync,
  realpathSync,
} from 'node:fs';
import { basename, relative, resolve, sep } from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const repoRoot = realpathSync(
  fileURLToPath(new URL('../../../', import.meta.url))
);
const required = (name) => {
  assert.ok(process.env[name], `${name} is required; no stale output fallback`);
  return process.env[name];
};
const sourceRoot = resolve(required('NATIVE_SOURCE_ROOT'));
const mpWeixinRoot = resolve(required('NATIVE_MP_WEIXIN_OUTPUT'));
const appRoot = resolve(required('NATIVE_APP_OUTPUT'));
const forbiddenNames = new Set([
  '.env',
  '.env.local',
  'node_modules',
  'package-lock.json',
  'pnpm-lock.yaml',
  'yarn.lock',
  'src',
  '.git',
  '.local',
  '.npmrc',
  'auth.json',
  'id_rsa',
  'vite.config.ts',
]);
// Business pages such as change_password are not credentials.
const secretPattern =
  /^(?:\.env(?:\.|$)|id_(?:rsa|ed25519)$)|\.(?:pem|p12|pfx|key|mobileprovision|keystore)$/i;

const inside = (parent, child) =>
  child === parent || child.startsWith(`${parent}${sep}`);

function readJson(path) {
  return JSON.parse(readFileSync(path, 'utf8'));
}

function pagesFromSource() {
  const pagesJson = readJson(resolve(sourceRoot, 'src/pages.json'));
  const pages = pagesJson.pages.map((page) => page.path);
  for (const subPackage of pagesJson.subPackages || []) {
    for (const page of subPackage.pages || []) {
      pages.push(`${subPackage.root}/${page.path}`);
    }
  }
  assert.equal(new Set(pages).size, pages.length, 'source pages are unique');
  return { pages, pagesJson };
}

function listFiles(root) {
  assert.ok(existsSync(root), `${root} does not exist`);
  assert.equal(
    realpathSync(root),
    root,
    'native output root/ancestors may not be links'
  );
  const realRoot = realpathSync(root);
  const digest = createHash('sha256');
  assert.ok(inside(realpathSync(repoRoot), realRoot), 'output is outside repo');
  const files = [];
  const visit = (directory) => {
    for (const name of readdirSync(directory).sort()) {
      assert.ok(
        !forbiddenNames.has(name),
        `forbidden native output entry ${name}`
      );
      assert.ok(
        !secretPattern.test(name),
        `secret-like native output entry ${name}`
      );
      const absolute = resolve(directory, name);
      const stat = lstatSync(absolute);
      assert.ok(!stat.isSymbolicLink(), `native output symlink ${absolute}`);
      assert.ok(
        stat.isFile() || stat.isDirectory(),
        `native output special file ${absolute}`
      );
      assert.ok(
        inside(realRoot, realpathSync(absolute)),
        'output escapes root'
      );
      const path = relative(realRoot, absolute).split(sep).join('/');
      digest.update(`${path}\0${stat.mode}\0`);
      if (stat.isDirectory()) visit(absolute);
      else {
        assert.equal(
          stat.nlink,
          1,
          'native output file may not be hard-linked'
        );
        files.push(path);
        digest.update(readFileSync(absolute));
      }
    }
  };
  visit(realRoot);
  assert.ok(files.length > 0, `${root} is empty`);
  assert.notEqual(basename(realRoot), 'h5', 'native output must not be H5');
  const expected = required(
    root === mpWeixinRoot ? 'NATIVE_MP_WEIXIN_SHA256' : 'NATIVE_APP_SHA256'
  );
  assert.match(expected, /^[a-f0-9]{64}$/);
  assert.equal(
    digest.digest('hex'),
    expected,
    'native artifact digest mismatch'
  );
  return { realRoot, files };
}

function readSmallText(root, file) {
  const stat = lstatSync(resolve(root, file));
  assert.ok(stat.size <= 5_000_000, `${file} is unexpectedly large`);
  return readFileSync(resolve(root, file), 'utf8');
}

test('mp-weixin output is a closed compiled native package', () => {
  const { pages, pagesJson } = pagesFromSource();
  const { realRoot, files } = listFiles(mpWeixinRoot);
  assert.ok(files.includes('app.json'), 'mp-weixin app.json is required');
  assert.ok(files.includes('app.js'), 'mp-weixin app.js is required');
  assert.ok(files.includes('app.wxss'), 'mp-weixin app.wxss is required');
  assert.ok(!files.includes('index.html'), 'mp-weixin output is not H5');

  const appJson = readJson(resolve(realRoot, 'app.json'));
  assert.deepEqual(
    appJson.pages,
    pagesJson.pages.map((page) => page.path)
  );
  assert.deepEqual(
    (appJson.subPackages || []).map((item) => ({
      root: item.root,
      pages: item.pages,
    })),
    (pagesJson.subPackages || []).map((item) => ({
      root: item.root,
      pages: item.pages.map((page) => page.path),
    }))
  );

  for (const page of pages) {
    for (const extension of ['.js', '.json', '.wxml', '.wxss']) {
      assert.ok(
        files.includes(`${page}${extension}`),
        `missing mp-weixin page artifact ${page}${extension}`
      );
    }
  }
});

test('app output contains compiled service and view artifacts', () => {
  const { pages } = pagesFromSource();
  const { realRoot, files } = listFiles(appRoot);
  for (const file of [
    'manifest.json',
    'app-config-service.js',
    'app-service.js',
    '__uniappview.html',
    'uni-app-view.umd.js',
  ]) {
    assert.ok(files.includes(file), `required native App artifact ${file}`);
  }
  const manifest = readJson(resolve(realRoot, 'manifest.json'));
  assert.equal(manifest.launch_path, '__uniappview.html');
  assert.equal(manifest.plus['uni-app'].vueVersion, '3');
  const config = readSmallText(realRoot, 'app-config-service.js');
  const routesLiteral = config.match(
    /const __uniRoutes = (\[[^\n]*\])(?:\.map\(|;)/
  );
  assert.ok(routesLiteral, 'native App route table must be identifiable JSON');
  const routes = JSON.parse(routesLiteral[1]);
  assert.deepEqual(
    routes.map((route) => route.path),
    pages
  );
  for (const page of pages) {
    assert.ok(files.includes(`${page}.css`), `missing App page style ${page}`);
  }
});
