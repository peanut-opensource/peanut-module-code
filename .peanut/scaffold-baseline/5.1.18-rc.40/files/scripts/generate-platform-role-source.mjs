#!/usr/bin/env node
// Export the Platform Shell's existing role-candidate definitions for the native
// API generator. The TypeScript file is the only editable node source.
import { readFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import vm from 'node:vm';

const root = resolve(process.argv[2] ?? '.');
const relative = 'platform/src/core/platform-shell-definitions.ts';
const source = readFileSync(join(root, relative), 'utf8');
// The inspected application is not dependency-installed during scaffold creation.
// TypeScript belongs to the already validated API generator toolchain, not the APP.
const require = createRequire(new URL('../web/package.json', import.meta.url));
const ts = require('typescript');
const js = ts.transpileModule(source, {
  compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
}).outputText;
const exports = {};
// No imports or remote runtime: the source contains only the established Shell definitions.
vm.runInNewContext(js, { exports }, { filename: relative, timeout: 1500 });
const definitions = exports.platformShellPreviewDefinitions;
if (!Array.isArray(definitions) || !definitions.length || definitions.length > 1000)
  throw new Error('PLATFORM_ROLE_SOURCE_INVALID: empty or oversized Shell definitions');

const keyPattern = /^platform\.[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)*$/;
const nodes = [];
const keys = new Set();
let links = 0;
for (const definition of definitions) {
  const { key, parentKey, type, endpoints } = definition;
  if (typeof key !== 'string' || !keyPattern.test(key) || key.length > 160 ||
      keys.has(key) || (parentKey !== null && !keyPattern.test(parentKey)) ||
      !['group', 'page', 'action', 'operation'].includes(type) ||
      !Array.isArray(endpoints) || (type === 'group' && endpoints.length) ||
      (type === 'page' && typeof definition.view !== 'string'))
    throw new Error('PLATFORM_ROLE_SOURCE_INVALID: ' + String(key));
  const refs = endpoints.map((ref) => {
    if (!Array.isArray(ref) || ref.length !== 2 ||
        !['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'].includes(ref[0]) ||
        typeof ref[1] !== 'string' || !ref[1].startsWith('/platformapi/'))
      throw new Error('PLATFORM_ROLE_SOURCE_INVALID: endpoint ' + String(key));
    return [ref[0], ref[1]];
  });
  links += refs.length;
  if (links > 2000) throw new Error('PLATFORM_ROLE_SOURCE_INVALID: excessive API references');
  keys.add(key);
  nodes.push({
    key,
    parent_key: parentKey,
    type,
    route_path: type === 'page' ? '/platform/#/' + definition.view : null,
    api_endpoints: refs,
    api_owner: key.startsWith('platform.storage.') && type !== 'group' ? 'peanut.file' : 'platform',
  });
}
for (const node of nodes) {
  const visited = new Set([node.key]);
  let parent = node.parent_key;
  while (parent !== null) {
    if (!keys.has(parent) || visited.has(parent))
      throw new Error('PLATFORM_ROLE_SOURCE_INVALID: parent ' + node.key);
    visited.add(parent);
    const item = nodes.find((row) => row.key === parent);
    if (item.type === 'action' || item.type === 'operation')
      throw new Error('PLATFORM_ROLE_SOURCE_INVALID: invalid parent ' + parent);
    parent = item.parent_key;
  }
}
process.stdout.write(JSON.stringify({
  schema_version: 1,
  source_path: relative,
  source_sha256: createHash('sha256').update(source).digest('hex'),
  nodes,
}));
