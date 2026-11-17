import type { Router } from 'vue-router';
import {
  bootstrapInstallationStatus,
  shouldShowInstallation,
} from '@/core/install';
import { INSTALLATION_ROUTE_NAME } from '../constants';

export default function setupInstallationGuard(router: Router) {
  router.beforeEach(async (to) => {
    const status = await bootstrapInstallationStatus();
    const isInstallationRoute = to.name === INSTALLATION_ROUTE_NAME;

    // An unavailable status is not permission to install or a cached fresh DB.
    // Keep the diagnostic page reachable so the user can explicitly retry.
    if (status.installed === null) {
      return true;
    }

    if (shouldShowInstallation(status)) {
      return isInstallationRoute ? true : { name: INSTALLATION_ROUTE_NAME };
    }

    // Automatic deployments and already-installed applications are handled
    // by their deployment/login entry point; the Web form must not be shown.
    if (isInstallationRoute) {
      return { name: 'login' };
    }
    return true;
  });
}
