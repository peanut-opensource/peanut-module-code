import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadConfigFromFile } from 'vite';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const hosts = [
  'platform.peanut-admin.test',
  'admin.peanut-admin.test',
  'tenant-a.peanut-admin.test',
  'tenant-b.peanut-admin.test',
];

async function configuredHosts(client, value) {
  const clientRoot = resolve(root, client);
  const previous = process.env.VITE_ALLOWED_HOSTS;
  process.env.VITE_ALLOWED_HOSTS = value;
  try {
    const loaded = await loadConfigFromFile(
      { command: 'serve', mode: 'development' },
      resolve(clientRoot, 'vite.config.ts'),
      clientRoot,
      'silent',
      undefined,
      'runner'
    );
    assert.ok(loaded, `${client} Vite config could not be loaded`);
    return loaded.config.server?.allowedHosts;
  } finally {
    if (previous === undefined) delete process.env.VITE_ALLOWED_HOSTS;
    else process.env.VITE_ALLOWED_HOSTS = previous;
  }
}

for (const client of ['web', 'platform']) {
  assert.deepEqual(
    await configuredHosts(client, ` ${hosts.join(' , ')} , , `),
    hosts,
    `${client} Vite must use the supplied host allowlist exactly`
  );
  assert.match(
    readFileSync(resolve(root, client, '.env.example'), 'utf8'),
    /^VITE_ALLOWED_HOSTS=$/m,
    `${client} example must leave host admission to the instance`
  );
  assert.deepEqual(
    await configuredHosts(client, ''),
    [],
    `${client} Vite must leave the optional host list empty by default`
  );
}

console.log('VITE-ALLOWED-HOSTS Web and Platform passed');
