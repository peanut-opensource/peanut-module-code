import type { Router } from 'vue-router';
import { DEFAULT_ROUTE_NAME, REDIRECT_ROUTE_NAME } from './constants';

// Both login entry and login completion consume the full path saved by the guard.
export function loginDestination(
  router: Router,
  redirect: unknown,
  previousPath?: string
) {
  for (const path of [redirect, previousPath]) {
    if (
      typeof path !== 'string' ||
      !path.startsWith('/') ||
      path.startsWith('//') ||
      path.includes('\\')
    )
      continue;
    const route = router.resolve(path);
    if (
      route.meta.requiresAuth &&
      route.name !== REDIRECT_ROUTE_NAME &&
      route.name !== 'redirectWrapper'
    )
      return { path: route.fullPath, replace: true };
  }
  return { name: DEFAULT_ROUTE_NAME, replace: true };
}
