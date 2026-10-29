import type {
  Router,
  LocationQueryRaw,
  RouteLocationNormalized,
} from 'vue-router';
import NProgress from 'nprogress'; // progress bar

import { useUserStore } from '@/store';
import { getSessionSnapshot } from '@/utils/auth';
import { WHITE_LIST } from '../constants';

function loginQuery(to: RouteLocationNormalized): LocationQueryRaw {
  const query: LocationQueryRaw = { ...to.query };
  if (to.name && to.name !== 'login') {
    query.redirect = String(to.name);
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

    if (session.token) {
      if (userStore.role) {
        next();
      } else {
        try {
          await userStore.info();
          next();
        } catch (error) {
          try {
            await userStore.logout(session);
          } catch {
            // A revoked token can make the remote logout request fail after local cleanup.
          }
          const afterLogout = getSessionSnapshot();
          if (
            afterLogout.generation === session.generation + 2 &&
            afterLogout.token === null
          ) {
            next({
              name: 'login',
              query: loginQuery(to),
            });
          } else {
            next();
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
