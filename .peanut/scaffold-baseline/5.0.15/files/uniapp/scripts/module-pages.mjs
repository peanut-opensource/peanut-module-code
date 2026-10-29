import {
  existsSync,
  readFileSync,
  readdirSync,
  mkdirSync,
  realpathSync,
  unlinkSync,
  writeFileSync,
} from 'node:fs';
import { dirname, isAbsolute, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadConfigFromFile } from 'vite';
import { moduleFrontendEntries } from '../../scripts/module-frontend-entries.mjs';

const defaultRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const marker = '<!-- Generated Module page: ';
function pageAdapter(source) {
  const quoted = source.replaceAll('\\', '\\\\').replaceAll("'", "\\'");
  return `${marker}${source} -->\n<script lang="ts">\n  import Page from '${quoted}';\n  export default Page;\n</script>\n`;
}

function ownedAdapters(directory) {
  if (!existsSync(directory)) return [];
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = resolve(directory, entry.name);
    if (entry.isDirectory()) return ownedAdapters(path);
    if (!entry.isFile() || !entry.name.endsWith('.vue')) return [];
    const content = readFileSync(path, 'utf8');
    if (!content.startsWith(marker)) return [];
    const source = content.slice(marker.length, content.indexOf(' -->'));
    if (content !== pageAdapter(source))
      throw new Error(`Module page has local changes: ${path}`);
    return [path];
  });
}

function moduleSource(entry, localPath, sourceRoot) {
  if (typeof localPath !== 'string' || !localPath.startsWith('./'))
    throw new Error('Invalid Module component path');
  const root = dirname(entry);
  const path = resolve(root, localPath);
  const fromRoot = relative(root, path);
  if (
    fromRoot.startsWith('..') ||
    !path.endsWith('.vue') ||
    !existsSync(path) ||
    realpathSync(path) !== path
  ) {
    throw new Error(`Module component is unavailable: ${path}`);
  }
  return `@/${relative(sourceRoot, path).split(sep).join('/')}`;
}

export async function prepareModulePages(configEnv, targetRoot = defaultRoot) {
  const root = resolve(targetRoot);
  const sourceRoot = resolve(root, 'src');
  const entries = moduleFrontendEntries(
    resolve(root, '..'),
    'uniapp',
    configEnv.command === 'serve'
  );
  const contributions = [];
  for (const entry of entries) {
    const loaded = await loadConfigFromFile(configEnv, entry, root);
    if (!loaded)
      throw new Error(`Module contribution cannot be loaded: ${entry}`);
    contributions.push({ entry, ...loaded.config });
  }
  const pages = JSON.parse(
    readFileSync(resolve(sourceRoot, 'pages.base.json'), 'utf8')
  );
  const paths = new Set(pages.pages.map((page) => page.path));
  const adapters = new Map();
  const slots = new Map();
  for (const contribution of contributions) {
    for (const page of contribution.pages ?? []) {
      if (
        typeof page.path !== 'string' ||
        !/^(?:pages|packages\/pages)\/[a-z0-9_/-]+$/.test(page.path) ||
        page.path.includes('..') ||
        paths.has(page.path)
      ) {
        throw new Error(
          `Module page path is invalid or duplicated: ${page.path}`
        );
      }
      paths.add(page.path);
      const { component, ...nativePage } = page;
      const destination = resolve(sourceRoot, `${page.path}.vue`);
      adapters.set(
        destination,
        pageAdapter(moduleSource(contribution.entry, component, sourceRoot))
      );
      pages.pages.push(nativePage);
    }
    for (const [name, component] of Object.entries(contribution.slots ?? {})) {
      if (slots.has(name))
        throw new Error(`Module slot is duplicated: ${name}`);
      slots.set(name, moduleSource(contribution.entry, component, sourceRoot));
    }
  }
  // Refuse to replace application-owned pages, including edited generated adapters.
  const previous = ownedAdapters(resolve(sourceRoot, 'pages')).concat(
    ownedAdapters(resolve(sourceRoot, 'packages/pages'))
  );
  for (const path of adapters.keys()) {
    if (existsSync(path) && !previous.includes(path))
      throw new Error(`Module page conflicts with application source: ${path}`);
  }
  for (const path of previous) if (!adapters.has(path)) unlinkSync(path);
  for (const [path, source] of adapters) {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, source);
  }
  writeFileSync(
    resolve(sourceRoot, 'pages.json'),
    `${JSON.stringify(pages, null, 2)}\n`
  );
  // Static component imports keep this slot usable by native UniApp compilers.
  const imports = [...slots]
    .map(([, path], index) => `  import Slot${index} from '${path}';`)
    .join('\n');
  const template = [...slots]
    .map(([name], index) => `    <Slot${index} v-if="name === '${name}'" />`)
    .join('\n');
  writeFileSync(
    resolve(sourceRoot, 'components/ModuleSlot.vue'),
    `<!-- Generated from installed Module contributions. -->\n<template>\n${
      slots.size ? `  <view>\n${template}\n  </view>` : '  <view />'
    }\n</template>\n<script setup lang="ts">\n${imports}${
      imports ? '\n' : ''
    }  defineProps<{ name: string }>();\n</script>\n`
  );
  return {
    name: 'peanut-module-pages',
    resolveId(id) {
      return id === 'virtual:peanut-module-contributions'
        ? '\0peanut-module-contributions'
        : null;
    },
    load(id) {
      if (id !== '\0peanut-module-contributions') return null;
      return `export const moduleKeys = ${JSON.stringify(
        contributions.map((c) => c.moduleKey)
      )};\nexport const moduleSlots = ${JSON.stringify([
        ...slots.keys(),
      ])};\nexport const modulePages = ${JSON.stringify([...paths])};`;
    },
  };
}

if (
  process.argv[1] &&
  resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
  const [option, root, ...extra] = process.argv.slice(2);
  if (option !== '--root' || !root || !isAbsolute(root) || extra.length) {
    throw new Error(
      'Usage: node module-pages.mjs --root <absolute UniApp root>'
    );
  }
  await prepareModulePages({ command: 'build', mode: 'production' }, root);
}
