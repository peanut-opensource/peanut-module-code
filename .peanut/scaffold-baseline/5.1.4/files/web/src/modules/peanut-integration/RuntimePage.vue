<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import useUser from '@/hooks/user';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot } from '@/utils/auth';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import IntegrationSecurityPage from './runtime/IntegrationSecurityPage.vue';
  import {
    createIntegrationSecurityFetchTransport,
    createIntegrationSecurityRuntime,
    integrationSecurityRuntimeKey,
  } from './runtime';

  const { logout } = useUser();
  const sessionGeneration = getSessionSnapshot().generation;
  const user = useUserStore();
  const allowed = (permission: string): boolean =>
    user.permissions.includes(permission);
  const runtime = createIntegrationSecurityRuntime({
    transport: createIntegrationSecurityFetchTransport({
      baseUrl: window.location.origin,
      fetch: tenantSessionFetch,
    }),
    permissions: {
      canReadMachines: () => allowed('peanut.integration.machine.read'),
      canManageMachines: () => allowed('peanut.integration.machine.manage'),
      canReadWebhooks: () => allowed('peanut.integration.webhook.read'),
      canManageWebhooks: () => allowed('peanut.integration.webhook.manage'),
      canReadDeliveries: () => allowed('peanut.integration.delivery.read'),
      canReadSessions: () => allowed('peanut.integration.session.read'),
      canRevokeSession: () => allowed('peanut.integration.session.revoke'),
    },
    onCurrentSessionRevoked: async () => {
      if (getSessionSnapshot().generation === sessionGeneration) await logout();
    },
  });

  provide(integrationSecurityRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <IntegrationSecurityPage />
</template>
