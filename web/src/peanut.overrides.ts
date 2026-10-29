import type { AdminOverride } from '@peanut-admin/vue';

/**
 * 应用对核心前端能力的唯一覆盖入口。
 *
 * 覆盖必须使用核心声明的稳定 key、类型和契约版本；禁止修改 node_modules
 * 或复制核心实现后通过 Vite alias 静默替换。
 */
const PEANUT_ADMIN_OVERRIDES: readonly AdminOverride[] = Object.freeze([]);

export default PEANUT_ADMIN_OVERRIDES;
