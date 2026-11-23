import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const webVite = readFileSync(
  resolve(root, 'web/config/vite.config.dev.ts'),
  'utf8'
);
const platformVite = readFileSync(
  resolve(root, 'platform/vite.config.mts'),
  'utf8'
);
const demoScript = readFileSync(
  resolve(root, 'scripts/local-multi-tenant-demo'),
  'utf8'
);
const hosts = [
  'platform.peanut-admin.test',
  'admin.peanut-admin.test',
  'tenant-a.peanut-admin.test',
  'tenant-b.peanut-admin.test',
];

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

for (const source of [webVite, platformVite]) {
  expect(
    source.includes("process.env.VITE_ALLOWED_HOSTS || ''") ||
      source.includes('environment.VITE_ALLOWED_HOSTS ||'),
    'Vite host allowlist is not environment-driven'
  );
  expect(
    source.includes('allowedHosts,'),
    'Vite server does not enforce the parsed host allowlist'
  );
}
expect(
  demoScript.includes(`VITE_ALLOWED_HOSTS ${hosts.join(',')}`),
  'local multi-tenant demo does not provide the registered host allowlist'
);

console.log('VITE-ALLOWED-HOSTS Web passed');
