import {
  mkdtempSync,
  mkdirSync,
  readFileSync,
  readdirSync,
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

function manifestPaths(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = resolve(directory, entry.name);
    if (entry.isDirectory()) return manifestPaths(path);
    return entry.isFile() && entry.name === 'module.json' ? [path] : [];
  });
}

const webRoot = realpathSync(
  resolve(fileURLToPath(new URL('../..', import.meta.url)))
);
const projectRoot = resolve(webRoot, '..');
const virtualId = 'virtual:peanut-plugin-contributions';

async function virtualSource() {
  const loaded = await loadConfigFromFile(
    { command: 'serve', mode: 'development' },
    resolve(webRoot, 'config/vite.config.dev.ts'),
    webRoot
  );
  expect(loaded !== null, 'development Vite config could not be loaded');
  const plugin = loaded.config.plugins?.find(
    (candidate) => candidate.name === 'peanut-plugin-contribution-manifest'
  );
  expect(plugin, 'plugin contribution virtual module is missing');
  const resolvedId = await plugin.resolveId(virtualId);
  return plugin.load(resolvedId);
}

async function productionVirtualSource(entries) {
  const probeDirectory = mkdtempSync(
    resolve(tmpdir(), 'pa-module-production-contribution-')
  );
  const probe = resolve(probeDirectory, 'vite.config.ts');
  try {
    writeFileSync(
      probe,
      `import { createBaseConfig } from ${JSON.stringify(
        resolve(webRoot, 'config/vite.config.base.ts')
      )};\nexport default createBaseConfig({ command: 'build', mode: 'production' }, () => ${JSON.stringify(
        entries
      )});\n`
    );
    const loaded = await loadConfigFromFile(
      { command: 'build', mode: 'production' },
      probe,
      probeDirectory
    );
    expect(loaded !== null, 'production Vite config could not be loaded');
    const plugin = loaded.config.plugins?.find(
      (candidate) => candidate.name === 'peanut-plugin-contribution-manifest'
    );
    expect(plugin, 'production contribution virtual module is missing');
    const resolvedId = await plugin.resolveId(virtualId);
    return await plugin.load(resolvedId);
  } finally {
    rmSync(probeDirectory, { recursive: true, force: true });
  }
}

const first = await virtualSource();
const second = await virtualSource();
expect(
  first === second,
  'development contribution discovery is not deterministic'
);
const expectedEntries = manifestPaths(
  resolve(projectRoot, 'server/app/modules')
)
  .map((path) => JSON.parse(readFileSync(path, 'utf8')))
  .map((manifest) => manifest.frontend?.entry)
  .filter(Boolean)
  .sort();
expectedEntries.forEach((entry) => {
  const importPath = `/${entry.slice('web/'.length)}`;
  expect(
    first.includes(JSON.stringify(importPath)),
    `development Vite omitted ${entry}`
  );
});
expect(
  (first.match(/^import contribution/gm) || []).length ===
    expectedEntries.length,
  'development Vite contribution count differs from module.json'
);

const devConfigSource = readFileSync(
  resolve(webRoot, 'config/vite.config.dev.ts'),
  'utf8'
);
expect(
  !devConfigSource.includes('plugins.lock') &&
    devConfigSource.includes(
      'createBaseConfig(configEnv, discoverAdminContributions)'
    ),
  'development Vite config still depends on plugins.lock'
);

const normalizedProductionSource = await productionVirtualSource([
  'web/src/modules/official-article/../official-article/contribution.ts',
]);
expect(
  normalizedProductionSource.includes(
    JSON.stringify('/src/modules/official-article/contribution.ts')
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
    resolve(webRoot, 'src/modules/official-article/contribution.ts'),
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

const temporary = mkdtempSync(resolve(tmpdir(), 'pa-module-dev-discovery-'));
try {
  const invalidRoot = resolve(
    temporary,
    'server/app/modules/fixture/invalid_entry'
  );
  mkdirSync(invalidRoot, { recursive: true });
  mkdirSync(resolve(temporary, 'web/src/modules/fixture-invalid-entry'), {
    recursive: true,
  });
  writeFileSync(
    resolve(invalidRoot, 'module.json'),
    JSON.stringify({
      key: 'fixture.invalid-entry',
      frontend: { entry: 'web/src/modules/wrong/contribution.ts' },
    })
  );
  const probe = resolve(temporary, 'vite.config.ts');
  writeFileSync(
    probe,
    `import { discoverAdminContributions } from ${JSON.stringify(
      resolve(webRoot, 'config/vite.config.dev.ts')
    )};\nexport default { entries: discoverAdminContributions(${JSON.stringify(
      temporary
    )}) };\n`
  );
  let rejected = false;
  try {
    await loadConfigFromFile(
      { command: 'serve', mode: 'development' },
      probe,
      temporary
    );
  } catch (error) {
    rejected = String(error).includes('frontend.entry differs from key');
  }
  expect(rejected, 'mismatched development frontend.entry was accepted');
} finally {
  rmSync(temporary, { recursive: true, force: true });
}

// eslint-disable-next-line no-console
console.log(
  `MODULE-DEV-DISCOVERY-C-001 passed modules=${expectedEntries.length}`
);
