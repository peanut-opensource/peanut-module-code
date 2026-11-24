import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const webRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const navbar = readFileSync(
  resolve(webRoot, 'src/components/navbar/index.vue'),
  'utf8'
);
const zhLocale = readFileSync(resolve(webRoot, 'src/locale/zh-CN.ts'), 'utf8');
const enLocale = readFileSync(resolve(webRoot, 'src/locale/en-US.ts'), 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(
  navbar.includes("userStore.avatar?.trim() || '/brand/avatar-admin.svg'"),
  'empty avatars must use the local administrator avatar'
);
expect(
  navbar.includes('<el-avatar :size="32" :src="avatar" alt="">') &&
    navbar.includes('{{ avatarInitial }}'),
  'failed avatar requests must fall back to the identity initial slot'
);
expect(
  navbar.includes("userStore.name?.trim() || userStore.email?.trim() || '?'"),
  'avatar fallback must derive an initial from the user identity'
);
expect(
  navbar.includes('class="user-menu-trigger"') &&
    navbar.includes(':aria-label="$t(\'navbar.userMenu\')"'),
  'the user menu trigger must remain an accessible button'
);
expect(
  !navbar.includes('<img alt="avatar" :src="avatar"'),
  'the navbar must not render a raw image without fallback handling'
);
expect(
  existsSync(resolve(webRoot, 'public/brand/avatar-admin.svg')),
  'the local administrator avatar asset is missing'
);
expect(
  zhLocale.includes("'navbar.userMenu': '打开用户菜单'") &&
    enLocale.includes("'navbar.userMenu': 'Open user menu'"),
  'the accessible user menu label must exist in both locales'
);

console.log('NAVBAR-AVATAR-FALLBACK Web passed');
