<template>
  <component v-if="component" :is="component" />
</template>

<script setup lang="ts">
  import { computed, resolveDynamicComponent } from 'vue';

  const props = defineProps<{ name: string }>();
  const slots: Record<string, string> = useAppConfig().moduleSlots;
  const component = computed(() => {
    const name = slots[props.name];
    return name ? resolveDynamicComponent(name) : null;
  });
</script>
