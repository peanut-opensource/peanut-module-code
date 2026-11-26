import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadConfigFromFile } from 'vite';

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

const webRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const configFile = resolve(webRoot, 'vite.config.ts');
const virtualId = 'virtual:peanut-instance-tool-routes';

async function virtualRouteSource(command, mode, deploymentMode) {
  const previousMode = process.env.VITE_DEPLOYMENT_MODE;
  process.env.VITE_DEPLOYMENT_MODE = deploymentMode;
  let loaded;
  try {
    loaded = await loadConfigFromFile(
      { command, mode },
      configFile,
      webRoot,
      'silent',
      undefined,
      'runner'
    );
  } finally {
    if (previousMode === undefined) delete process.env.VITE_DEPLOYMENT_MODE;
    else process.env.VITE_DEPLOYMENT_MODE = previousMode;
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
  'standalone'
);
expect(
  standaloneDev.includes('/src/router/routes/modules/dev-tools.ts'),
  'development Standalone lost the unique dev-tools route import'
);

const multiTenantDev = await virtualRouteSource(
  'serve',
  'development',
  'multi-tenant'
);
expect(
  multiTenantDev.trim() === 'export default [];',
  'serve/multi-tenant generated a dev-tools import edge'
);
for (const mode of ['production', 'standalone', 'multi-tenant']) {
  const edition = mode === 'multi-tenant' ? mode : 'standalone';
  const bundle = await virtualRouteSource('build', mode, edition);
  expect(
    bundle.trim() === 'export default [];',
    `build/${mode} generated a dev-tools import edge`
  );
}

const routeIndex = readFileSync(
  resolve(webRoot, 'src/router/routes/index.ts'),
  'utf8'
);
expect(
  routeIndex.includes("'!./modules/dev-tools.ts'") &&
    routeIndex.includes("from 'virtual:peanut-instance-tool-routes'"),
  'ordinary eager routes no longer exclude dev-tools or lost the virtual gate'
);

// eslint-disable-next-line no-console
console.log('INSTANCE-TOOL-BUILD-BOUNDARY-D4-001 passed');
