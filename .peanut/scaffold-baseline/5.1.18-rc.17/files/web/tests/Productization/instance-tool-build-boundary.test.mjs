import { mkdtempSync, rmSync, writeFileSync } from 'fs';
import { resolve } from 'path';
import { tmpdir } from 'os';
import { fileURLToPath } from 'url';
import { loadConfigFromFile } from 'vite';

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

const webRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const virtualId = 'virtual:peanut-instance-tool-routes';

async function virtualRouteSource(command, mode, deploymentMode, configFile) {
  const envDirectory = mkdtempSync(resolve(tmpdir(), 'pa-vite-boundary-'));
  const envFile = resolve(envDirectory, '.env');
  const previousEnvFile = process.env.PEANUT_CLIENT_ENV_FILE;
  writeFileSync(envFile, `VITE_DEPLOYMENT_MODE=${deploymentMode}\n`);
  process.env.PEANUT_CLIENT_ENV_FILE = envFile;
  let loaded;
  try {
    loaded = await loadConfigFromFile(
      { command, mode },
      resolve(webRoot, configFile),
      webRoot
    );
  } finally {
    if (previousEnvFile === undefined)
      delete process.env.PEANUT_CLIENT_ENV_FILE;
    else process.env.PEANUT_CLIENT_ENV_FILE = previousEnvFile;
    rmSync(envDirectory, { recursive: true, force: true });
  }
  expect(loaded !== null, `${configFile} could not be loaded`);
  const plugin = loaded.config.plugins?.find(
    (candidate) => candidate.name === 'peanut-instance-tool-route-manifest'
  );
  expect(plugin, 'instance-tool virtual module plugin is missing');
  expect(
    typeof plugin.resolveId === 'function',
    'virtual route resolver is missing'
  );
  expect(typeof plugin.load === 'function', 'virtual route loader is missing');
  const resolvedId = await plugin.resolveId(virtualId);
  return plugin.load(resolvedId);
}

const standaloneDev = await virtualRouteSource(
  'serve',
  'development',
  'standalone',
  'config/vite.config.dev.ts'
);
expect(
  standaloneDev.includes('/src/router/routes/modules/dev-tools.ts'),
  'development Standalone lost the unique dev-tools route import'
);

const multiTenantDev = await virtualRouteSource(
  'serve',
  'development',
  'multi-tenant',
  'config/vite.config.dev.ts'
);
expect(
  multiTenantDev.trim() === 'export default [];',
  'serve/multi-tenant generated a dev-tools import edge'
);
const standaloneBuild = await virtualRouteSource(
  'build',
  'production',
  'standalone',
  'config/vite.config.prod.ts'
);
expect(
  standaloneBuild.trim() === 'export default [];',
  'build/standalone generated a dev-tools import edge'
);

const routeIndex = await import('fs').then(({ readFileSync }) =>
  readFileSync(resolve(webRoot, 'src/router/routes/index.ts'), 'utf8')
);
expect(
  routeIndex.includes("'!./modules/dev-tools.ts'") &&
    routeIndex.includes("from 'virtual:peanut-instance-tool-routes'"),
  'ordinary eager routes no longer exclude dev-tools or lost the virtual gate'
);

// eslint-disable-next-line no-console
console.log('INSTANCE-TOOL-BUILD-BOUNDARY-D4-001 passed');
