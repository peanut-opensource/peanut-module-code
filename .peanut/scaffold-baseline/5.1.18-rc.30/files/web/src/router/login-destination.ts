import type { Router } from 'vue-router';
import { DEFAULT_ROUTE_NAME, REDIRECT_ROUTE_NAME } from './constants';

// Both login entry and login completion consume the full path saved by the guard.
// eslint-disable-next-line import/prefer-default-export
export function loginDestination(
  router: Router,
  redirect: unknown,
  previousPath?: string
) {
  const matched = [redirect, previousPath].find((path) => {
    if (
      typeof path !== 'string' ||
      !path.startsWith('/') ||
      path.startsWith('//') ||
      path.includes('\\')
    ) {
      return false;
    }
    const route = router.resolve(path);
    return (
      route.meta.requiresAuth &&
      route.name !== REDIRECT_ROUTE_NAME &&
      route.name !== 'redirectWrapper'
    );
  });
  if (typeof matched === 'string') {
    return { path: router.resolve(matched).fullPath, replace: true };
  }
  return { name: DEFAULT_ROUTE_NAME, replace: true };
}
