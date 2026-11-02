<template>
  <component v-for="entry in visible" :is="entry.component" :key="entry.key" />
</template>

<script setup lang="ts">
  import { computed, defineAsyncComponent } from 'vue';
  import type { AdminModuleShellSlotName } from '@peanut-admin/vue';
  import contributions from 'virtual:peanut-plugin-contributions';
  import { useAppStore, useUserStore } from '@/store';
  import { hasPermission } from '@/hooks/permission';

  const props = defineProps<{ name: AdminModuleShellSlotName }>();
  const appStore = useAppStore();
  const userStore = useUserStore();
  const entries = contributions.flatMap((module) =>
    (module.shellSlots ?? []).map((slot) => ({
      key: `${module.moduleKey}:${slot.name}`,
      moduleKey: module.moduleKey,
      slot,
      component: defineAsyncComponent(slot.component),
    }))
  );
  const visible = computed(() =>
    entries.filter(
      ({ moduleKey, slot }) =>
        slot.name === props.name &&
        appStore.enabledTenantModules.includes(moduleKey) &&
        (slot.access === undefined ||
          (slot.access.moduleKey === moduleKey &&
            hasPermission(
              [...slot.access.permissionKeys],
              undefined,
              userStore.permissions
            )))
    )
  );
</script>
