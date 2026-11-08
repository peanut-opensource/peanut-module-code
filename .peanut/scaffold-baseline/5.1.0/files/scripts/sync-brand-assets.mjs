import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const checkOnly = process.argv.includes('--check');
const manifestSource = join(repositoryRoot, 'server/config/brand.json');
const assetSource = join(repositoryRoot, 'server/public/brand');
const manifest = JSON.parse(await readFile(manifestSource, 'utf8'));

if (manifest.schema_version !== 1 || !manifest.website) {
  throw new Error('server/config/brand.json does not match schema version 1');
}

const generatedManifest = `${JSON.stringify(manifest, null, 2)}\n`;
const manifestTargets = [
  'web/src/generated/brand.json',
  'pc/generated/brand.json',
  'uniapp/src/generated/brand.json',
];
const assetNames = [
  'favicon.svg',
  'logo.svg',
  'login-background.svg',
  'avatar-admin.svg',
  'avatar-member.svg',
  'menu.svg',
  'docs.svg',
  'support.svg',
];
const assetTargets = [
  'web/public/brand',
  'pc/public/brand',
  'uniapp/src/static/brand',
];

async function sync(target, content) {
  const path = join(repositoryRoot, target);
  if (checkOnly) {
    const current = await readFile(path, 'utf8').catch(() => null);
    if (current !== content)
      throw new Error(`generated brand file is stale: ${target}`);
    return;
  }
  await mkdir(dirname(path), { recursive: true });
  await writeFile(path, content);
}

for (const target of manifestTargets) await sync(target, generatedManifest);
for (const assetName of assetNames) {
  const content = await readFile(join(assetSource, assetName), 'utf8');
  for (const targetDirectory of assetTargets) {
    await sync(`${targetDirectory}/${assetName}`, content);
  }
}

if (!checkOnly) {
  process.stdout.write('Brand defaults and assets synchronized.\n');
}
