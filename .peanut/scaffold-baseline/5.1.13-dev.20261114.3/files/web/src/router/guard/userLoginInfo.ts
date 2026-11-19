import type {
  Router,
  LocationQueryRaw,
  RouteLocationNormalized,
} from 'vue-router';
import NProgress from 'nprogress'; // progress bar

import { useBrandStore, useUserStore } from '@/store';
import { getSessionSnapshot } from '@/utils/auth';
import { WHITE_LIST } from '../constants';

function loginQuery(to: RouteLocationNormalized): LocationQueryRaw {
  const query: LocationQueryRaw = { ...to.query };
  if (to.name && to.name !== 'login') {
    query.redirect = to.fullPath;
  } else {
    delete query.redirect;
  }
  return query;
}

export default function setupUserLoginInfoGuard(router: Router) {
  router.beforeEach(async (to, from, next) => {
    NProgress.start();
    const userStore = useUserStore();
    const session = getSessionSnapshot();
    if (WHITE_LIST.some((route) => route.name === to.name)) {
      next();
      return;
    }

    const brandStore = useBrandStore();
    await brandStore.load();
    if (!brandStore.loginEnabled) {
      next({
        name: 'login',
        query: loginQuery(to),
      });
      return;
    }

    if (session.token) {
      if (userStore.role) {
        next();
      } else {
        try {
          await userStore.info();
          next();
        } catch (error) {
          // Definitive authentication failure is cleared by the shared recovery.
          // A failed profile read alone (network/403/cancel) is not a logout.
          if (getSessionSnapshot().token === null) {
            next({
              name: 'login',
              query: loginQuery(to),
            });
          } else {
            NProgress.done();
            next(false);
          }
        }
      }
    } else {
      next({
        name: 'login',
        query: loginQuery(to),
      });
    }
  });
}
