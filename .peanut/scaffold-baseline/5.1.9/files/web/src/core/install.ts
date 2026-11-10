import { ref } from 'vue';
import {
  getInstallationEntryStatus,
  type InstallationDeploymentMode,
  type InstallationEntryStatus,
  type InstallationStatus,
} from '@/api/install';

export const installationStatus = ref<InstallationStatus | null>(null);

const entryStatus = ref<InstallationEntryStatus | null>(null);
let pendingStatus: Promise<InstallationEntryStatus> | null = null;
let pendingDiagnostics: Promise<InstallationStatus> | null = null;

function configuredDeploymentMode(): InstallationDeploymentMode {
  return import.meta.env.VITE_DEPLOYMENT_MODE === 'multi-tenant'
    ? 'multi-tenant'
    : 'standalone';
}

export function bootstrapInstallationStatus(
  force = false
): Promise<InstallationEntryStatus> {
  if (force) entryStatus.value = null;
  if (!force && entryStatus.value) {
    return Promise.resolve(entryStatus.value);
  }
  if (pendingStatus) {
    return pendingStatus;
  }
  pendingStatus = getInstallationEntryStatus()
    .then((status) => {
      entryStatus.value = status;
      return status;
    })
    .catch(() => {
      entryStatus.value = null;
      installationStatus.value = null;
      const status = {
        installed: null,
        state: 'unknown',
        deployment_mode: configuredDeploymentMode(),
      } satisfies InstallationEntryStatus;
      return status;
    })
    .finally(() => {
      pendingStatus = null;
    });
  return pendingStatus;
}

export const loadInstallationStatus = bootstrapInstallationStatus;

export function loadInstallationDiagnostics(
  force = false
): Promise<InstallationStatus> {
  if (!force && installationStatus.value) {
    return Promise.resolve(installationStatus.value);
  }
  if (!pendingDiagnostics) {
    if (force) installationStatus.value = null;
    pendingDiagnostics = import('@/api/install')
      .then(({ getInstallationStatus }) => getInstallationStatus())
      .then((status) => {
        installationStatus.value = status;
        return status;
      })
      .catch((error) => {
        installationStatus.value = null;
        throw error;
      })
      .finally(() => {
        pendingDiagnostics = null;
      });
  }
  return pendingDiagnostics;
}

export function shouldShowInstallation(status: InstallationEntryStatus | null) {
  return status?.installed === false;
}

export function markInstallationInstalled() {
  entryStatus.value = {
    installed: true,
    deployment_mode: entryStatus.value?.deployment_mode || configuredDeploymentMode(),
  };
  if (installationStatus.value) {
    installationStatus.value = { ...installationStatus.value, state: 'installed' };
  }
}
