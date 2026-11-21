import {
  mkdtempSync,
  readFileSync,
  realpathSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from 'fs';
import { tmpdir } from 'os';
import { resolve, sep } from 'path';
import { fileURLToPath } from 'url';
import { loadConfigFromFile } from 'vite';

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

const webRoot = realpathSync(
  resolve(fileURLToPath(new URL('../..', import.meta.url)))
);
const projectRoot = resolve(webRoot, '..');
const virtualId = 'virtual:peanut-module-contributions';

async function virtualSource(command, mode) {
  const loaded = await loadConfigFromFile(
    { command, mode },
    resolve(webRoot, 'vite.config.ts'),
    webRoot
  );
  expect(loaded !== null, 'development Vite config could not be loaded');
  const plugin = loaded.config.plugins?.find(
    (candidate) => candidate.name === 'peanut-module-contribution-manifest'
  );
  expect(plugin, 'plugin contribution virtual module is missing');
  const resolvedId = await plugin.resolveId(virtualId);
  return plugin.load(resolvedId);
}

async function productionVirtualSource(entries, mode = 'production') {
  const probeDirectory = mkdtempSync(
    resolve(tmpdir(), 'pa-module-production-contribution-')
  );
  const probe = resolve(probeDirectory, 'vite.config.ts');
  try {
    writeFileSync(
      probe,
      `import { createBaseConfig } from ${JSON.stringify(
        resolve(webRoot, 'vite.config.ts')
      )};\nexport default createBaseConfig({ command: 'build', mode: ${JSON.stringify(mode)} }, {}, () => ${JSON.stringify(
        entries
      )});\n`
    );
    const loaded = await loadConfigFromFile(
      { command: 'build', mode },
      probe,
      probeDirectory
    );
    expect(loaded !== null, 'production Vite config could not be loaded');
    if (mode !== 'production') {
      expect(
        loaded.config.define['import.meta.env.VITE_DEPLOYMENT_MODE'] === JSON.stringify(mode),
        `generated APP without a mode env file lost its explicit ${mode} build`
      );
    }
    const plugin = loaded.config.plugins?.find(
      (candidate) => candidate.name === 'peanut-module-contribution-manifest'
    );
    expect(plugin, 'production contribution virtual module is missing');
    const resolvedId = await plugin.resolveId(virtualId);
    return await plugin.load(resolvedId);
  } finally {
    rmSync(probeDirectory, { recursive: true, force: true });
  }
}

const first = await virtualSource('serve', 'development');
const built = await virtualSource('build', 'production');
expect(first === built, 'development and production Vite resolved different locked entries');
const lock = JSON.parse(readFileSync(resolve(projectRoot, 'modules.lock'), 'utf8'));
const expectedEntries = lock.modules.flatMap((module) => module.frontend)
  .filter((entry) => entry.client_key === 'admin-web')
  .map((entry) => entry.entry)
  .sort();
expect(expectedEntries.length > 0, 'admin-web lock has no entries');
for (const entry of expectedEntries) {
  expect(first.includes(JSON.stringify('/' + entry.slice('web/'.length))), 'Vite omitted ' + entry);
}
expect((first.match(/^import contribution/gm) || []).length === expectedEntries.length,
  'Vite contribution count differs from the installed lock');

for (const edition of ['standalone', 'multi-tenant']) {
  await productionVirtualSource([], edition);
}

const normalizedProductionSource = await productionVirtualSource([
  'web/src/modules/peanut-content-tools/../peanut-content-tools/contribution.ts',
]);
expect(
  normalizedProductionSource.includes(
    JSON.stringify('/src/modules/peanut-content-tools/contribution.ts')
  ),
  'production Vite did not normalize a legal contribution path'
);

for (const [entry, expectedError] of [
  ['web/src/../../package.json', 'outside web/src'],
]) {
  let rejected = false;
  try {
    await productionVirtualSource([entry]);
  } catch (error) {
    rejected = String(error).includes(expectedError);
  }
  expect(rejected, `production Vite accepted unsafe contribution: ${entry}`);
}

const symlinkDirectory = mkdtempSync(
  resolve(webRoot, 'src/.pa-module-contribution-symlink-')
);
try {
  symlinkSync(
    resolve(webRoot, 'src/modules/peanut-content-tools/contribution.ts'),
    resolve(symlinkDirectory, 'contribution.ts')
  );
  const symlinkEntry = `web/src/${symlinkDirectory
    .slice(resolve(webRoot, 'src').length + 1)
    .split(sep)
    .join('/')}/contribution.ts`;
  let rejected = false;
  try {
    await productionVirtualSource([symlinkEntry]);
  } catch (error) {
    rejected = String(error).includes('unavailable or a symlink');
  }
  expect(rejected, 'production Vite accepted a symlinked contribution');
} finally {
  rmSync(symlinkDirectory, { recursive: true, force: true });
}

// eslint-disable-next-line no-console
console.log(
  `MODULE-DEV-DISCOVERY-C-001 passed modules=${expectedEntries.length}`
);
