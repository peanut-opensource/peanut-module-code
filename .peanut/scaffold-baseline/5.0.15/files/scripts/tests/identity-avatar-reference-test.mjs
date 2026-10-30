import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';

const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..'
);
const require = createRequire(path.join(root, 'web/package.json'));
const compiler = require('vue/compiler-sfc');
const ts = require('typescript');
const file = path.join(root, 'web/src/views/user/setting/index.vue');
const parsed = compiler.parse(fs.readFileSync(file, 'utf8'), {
  filename: file,
});
assert.deepEqual(parsed.errors, []);
const source = parsed.descriptor.scriptSetup.content;
const syntax = ts.createSourceFile(
  file,
  source,
  ts.ScriptTarget.Latest,
  true,
  ts.ScriptKind.TS
);
const functions = syntax.statements.filter(
  (statement) =>
    ts.isVariableStatement(statement) &&
    statement.declarationList.declarations.some((item) =>
      ['onAvatarSuccess', 'saveBasic'].includes(item.name.getText(syntax))
    )
);
assert.equal(functions.length, 2);
const code = ts.transpileModule(
  functions.map((statement) => statement.getText(syntax)).join('\n'),
  {
    compilerOptions: { target: ts.ScriptTarget.ES2020 },
  }
).outputText;

test('own avatar callback and save use file_key while displaying its delivery URL', async () => {
  const key = `file_${'a'.repeat(32)}`;
  const basicForm = { nickname: 'Actor', avatar: '', avatarUrl: '' };
  const writes = [];
  const errors = [];
  const ui = vm.runInNewContext(`${code}\n({onAvatarSuccess,saveBasic});`, {
    basicForm,
    basicRef: { value: { validate: async () => true } },
    basicLoading: { value: false },
    ElMessage: { success: () => {}, error: (message) => errors.push(message) },
    t: (key) => key,
    editAdminSelf: async (payload) => {
      writes.push({ ...payload });
    },
    getAdminSelf: async () => ({
      data: {
        nickname: 'Actor',
        avatar_uri: key,
        avatar: 'https://files.example.test/delivery',
      },
    }),
    userStore: { setInfo: () => {} },
  });
  ui.onAvatarSuccess({
    file_key: key,
    uri: 'physical/objects/a.png',
    url: 'https://files.example.test/delivery',
  });
  assert.equal(basicForm.avatar, key);
  assert.equal(basicForm.avatarUrl, 'https://files.example.test/delivery');
  await ui.saveBasic();
  assert.equal(writes[0].avatar, key);
  ui.onAvatarSuccess({
    uri: 'physical/objects/b.png',
    url: 'https://files.example.test/other',
  });
  assert.equal(errors.length, 1);
  assert.equal(basicForm.avatar, key);
});
