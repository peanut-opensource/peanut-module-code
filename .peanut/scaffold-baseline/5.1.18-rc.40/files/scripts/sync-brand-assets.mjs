import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const {
  values: { check: checkOnly, client: clients },
} = parseArgs({
  options: {
    check: { type: 'boolean', default: false },
    client: { type: 'string', multiple: true },
  },
});
const targetsByClient = {
  web: { manifest: 'web/src/generated/brand.json', assets: 'web/public/brand' },
  pc: { manifest: 'pc/generated/brand.json', assets: 'pc/public/brand' },
  uniapp: {
    manifest: 'uniapp/src/generated/brand.json',
    assets: 'uniapp/src/static/brand',
  },
};
if (
  clients &&
  (clients.length !== 1 || !Object.hasOwn(targetsByClient, clients[0]))
) {
  throw new Error('--client must be one of web, pc, or uniapp');
}
const targets = clients
  ? [targetsByClient[clients[0]]]
  : Object.values(targetsByClient);
const manifestSource = join(repositoryRoot, 'server/config/brand.json');
const assetSource = join(repositoryRoot, 'server/public/brand');
const manifest = JSON.parse(await readFile(manifestSource, 'utf8'));

if (manifest.schema_version !== 1 || !manifest.website) {
  throw new Error('server/config/brand.json does not match schema version 1');
}

const sidebarFooterDefaults = {
  sidebar_footer_enabled: true,
  sidebar_footer_title: '',
  sidebar_footer_settings: true,
  sidebar_footer_license: true,
  sidebar_footer_about: true,
};
const generatedManifest = `${JSON.stringify(
  {
    ...manifest,
    sidebar_footer: {
      ...sidebarFooterDefaults,
      ...(manifest.sidebar_footer ?? {}),
    },
  },
  null,
  2
)}\n`;
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

for (const { manifest: target } of targets)
  await sync(target, generatedManifest);
for (const assetName of assetNames) {
  const content = await readFile(join(assetSource, assetName), 'utf8');
  for (const { assets } of targets) {
    await sync(`${assets}/${assetName}`, content);
  }
}

if (!checkOnly) {
  process.stdout.write('Brand defaults and assets synchronized.\n');
}
