import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const web = fileURLToPath(new URL('../../', import.meta.url));

test('Shell resolves all four components using the installed host packages', async () => {
  const temporary = await mkdtemp(join(tmpdir(), 'peanut-shell-overrides-'));
  const keys = ['login-extra', 'navbar-actions', 'workbench-extra', 'footer'];
  const exports = ['loginExtra', 'navbarActions', 'workbenchExtra', 'footerOverride'];
  const overrides = join(temporary, 'overrides.mjs');
  const server = await createServer({
    root: web,
    configFile: false,
    optimizeDeps: { noDiscovery: true, include: [] },
    server: { middlewareMode: true, watch: null, ws: false },
    resolve: { alias: [
      { find: '@/peanut.overrides', replacement: overrides },
      { find: '@', replacement: join(web, 'src') },
    ] },
  });

  try {
    await writeFile(overrides, 'export default [];');
    let runtime = await server.ssrLoadModule('/src/core/runtime.ts');
    assert.deepEqual(exports.map((name) => runtime[name]), [null, null, null, null]);

    const slots = keys.map((key, index) => ({
      key: `peanut.shell.component.${key}`,
      kind: 'component',
      contractVersion: '1.0.0',
      value: index,
    }));
    await writeFile(overrides, `
      const slots = ${JSON.stringify(slots)};
      export default slots.map((slot) => ({ ...slot, value: { render: () => slot.value } }));
    `);
    server.moduleGraph.invalidateAll();
    runtime = await server.ssrLoadModule('/src/core/runtime.ts');
    assert.deepEqual(exports.map((name) => runtime[name].render()), [0, 1, 2, 3]);
  } finally {
    await server.close();
    await rm(temporary, { recursive: true, force: true });
  }
});
