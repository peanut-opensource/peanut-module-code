#!/usr/bin/env node
/** 合成原生锁+既有 YAML 解析器；只验证身份/拒绝路径，不访问合成 registry URL。 */
import assert from 'node:assert/strict';
import test from 'node:test';
import { createHash } from 'node:crypto';
import {
  readFileSync,
  mkdirSync,
  mkdtempSync,
  writeFileSync,
  copyFileSync,
  symlinkSync,
  renameSync,
  rmSync,
} from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import {
  coreWebPackages,
  dependencyMode,
  verifyDependencyLocks,
  verifyInstalledDependencies,
  lockedYamlParser,
} from '../release-dependency-locks.mjs';

const root = fileURLToPath(new URL('../..', import.meta.url));
const parseYaml = lockedYamlParser(process.argv[2] || root);
function fixture() {
  const versions = JSON.parse(
    readFileSync(resolve(root, 'release-versions.json'))
  );
  Object.assign(versions.core_php, {
    constraint: '9.2.1',
    resolved_version: 'v9.2.1',
  });
  for (const name of coreWebPackages)
    versions.core_web.packages[name] = {
      version: '8.1.0-rc.2',
      resolved: `https://registry.example.invalid/${name}/-/fixture-8.1.0-rc.2.tgz`,
      integrity:
        'sha512-' +
        createHash('sha512')
          .update('synthetic:' + name)
          .digest('base64'),
    };
  const files = new Map();
  files.set('server/composer.json', {
    require: { 'peanut-admin/core': '9.2.1' },
    repositories: [],
  });
  files.set('server/composer.lock', {
    packages: [
      {
        name: 'peanut-admin/core',
        version: 'v9.2.1',
        source: {
          type: 'git',
          url: versions.core_php.source_url,
          reference: versions.core_php.source_reference,
        },
      },
    ],
  });
  for (const [client, suffixes] of Object.entries({
    web: ['vue', 'ui-vue'],
    platform: ['vue', 'ui-vue'],
    pc: ['client', 'nuxt'],
    uniapp: ['client', 'uniapp'],
  })) {
    const dependencies = Object.fromEntries(
      suffixes.map((s) => [`@peanut-admin/${s}`, '8.1.0-rc.2'])
    );
    files.set(`${client}/package.json`, { version: '9.8.7', dependencies });
    if (client !== 'web')
      files.set(`${client}/package-lock.json`, {
        lockfileVersion: 3,
        packages: {
          '': { dependencies },
          ...Object.fromEntries(
            Object.keys(dependencies).map((name) => [
              `node_modules/${name}`,
              { ...versions.core_web.packages[name] },
            ])
          ),
        },
      });
  }
  const names = ['@peanut-admin/vue', '@peanut-admin/ui-vue'];
  const yaml =
    "lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n" +
    names
      .map(
        (n) =>
          `      '${n}':\n        specifier: 8.1.0-rc.2\n        version: 8.1.0-rc.2(vue@3.5.0)\n`
      )
      .join('') +
    'packages:\n' +
    names
      .map(
        (n) =>
          `  '${n}@8.1.0-rc.2':\n    resolution: {integrity: '${versions.core_web.packages[n].integrity}'}\n`
      )
      .join('');
  files.set('web/pnpm-lock.yaml', yaml);
  const read = (path) => {
    assert.ok(files.has(path), 'unexpected source read: ' + path);
    const data = files.get(path);
    return Buffer.from(typeof data === 'string' ? data : JSON.stringify(data));
  };
  return {
    versions,
    files,
    run: () => verifyDependencyLocks(versions, read, parseYaml),
  };
}

test('fixed v3 native locks work without a local Core tgz', () => {
  const f = fixture(),
    result = f.run();
  assert.equal(result.mode, 'registry');
  assert.equal(result.checked.length, 8);
  assert.equal(result.published, false);
});
test('Composer lock source identity cannot drift', () => {
  const f = fixture();
  f.files.get('server/composer.lock').packages[0].source.reference = '0'.repeat(
    40
  );
  assert.throws(f.run, /Composer/);
});
test('npm exact version, source and integrity must all agree', () => {
  for (const key of ['version', 'resolved', 'integrity']) {
    const f = fixture();
    f.files.get('pc/package-lock.json').packages[
      'node_modules/@peanut-admin/client'
    ][key] = 'different';
    assert.throws(f.run, /npm resolution/);
  }
});
test('npm root manifest and lock importer cannot disagree', () => {
  const f = fixture();
  f.files.get('pc/package-lock.json').packages[''].dependencies = {};
  assert.throws(f.run, /npm importer/);
});
test('pnpm YAML importer identity is checked with the locked parser', () => {
  const f = fixture();
  f.files.set(
    'web/pnpm-lock.yaml',
    f.files
      .get('web/pnpm-lock.yaml')
      .replace('specifier: 8.1.0-rc.2', 'specifier: ^8.1.0')
  );
  assert.throws(f.run, /pnpm importer/);
});
test('pnpm package integrity cannot disagree or disappear', () => {
  const f = fixture();
  f.files.set(
    'web/pnpm-lock.yaml',
    f.files
      .get('web/pnpm-lock.yaml')
      .replace(/integrity: '[^']+'/, "integrity: 'sha512-AAAA'")
  );
  assert.throws(f.run, /pnpm resolution/);
});
test('duplicate YAML keys are not quietly overwritten', () => {
  const f = fixture();
  f.files.set(
    'web/pnpm-lock.yaml',
    f.files.get('web/pnpm-lock.yaml') + 'packages: {}\n'
  );
  assert.throws(f.run, /duplicated mapping key/);
});
test('missing required client dependency is not accepted as an empty package', () => {
  const f = fixture();
  delete f.files.get('web/package.json').dependencies['@peanut-admin/vue'];
  assert.throws(f.run, /missing vue/);
});
test('mixed development and registry identities remain rejected', () => {
  const f = fixture();
  f.versions.core_php.constraint = f.versions.core_php.resolved_version =
    'dev-dev';
  assert.throws(f.run, /mixed/);
});
test('credential-bearing sources, ranges and malformed SRI are rejected', () => {
  for (const changed of [
    { resolved: 'https://user:secret@example.invalid/package.tgz' },
    { version: '^8.1.0' },
    { integrity: 'sha512-AAAA' },
    { version: '8.1.0-dev.1' },
    { archive: 'packages/core-web/extra.tgz' },
  ]) {
    const f = fixture();
    Object.assign(
      f.versions.core_web.packages['@peanut-admin/client'],
      changed
    );
    assert.throws(
      () => dependencyMode(f.versions),
      /RELEASE_DEPENDENCY_INVALID/
    );
  }
});
test('existing static checker reads native V3 metadata and preserves not-ready status', () => {
  assert.ok(
    process.env.TMPDIR?.includes('/.local/tmp/'),
    'test requires checkout TMPDIR'
  );
  const temporary = mkdtempSync(join(process.env.TMPDIR, 'native-checker-'));
  const app = join(temporary, 'candidate'),
    tools = join(temporary, 'tools');
  try {
    const f = fixture();
    Object.assign(f.versions, {
      source_product_version: '9.8.7',
      scaffold_template: '9.8.7',
    });
    f.files.set('release-versions.json', f.versions);
    f.files.set('RELEASE_METADATA.json', {
      schema_version: 3,
      protocol: 'peanut.release-metadata.v3',
      source_product_version: '9.8.7',
      instance_version: null,
      expected_tag: 'v9.8.7',
    });
    f.files.set('scaffold/application-template-inventory.json', {
      template_version: '9.8.7',
      application: { version: '0.1.0' },
    });
    f.files.set('server/tests/fixtures/p0e-runtime-qualification/matrix.json', {
      target_release: { version: '9.8.7' },
    });
    f.files.set(
      'CHANGELOG.md',
      '## [9.8.7]\nSynthetic checker input, not a release.\n'
    );
    for (const [relative, data] of f.files) {
      const path = join(app, relative);
      mkdirSync(dirname(path), { recursive: true });
      writeFileSync(
        path,
        typeof data === 'string' ? data : JSON.stringify(data)
      );
    }
    mkdirSync(join(tools, 'scripts'), { recursive: true });
    for (const name of [
      'check-release-consistency',
      'release-dependency-locks.mjs',
      'release-template-artifacts.mjs',
    ])
      copyFileSync(join(root, 'scripts', name), join(tools, 'scripts', name));
    // 测试工具独立于候选，仅读既有锁定解析器；候选自身不带 node_modules。
    mkdirSync(join(tools, 'web/node_modules'), { recursive: true });
    symlinkSync(
      resolve(process.argv[2] || root, 'web/node_modules/openapi-typescript'),
      join(tools, 'web/node_modules/openapi-typescript')
    );
    const command = [
      join(tools, 'scripts/check-release-consistency'),
      '--static-only',
    ];
    let r = spawnSync(process.execPath, command, {
      cwd: app,
      encoding: 'utf8',
      timeout: 10000,
    });
    assert.equal(r.status, 0, r.stderr);
    const result = JSON.parse(r.stdout);
    assert.equal(result.dependency_identity.mode, 'registry');
    assert.equal(result.product_release_ready, false);
    const lock = f.files.get('pc/package-lock.json');
    lock.packages['node_modules/@peanut-admin/client'].integrity = 'different';
    writeFileSync(join(app, 'pc/package-lock.json'), JSON.stringify(lock));
    r = spawnSync(process.execPath, command, {
      cwd: app,
      encoding: 'utf8',
      timeout: 10000,
    });
    assert.equal(r.status, 1);
    assert.match(r.stderr, /npm resolution/);
  } finally {
    rmSync(temporary, { recursive: true, force: true });
  }
});
// 实际调用现有检查CLI，夹具只含合成元数据；不能把这些检查当作依赖安装或业务运行。
function installedFixture(operation) {
  assert.ok(
    process.env.TMPDIR?.includes('/.local/tmp/'),
    'test requires checkout TMPDIR'
  );
  const temporary = mkdtempSync(
    join(process.env.TMPDIR, 'installed-identity-')
  );
  const app = join(temporary, 'application');
  const f = fixture();
  const write = (relative, data) => {
    const path = join(app, relative);
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, typeof data === 'string' ? data : JSON.stringify(data));
  };
  try {
    for (const [relative, data] of f.files) write(relative, data);
    write('release-versions.json', f.versions);
    const phpRecord = {
      ...f.files.get('server/composer.lock').packages[0],
      'install-path': '../peanut-admin/core',
    };
    write('server/vendor/composer/installed.json', { packages: [phpRecord] });
    write(
      'server/vendor/autoload.php',
      '<?php // Synthetic metadata fixture; never executed.\n'
    );
    write('server/vendor/peanut-admin/core/composer.json', {
      name: 'peanut-admin/core',
    });
    for (const client of ['web', 'platform', 'pc', 'uniapp']) {
      for (const [name, version] of Object.entries(
        f.files.get(`${client}/package.json`).dependencies
      )) {
        write(`${client}/node_modules/${name}/package.json`, { name, version });
      }
    }
    symlinkSync(
      resolve(process.argv[2] || root, 'web/node_modules/openapi-typescript'),
      join(app, 'web/node_modules/openapi-typescript')
    );
    const check = () =>
      spawnSync(
        process.execPath,
        [
          join(root, 'scripts/release-dependency-locks.mjs'),
          app,
          '--installed',
        ],
        { encoding: 'utf8', timeout: 10000 }
      );
    operation({
      app,
      temporary,
      phpRecord,
      versions: f.versions,
      write,
      check,
    });
  } finally {
    rmSync(temporary, { recursive: true, force: true });
  }
}

test('installed fixed identities accept contained native package layout', () =>
  installedFixture(({ check }) => {
    const result = check();
    assert.equal(result.status, 0, result.stderr);
    const proof = JSON.parse(result.stdout);
    assert.equal(proof.published, false);
    assert.equal(proof.installed.php.version, 'v9.2.1');
    assert.equal(proof.installed.independent_locations_checked, true);
    assert.equal(proof.installed.runtime_qualified, false);
  }));
test('installed check rejects missing PHP dependencies rather than only reading web versions', () =>
  installedFixture(({ app, check }) => {
    rmSync(join(app, 'server/vendor'), { recursive: true });
    const result = check();
    assert.notEqual(result.status, 0, 'missing PHP installation was accepted');
  }));
test('installed PHP source commit must match the declared native lock', () =>
  installedFixture(({ phpRecord, write, check }) => {
    write('server/vendor/composer/installed.json', {
      packages: [
        {
          ...phpRecord,
          source: { ...phpRecord.source, reference: '0'.repeat(40) },
        },
      ],
    });
    const result = check();
    assert.notEqual(
      result.status,
      0,
      'different installed PHP source was accepted'
    );
  }));
test('installed PHP metadata cannot omit or duplicate the actual Core record', () => {
  for (const duplicate of [false, true])
    installedFixture(({ phpRecord, write, check }) => {
      write('server/vendor/composer/installed.json', {
        packages: duplicate ? [phpRecord, phpRecord] : [],
      });
      assert.notEqual(
        check().status,
        0,
        'ambiguous installed PHP record was accepted'
      );
    });
});
test('installed PHP metadata cannot point to a maintainer directory outside the application', () =>
  installedFixture(({ app, temporary, phpRecord, write, check }) => {
    const outside = join(temporary, 'maintainer-php-core');
    renameSync(join(app, 'server/vendor/peanut-admin/core'), outside);
    symlinkSync(outside, join(app, 'server/vendor/peanut-admin/core'));
    write('server/vendor/composer/installed.json', {
      packages: [{ ...phpRecord, 'install-path': outside }],
    });
    assert.notEqual(
      check().status,
      0,
      'maintainer PHP link was accepted as an installed release'
    );
  }));
test('installed Web Core same version outside the application is not independent consumption', () =>
  installedFixture(({ app, temporary, check }) => {
    const original = join(app, 'pc/node_modules/@peanut-admin/client');
    const outside = join(temporary, 'maintainer-web-core');
    renameSync(original, outside);
    symlinkSync(outside, original);
    assert.notEqual(
      check().status,
      0,
      'maintainer Web link was accepted as an installed release'
    );
  }));
test('installed Web Core permits normal pnpm links into the same application', () =>
  installedFixture(({ app, check }) => {
    const original = join(app, 'web/node_modules/@peanut-admin/vue');
    const internal = join(
      app,
      'web/node_modules/.pnpm/core-fixture/node_modules/@peanut-admin/vue'
    );
    mkdirSync(dirname(internal), { recursive: true });
    renameSync(original, internal);
    symlinkSync(internal, original);
    const result = check();
    assert.equal(result.status, 0, result.stderr);
  }));

test('development linked Core remains allowed without claiming independent installation', () =>
  installedFixture(({ app, temporary, versions }) => {
    versions.core_php.constraint = versions.core_php.resolved_version =
      'dev-dev';
    for (const name of coreWebPackages)
      versions.core_web.packages[name] = {
        version: '8.1.0-rc.2',
        archive: `packages/core-web/peanut-admin-${
          name.split('/')[1]
        }-8.1.0-rc.2.tgz`,
        sha256: 'a'.repeat(64),
      };
    rmSync(join(app, 'server/vendor'), { recursive: true });
    const original = join(app, 'pc/node_modules/@peanut-admin/client'),
      outside = join(temporary, 'development-core');
    renameSync(original, outside);
    symlinkSync(outside, original);
    const proof = verifyInstalledDependencies(app, versions);
    assert.equal(proof.independent_locations_checked, false);
    assert.equal(proof.php, null);
    assert.equal(proof.proof, 'development-web-manifests-only');
    assert.equal(
      proof.web.find(
        (item) =>
          item.client === 'pc' && item.package === '@peanut-admin/client'
      ).contained,
      false
    );
  }));

test('candidate gate rejects absent qualification before reading candidate contents', () => {
  const r = spawnSync(
    process.execPath,
    [
      resolve(root, 'scripts/check-release-consistency'),
      '--candidate',
      'a'.repeat(40),
    ],
    { encoding: 'utf8' }
  );
  assert.equal(r.status, 64);
  assert.match(r.stderr, /requires --qualification/);
});
