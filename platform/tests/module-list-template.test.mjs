import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { compileTemplate, parse } from 'vue/compiler-sfc';

for (const [name, expected] of [
  ['DeveloperCenterPage.vue', ['moduleLabel(row.key)', 'moduleLabel(row.module_key, row.name)']],
  ['PlansPanel.vue', ['moduleLabel(item.module_key, item.name)', 'moduleLabel(key)']],
]) {
  test(name + ' compiles real Module name and key display slots', () => {
    const filename = fileURLToPath(new URL('../src/components/' + name, import.meta.url));
    const source = readFileSync(filename, 'utf8');
    const { descriptor, errors: parseErrors } = parse(source, { filename });
    assert.deepEqual(parseErrors, [], 'Vue SFC parser must accept the author source');
    assert.ok(descriptor.template, 'Module list needs a real Vue template');
    const compiled = compileTemplate({
      source: descriptor.template.content,
      filename,
      id: 'platform-module-name-' + name,
    });
    assert.deepEqual(compiled.errors, [], 'Vue template compiler must accept named slots');
    for (const pattern of expected) assert.ok(source.includes(pattern), pattern);
  });
}

test('Platform package selector keeps stable keys and does not authorize with display names', () => {
  const filename = fileURLToPath(new URL('../src/components/PlansPanel.vue', import.meta.url));
  const source = readFileSync(filename, 'utf8');
  assert.match(source, /:value="item\.module_key"/);
  assert.match(source, /module_keys:\s*\[\.\.\.form\.module_keys\]/);
  assert.match(source, /props\.canApi\(\.\.\.platformDeveloperRuntimeRead\.endpoints\[0\]\)/);
});


test('developer navigation declares five independently guarded routes and a legacy redirect', () => {
  const app = readFileSync(fileURLToPath(new URL('../src/App.vue', import.meta.url)), 'utf8');
  const source = readFileSync(fileURLToPath(new URL('../src/core/platform-shell-definitions.ts', import.meta.url)), 'utf8');
  for (const section of ['modules', 'installations', 'official', 'operations', 'diagnostics']) {
    assert.match(source, new RegExp("view: 'developer/" + section + "'"));
    assert.ok(app.includes("developer/" + section) || app.includes("platformDeveloperPages." + section + ".view") || (section === "modules" && app.includes("platformDeveloperPage.view")));
  }
  assert.match(app, /redirectLegacyDeveloper\(name: string\)/);
  assert.match(app, /authorizedDeveloperEntrance\(\)/);
  assert.match(app, /window\.history\.replaceState/);
  assert.match(app, /if \(!canView\(next\)\)/);
  assert.match(app, /:section="developerSection\(view\)"/);
});
