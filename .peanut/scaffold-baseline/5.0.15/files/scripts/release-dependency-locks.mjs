#!/usr/bin/env node
/** 核对 V3 合同与原生锁；不下载、不发布，也不把字符串核对当作安装成功。 */
import { createHash } from 'node:crypto';
import { readFileSync, realpathSync, lstatSync } from 'node:fs';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { isDeepStrictEqual } from 'node:util';

export const coreWebPackages = [
  'client',
  'vue',
  'ui-vue',
  'nuxt',
  'uniapp',
  'testing',
].map((x) => `@peanut-admin/${x}`);
const semver =
  /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/;
const object = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);
const keys = (value, names) =>
  object(value) && JSON.stringify(Object.keys(value)) === JSON.stringify(names);
const fixed = (value) =>
  typeof value === 'string' &&
  semver.test(value) &&
  !/(?:^|[.-])dev(?:[.+-]|$)/i.test(value);
const fail = (message) => {
  throw new Error(`RELEASE_DEPENDENCY_INVALID: ${message}`);
};
const https = (value) => {
  if (typeof value !== 'string' || /[\x00-\x20\x7f]/.test(value)) return false;
  try {
    const u = new URL(value);
    return (
      u.protocol === 'https:' &&
      u.hostname &&
      u.pathname !== '/' &&
      !u.username &&
      !u.password &&
      !u.search &&
      !u.hash
    );
  } catch {
    return false;
  }
};
const integrity = (value) =>
  typeof value === 'string' &&
  value.startsWith('sha512-') &&
  Buffer.from(value.slice(7), 'base64').length === 64 &&
  Buffer.from(value.slice(7), 'base64').toString('base64') === value.slice(7);

export function dependencyMode(versions) {
  const p = versions.core_php,
    w = versions.core_web;
  const phpGit = keys(p, [
      'package',
      'constraint',
      'resolved_version',
      'source_type',
      'source_url',
      'source_reference',
    ]) && p?.source_type === 'git' && https(p.source_url) && /^[0-9a-f]{40}$/.test(p.source_reference ?? '');
  const phpLocal = keys(p, ['package', 'constraint', 'resolved_version', 'source_type', 'source_identity']) &&
    p?.source_type === 'local' && object(p.source_identity) &&
    JSON.stringify(Object.keys(p.source_identity)) === JSON.stringify(['kind', 'snapshot_sha256']) &&
    p.source_identity.kind === 'local' && /^[0-9a-f]{64}$/.test(p.source_identity.snapshot_sha256 ?? '');
  if ((!phpGit && !phpLocal) || p.package !== 'peanut-admin/core' || typeof p.resolved_version !== 'string') fail('core_php');
  const dev =
    /^dev-[A-Za-z0-9._-]+$/.test(p.constraint ?? '') &&
    p.constraint === p.resolved_version;
  const pinned =
    fixed(p.constraint) &&
    p.constraint === p.resolved_version.replace(/^v/, '');
  if (phpLocal ? (!fixed(p.constraint) || p.constraint !== p.resolved_version) : (!dev && !pinned)) fail('core_php version');
  const webGit = keys(w, ['source_type', 'source_url', 'source_reference', 'packages']) && w?.source_type === 'git' &&
    https(w.source_url) && /^[0-9a-f]{40}$/.test(w.source_reference ?? '');
  const webLocal = keys(w, ['source_type', 'source_identity', 'packages']) && w?.source_type === 'local' &&
    object(w.source_identity) && JSON.stringify(Object.keys(w.source_identity)) === JSON.stringify(['kind', 'snapshot_sha256']) &&
    w.source_identity.kind === 'local' && /^[0-9a-f]{64}$/.test(w.source_identity.snapshot_sha256 ?? '');
  if ((!webGit && !webLocal) || !keys(w.packages, coreWebPackages)) fail('core_web');
  const modes = new Set();
  const webVersions = new Set();
  for (const [name, i] of Object.entries(w.packages)) {
    if (!object(i) || typeof i.version !== 'string' || !semver.test(i.version))
      fail(name);
    webVersions.add(i.version);
    if (keys(i, ['version', 'archive', 'sha256'])) {
      const suffix = name.slice('@peanut-admin/'.length);
      if (
        i.archive !== `packages/core-web/peanut-admin-${suffix}-${i.version}.tgz` ||
        !/^[0-9a-f]{64}$/.test(i.sha256 ?? '')
      )
        fail(`${name} archive`);
      modes.add('archive');
    } else if (keys(i, ['version', 'resolved', 'integrity'])) {
      if (!fixed(i.version) || !https(i.resolved) || !integrity(i.integrity))
        fail(`${name} registry`);
      modes.add('registry');
    } else fail(`${name} shape`);
  }
  if (modes.size !== 1 || (modes.has('registry') && !pinned) || (webLocal && !modes.has('archive')) ||
      (webLocal && webVersions.size !== 1))
    fail('mixed development/registry identities');
  return [...modes][0];
}

/** Explicit frozen inputs authenticate archive origins, not an artifact category. */
export function fixedArchiveInputs(root, inputPath, document, read) {
  if (!inputPath || resolve(inputPath) !== inputPath || realpathSync(inputPath) !== inputPath || !lstatSync(inputPath).isFile() || lstatSync(inputPath).nlink !== 1)
    fail('fixed Core input path');
  const raw = readFileSync(inputPath);
  const receipt = JSON.parse(raw);
  const application = JSON.parse(read('.peanut/application-manifest.json'));
  const sha256 = createHash('sha256').update(raw).digest('hex');
  if (application.scaffold_candidate?.core_inputs_sha256 !== sha256 ||
      receipt.schema_version !== 1 || receipt.status !== 'frozen-core-build-inputs-not-product-release' ||
      dependencyMode(document) !== 'archive' || !fixed(document.core_php.constraint) ||
      !isDeepStrictEqual(receipt.core_php, document.core_php) ||
      !isDeepStrictEqual(receipt.core_web, document.core_web) ||
      (document.core_php.source_type === 'local'
        ? !isDeepStrictEqual(receipt.sources?.php, document.core_php.source_identity) ||
          !isDeepStrictEqual(receipt.sources?.web, document.core_web.source_identity)
        : receipt.sources?.php?.commit !== document.core_php.source_reference ||
          receipt.sources?.web?.commit !== document.core_web.source_reference ||
          !/^[a-f0-9]{40}$/.test(receipt.sources?.php?.tree ?? '') ||
          !/^[a-f0-9]{40}$/.test(receipt.sources?.web?.tree ?? '')))
    fail('fixed Core input binding');
  const php = receipt.core_php_archive;
  if (!object(php) || php.path !== `packages/core-php/peanut-admin-core-${document.core_php.resolved_version}.zip` ||
      !/^[a-f0-9]{64}$/.test(php.sha256 ?? '') || !/^[a-f0-9]{40}$/.test(php.sha1 ?? ''))
    fail('fixed PHP archive identity');
  const composer = JSON.parse(read('server/composer.json'));
  const locked = JSON.parse(read('server/composer.lock')).packages?.filter(p => p.name === 'peanut-admin/core');
  const expectedDist = {type: 'zip', url: `../${php.path}`,
    reference: document.core_php.source_type === 'local' ? document.core_php.source_identity.snapshot_sha256 : document.core_php.source_reference,
    shasum: php.sha1};
  if (!isDeepStrictEqual(composer.repositories, [receipt.composer_repository]) || locked?.length !== 1 ||
      !isDeepStrictEqual(locked[0].dist, expectedDist) ||
      (document.core_php.source_type === 'local' ? locked[0].source != null
        : !isDeepStrictEqual(locked[0].source, {type: document.core_php.source_type, url: document.core_php.source_url, reference: document.core_php.source_reference})))
    fail('fixed PHP native origin');
  const archives = [{path: php.path, sha256: php.sha256},
    ...Object.values(document.core_web.packages).map(p => ({path: p.archive, sha256: p.sha256}))];
  if (archives.length !== 7 || new Set(archives.map(p => p.path)).size !== 7) fail('fixed archive count');
  for (const archive of archives) {
    let current = root;
    for (const part of archive.path.split('/')) {
      if (!part || part === '.' || part === '..') fail('fixed archive path');
      current = resolve(current, part);
      if (!current.startsWith(root + sep) || lstatSync(current).isSymbolicLink()) fail('fixed archive symlink');
    }
    if (!lstatSync(current).isFile() || lstatSync(current).nlink !== 1 || realpathSync(current) !== current ||
        createHash('sha256').update(read(archive.path)).digest('hex') !== archive.sha256)
      fail('fixed archive bytes');
  }
  if (createHash('sha1').update(read(php.path)).digest('hex') !== php.sha1) fail('fixed PHP archive SHA-1');
  return {sha256, core_php_archive: php, archives};
}

/** 从既有锁定 OpenAPI 工具依赖取得 YAML 解析器，不自造 YAML 解析或安装新依赖。 */
export function lockedYamlParser(toolRoot) {
  const require = createRequire(
    realpathSync(
      resolve(toolRoot, 'web/node_modules/openapi-typescript/package.json')
    )
  );
  const redocly = createRequire(require.resolve('@redocly/openapi-core'));
  const yaml = redocly('js-yaml');
  return (text) => yaml.load(text, { schema: yaml.JSON_SCHEMA });
}

/** read 只读取指定源码/锁；Git 候选检查传入 git-show，绝不执行候选中的代码。 */
export function verifyDependencyLocks(versions, read, parseYaml, internal = false) {
  const mode = dependencyMode(versions);
  const json = (path) => JSON.parse(String(read(path)));
  const p = versions.core_php,
    packages = versions.core_web.packages;
  const composer = json('server/composer.json'),
    composerLock = json('server/composer.lock');
  const records = (composerLock.packages ?? []).filter(
    (x) => x.name === 'peanut-admin/core'
  );
  const locked = records[0];
  if (
    records.length !== 1 ||
    composer.require?.['peanut-admin/core'] !== p.constraint ||
    locked.version !== p.resolved_version ||
    (p.source_type === 'local'
      ? (locked.source != null || locked.dist?.reference !== p.source_identity.snapshot_sha256)
      : (locked.source?.type !== p.source_type || locked.source?.url !== p.source_url ||
          locked.source?.reference !== p.source_reference || (locked.dist?.reference && locked.dist.reference !== p.source_reference)))
  )
    fail('Composer manifest/lock/provenance');
  if (
    (mode === 'registry' || internal) &&
    (Object.values(composer.repositories ?? {}).some(
      (x) => x?.type === 'path'
    ) ||
      [
        ...(composerLock.packages ?? []),
        ...(composerLock['packages-dev'] ?? []),
      ].some((x) => x.dist?.type === 'path'))
  )
    fail('Composer path dependency');
  const required = {
    web: ['vue', 'ui-vue'],
    platform: ['vue', 'ui-vue'],
    pc: ['client', 'nuxt'],
    uniapp: ['client', 'uniapp'],
  };
  const checked = [];
  for (const [client, suffixes] of Object.entries(required)) {
    const manifest = json(`${client}/package.json`);
    if (
      (mode === 'registry' || internal) &&
      Object.entries({
        ...manifest.dependencies,
        ...manifest.devDependencies,
        ...manifest.optionalDependencies,
      }).some(
        ([name, value]) =>
          typeof value !== 'string' ||
          (/^(?:file:|link:|workspace:|\/|\.\.?\/)/.test(value) &&
            !(internal && name.startsWith('@peanut-admin/')))
      )
    )
      fail(`${client} local dependency`);
    for (const suffix of suffixes)
      if (!manifest.dependencies?.[`@peanut-admin/${suffix}`])
        fail(`${client} missing ${suffix}`);
    const selections = Object.entries({
      ...manifest.dependencies,
      ...manifest.devDependencies,
      ...manifest.optionalDependencies,
    }).filter(([name]) => name.startsWith('@peanut-admin/'));
    for (const [name, specifier] of selections) {
      const i = packages[name];
      if (
        !i ||
        specifier !== (mode === 'registry' ? i.version : `file:../${i.archive}`)
      )
        fail(`${client}:${name} declaration`);
    }
    if (mode === 'archive' && !internal) continue;
    if (client === 'web') {
      if (typeof parseYaml !== 'function')
        fail('locked YAML parser required for native pnpm lock');
      const lock = parseYaml(String(read('web/pnpm-lock.yaml')));
      if (
        String(lock?.lockfileVersion) !== '9.0' &&
        String(lock?.lockfileVersion) !== '9'
      )
        fail('pnpm lock version');
      const importer = lock.importers?.['.'];
      for (const [name, specifier] of selections) {
        const reference =
          importer?.dependencies?.[name] ??
          importer?.devDependencies?.[name] ??
          importer?.optionalDependencies?.[name];
        if (
          reference?.specifier !== specifier ||
          String(reference?.version).split('(')[0] !==
            (mode === 'archive' ? `file:../${packages[name].archive}` : packages[name].version)
        )
          fail(`pnpm importer:${name}`);
      }
      for (const [key, value] of Object.entries(lock.packages ?? {})) {
        if (!key.startsWith('@peanut-admin/')) continue;
        const name = coreWebPackages.find((name) => key.startsWith(`${name}@`)),
          i = packages[name];
        if (
          !i ||
          key !== `${name}@${mode === 'archive' ? `file:../${i.archive}` : i.version}` ||
          value.resolution?.integrity !== (mode === 'archive' ? archiveIntegrity(read, i) : i.integrity) ||
          (mode === 'archive'
            ? value.resolution?.tarball !== `file:../${i.archive}`
            : value.resolution?.tarball && value.resolution.tarball !== i.resolved)
        )
          fail(`pnpm resolution:${key}`);
        checked.push(`${client}:${name}`);
      }
      for (const [name] of selections)
        if (!checked.includes(`${client}:${name}`))
          fail(`pnpm package missing:${name}`);
    } else {
      const lock = json(`${client}/package-lock.json`);
      if (![2, 3].includes(lock.lockfileVersion))
        fail(`${client} npm lock version`);
      for (const [name, specifier] of selections) {
        const root = lock.packages?.[''];
        if (
          (root?.dependencies?.[name] ??
            root?.devDependencies?.[name] ??
            root?.optionalDependencies?.[name]) !== specifier ||
          !lock.packages?.[`node_modules/${name}`]
        )
          fail(`${client} npm importer:${name}`);
      }
      for (const [path, value] of Object.entries(lock.packages ?? {})) {
        const name = path.slice(path.lastIndexOf('node_modules/') + 13);
        if (!name.startsWith('@peanut-admin/')) continue;
        const i = packages[name];
        if (
          !i ||
          value.link ||
          value.version !== i.version ||
          value.resolved !== (mode === 'archive' ? `file:../${i.archive}` : i.resolved) ||
          value.integrity !== (mode === 'archive' ? archiveIntegrity(read, i) : i.integrity)
        )
          fail(`${client} npm resolution:${name}`);
        checked.push(`${client}:${name}`);
      }
    }
  }
  if (mode === 'archive') {
    for (const [name, i] of Object.entries(packages)) {
      if (
        createHash('sha256').update(read(i.archive)).digest('hex') !== i.sha256
      )
        fail(`${name} archive digest`);
    }
  }
  return {
    mode,
    checked,
    proof:
      mode === 'registry' || internal
        ? 'native-lock-identity'
        : 'development-archive-identity',
    published: false,
  };
}

function archiveIntegrity(read, identity) {
  const bytes = read(identity.archive);
  if (createHash('sha256').update(bytes).digest('hex') !== identity.sha256)
    fail('archive SHA-256');
  return `sha512-${createHash('sha512').update(bytes).digest('base64')}`;
}

/** Native lock identity rows, excluding only Core; no lock rewriting. */
export function thirdPartyLocks(read, parseYaml) {
  const result = {};
  for (const client of ['web', 'platform', 'pc', 'uniapp']) {
    const lock = client === 'web'
      ? parseYaml(String(read('web/pnpm-lock.yaml')))
      : JSON.parse(String(read(`${client}/package-lock.json`)));
    const rows = [];
    for (const [key, value] of Object.entries(lock.packages ?? {})) {
      if (!key) continue;
      // Peer-context paths may change when Core is projected; third-party
      // package name/version and native origin/integrity must not change.
      const name = client === 'web' ? key.split('(')[0]
        : key.slice(key.lastIndexOf('node_modules/') + 13);
      const packageName = client === 'web' ? name.slice(0, name.lastIndexOf('@')) : name;
      if (coreWebPackages.includes(packageName)) continue;
      rows.push(JSON.stringify([name, value.version ?? null,
        value.resolution ?? { resolved: value.resolved ?? null, integrity: value.integrity ?? null }]));
    }
    rows.sort();
    result[client] = createHash('sha256').update(JSON.stringify(rows)).digest('hex');
  }
  return result;
}

/** Compare every regular archive payload byte with the contained installation.
 * Python's stdlib reads archives without extracting or executing package code.
 */
function verifyArchivePayload(archive, location, kind, name, version) {
  const program = String.raw`
import hashlib,json,os,pathlib,stat,sys,tarfile,zipfile
archive,location,kind,name,version=sys.argv[1:]
root=pathlib.Path(location).resolve(strict=True)
if kind=='tgz':
    source=tarfile.open(archive,'r:gz')
    entries=[]
    for item in source.getmembers():
        if item.isdir(): continue
        if not item.isfile() or not item.name.startswith('package/'): raise ValueError('unsafe tar entry')
        entries.append((item.name[8:],source.extractfile(item).read()))
else:
    source=zipfile.ZipFile(archive)
    entries=[]
    for item in source.infolist():
        if item.is_dir(): continue
        if stat.S_ISLNK(item.external_attr>>16): raise ValueError('unsafe zip entry')
        entries.append((item.filename,source.read(item)))
    # Composer ZIPs may contain one repository prefix.
    if not any(p=='composer.json' for p,_ in entries):
        prefixes={p.split('/')[0] for p,_ in entries}
        if len(prefixes)!=1: raise ValueError('zip root')
        entries=[(p.split('/',1)[1],b) for p,b in entries]
seen=set()
for relative,data in entries:
    p=pathlib.PurePosixPath(relative)
    if p.is_absolute() or '..' in p.parts or str(p)!=relative or relative in seen: raise ValueError('archive path')
    seen.add(relative)
    actual=root.joinpath(*p.parts)
    if actual.is_symlink() or not actual.is_file() or not actual.resolve().is_relative_to(root): raise ValueError('installed path')
    if actual.read_bytes()!=data: raise ValueError('installed payload differs: '+relative)
for actual in root.rglob('*'):
    if actual.is_symlink(): raise ValueError('installed payload symlink')
    if actual.is_file() and actual.relative_to(root).as_posix() not in seen: raise ValueError('unexpected installed payload file')
manifest=json.loads((root/('package.json' if kind=='tgz' else 'composer.json')).read_text())
if manifest.get('name')!=name or (kind=='tgz' and manifest.get('version')!=version): raise ValueError('archive manifest')
print(json.dumps(manifest))
`;
  const result = spawnSync('python3', ['-c', program, archive, location, kind, name, version], { encoding: 'utf8' });
  if (result.status !== 0) fail(`installed archive payload:${name}: ${result.stderr?.trim()}`);
  return JSON.parse(result.stdout);
}

/** 只解析真实安装位置；pnpm目录内链接合法，发布候选不得借用应用外源码。 */
function installedPath(root, path) {
  const actual = realpathSync(path);
  if (!actual.startsWith(root + sep))
    fail('installed dependency outside application');
  return actual;
}

/** 读取Composer原生安装记录，不启动框架、不生成services.php、不访问数据库。
 * 调用者仍须先以verifyDependencyLocks核对原生锁；本函数不证明业务运行或制品未被本地改写。
 */
export function verifyInstalledPhp(applicationRoot, expected) {
  const root = realpathSync(applicationRoot);
  const autoload = installedPath(
    root,
    resolve(root, 'server/vendor/autoload.php')
  );
  if (!lstatSync(autoload).isFile()) fail('PHP autoload unavailable');
  const metadataPath = installedPath(
    root,
    resolve(root, 'server/vendor/composer/installed.json')
  );
  const document = JSON.parse(readFileSync(metadataPath, 'utf8'));
  const records = Array.isArray(document) ? document : document?.packages;
  if (!Array.isArray(records)) fail('PHP installed metadata');
  const matches = records.filter((item) => item?.name === 'peanut-admin/core');
  const installed = matches[0];
  if (
    matches.length !== 1 ||
    installed.version !== expected.resolved_version ||
    installed.source?.type !== expected.source_type ||
    installed.source?.url !== expected.source_url ||
    installed.source?.reference !== expected.source_reference ||
    installed.dist?.type === 'path' ||
    (installed.dist?.reference &&
      installed.dist.reference !== expected.source_reference) ||
    typeof installed['install-path'] !== 'string' ||
    installed['install-path'] === ''
  )
    fail('PHP installed identity differs from native lock');
  const location = installedPath(
    root,
    resolve(dirname(metadataPath), installed['install-path'])
  );
  const conventional = resolve(root, 'server/vendor/peanut-admin/core');
  if (
    lstatSync(conventional).isSymbolicLink() ||
    location !== installedPath(root, conventional)
  )
    fail('PHP installed location');
  const manifest = JSON.parse(
    readFileSync(
      installedPath(root, resolve(location, 'composer.json')),
      'utf8'
    )
  );
  if (manifest.name !== expected.package) fail('PHP installed package name');
  return {
    package: installed.name,
    version: installed.version,
    source_reference: installed.source.reference,
    path: relative(root, location),
    proof: 'native-installed-metadata-and-contained-location',
  };
}

/** 开发archive模式保持本地Core连接；registry候选严格核实际PHP/Web安装位置。 */
export function verifyInstalledDependencies(applicationRoot, document, internal = false, fixedInputs = null) {
  const root = realpathSync(applicationRoot),
    independent = dependencyMode(document) === 'registry' || internal || fixedInputs !== null;
  const php = independent ? verifyInstalledPhp(root, document.core_php) : null;
  if (internal) {
    const marker = JSON.parse(readFileSync(resolve(root, '.peanut/internal-candidate.json')));
    const locked = JSON.parse(readFileSync(resolve(root, 'server/composer.lock'))).packages.find((p) => p.name === 'peanut-admin/core');
    if (locked?.dist?.url !== `../${marker.core.path_in_archive}` || locked?.dist?.shasum !== marker.core.sha1)
      fail('PHP internal archive origin');
    verifyArchivePayload(resolve(root, marker.core.path_in_archive), resolve(root, php.path), 'zip', 'peanut-admin/core', document.core_php.resolved_version);
  }
  if (fixedInputs !== null) {
    verifyArchivePayload(resolve(root, fixedInputs.core_php_archive.path), resolve(root, php.path), 'zip', 'peanut-admin/core', document.core_php.resolved_version);
  }
  const web = [];
  for (const client of ['web', 'platform', 'pc', 'uniapp']) {
    const manifest = JSON.parse(
      readFileSync(resolve(root, client, 'package.json'), 'utf8')
    );
    for (const [name] of Object.entries({
      ...manifest.dependencies,
      ...manifest.devDependencies,
      ...manifest.optionalDependencies,
    })) {
      if (!name.startsWith('@peanut-admin/')) continue;
      const file = resolve(root, client, 'node_modules', name, 'package.json');
      const actual = independent
        ? installedPath(root, file)
        : realpathSync(file);
      const installed = JSON.parse(readFileSync(actual, 'utf8'));
      if (
        installed.name !== name ||
        installed.version !== document.core_web.packages[name]?.version
      )
        fail(`${client} installed:${name}`);
      if (internal || fixedInputs !== null) {
        const identity = document.core_web.packages[name];
        const payload = verifyArchivePayload(resolve(root, identity.archive), dirname(actual), 'tgz', name, identity.version);
        for (const [peer, constraint] of Object.entries(payload.peerDependencies ?? {})) {
          if (!peer.startsWith('@peanut-admin/')) continue;
          const selected = document.core_web.packages[peer];
          const declaration = { ...manifest.dependencies, ...manifest.devDependencies, ...manifest.optionalDependencies }[peer];
          if (!selected || constraint !== selected.version || declaration !== `file:../${selected.archive}`)
            fail(`${client}:${name} unresolved Core peer:${peer}`);
        }
      }
      web.push({
        client,
        package: name,
        version: installed.version,
        contained: actual.startsWith(root + sep),
        ...(internal || fixedInputs !== null ? { archive: document.core_web.packages[name].archive, proof: 'archive-payload-bytes-and-contained-location' } : {}),
      });
    }
  }
  return {
    php,
    web,
    independent_locations_checked: independent,
    proof: independent
      ? 'native-installed-identities-and-locations'
      : 'development-web-manifests-only',
    runtime_qualified: false,
  };
}

if (
  process.argv[1] &&
  resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
  try {
    const args = process.argv.slice(2);
    if (!args[0] || args.slice(1).some((arg) => !['--installed', '--internal-candidate', '--third-party-only'].includes(arg) && !arg.startsWith('--tool-root=') && !arg.startsWith('--core-inputs=')))
      throw new Error(
        'Usage: release-dependency-locks.mjs <application-root> [--installed] [--internal-candidate] [--core-inputs=<absolute-receipt>] [--third-party-only] [--tool-root=<root>]'
      );
    const root = realpathSync(args[0]);
    const read = (relative) => {
      const path = resolve(root, relative);
      if (!path.startsWith(root + '/') || lstatSync(path).isSymbolicLink())
        fail('source path');
      return readFileSync(path);
    };
    const document = JSON.parse(read('release-versions.json'));
    const internal = args.includes('--internal-candidate');
    const coreInputOptions = args.filter(arg => arg.startsWith('--core-inputs='));
    if (coreInputOptions.length > 1 || (coreInputOptions.length && (internal || args.includes('--third-party-only')))) fail('fixed Core input mode');
    const fixedInputs = coreInputOptions.length ? fixedArchiveInputs(root, coreInputOptions[0].slice(14), document, read) : null;
    if (internal && dependencyMode(document) !== 'archive') fail('internal candidate requires carried archives');
    const toolRoot = args.find((arg) => arg.startsWith('--tool-root='))?.slice(12) ?? root;
    const parseYaml = (internal || fixedInputs !== null || args.includes('--third-party-only') || dependencyMode(document) === 'registry')
      ? lockedYamlParser(toolRoot) : undefined;
    if (args.includes('--third-party-only')) {
      console.log(JSON.stringify(thirdPartyLocks(read, parseYaml)));
      process.exit(0);
    }
    const result = verifyDependencyLocks(
      document,
      read,
      parseYaml,
      internal || fixedInputs !== null
    );
    const installed =
      args.includes('--installed')
        ? verifyInstalledDependencies(root, document, internal, fixedInputs)
        : null;
    console.log(
      JSON.stringify({
        ...result,
        ...(fixedInputs !== null ? {fixed_archive_inputs: fixedInputs} : {}),
        installed_manifests_checked: args.includes('--installed'),
        installed,
      })
    );
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
