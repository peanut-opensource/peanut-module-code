import type {
  Router,
  LocationQueryRaw,
  RouteLocationNormalized,
} from 'vue-router';
import NProgress from 'nprogress'; // progress bar

import { useBrandStore, useUserStore } from '@/store';
import { getSessionSnapshot } from '@/utils/auth';
import { WHITE_LIST } from '../constants';
import { loginDestination } from '../login-destination';

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
  router.beforeEach(async (to, from) => {
    NProgress.start();
    const userStore = useUserStore();
    const session = getSessionSnapshot();
    const authenticatedLogin = to.name === 'login' && session.token;
    if (
      WHITE_LIST.some((route) => route.name === to.name) &&
      !authenticatedLogin
    ) {
      return true;
    }

    const brandStore = useBrandStore();
    await brandStore.load();
    if (!brandStore.loginEnabled) {
      return to.name === 'login'
        ? true
        : {
            name: 'login',
            query: loginQuery(to),
          };
    }

    if (session.token) {
      if (userStore.role) {
        return to.name === 'login'
          ? loginDestination(router, to.query.redirect, from.fullPath)
          : true;
      } else {
        try {
          await userStore.info();
          return to.name === 'login'
            ? loginDestination(router, to.query.redirect, from.fullPath)
            : true;
        } catch (error) {
          // Definitive authentication failure is cleared by the shared recovery.
          // A failed profile read alone (network/403/cancel) is not a logout.
          if (getSessionSnapshot().token === null) {
            return to.name === 'login'
              ? true
              : {
                  name: 'login',
                  query: loginQuery(to),
                };
          } else {
            NProgress.done();
            return false;
          }
        }
      }
    } else {
      return { name: 'login', query: loginQuery(to) };
    }
  });
}
