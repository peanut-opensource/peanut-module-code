<template>
  <router-view v-slot="{ Component, route }">
    <keep-alive
      :include="cacheNamesFor(getTabKey(route), route.meta.ignoreCache === true)"
    >
      <component
        :is="tabWrapper(getTabKey(route))"
        :component="Component"
        v-if="!route.meta.ignoreCache"
        :key="getTabKey(route)"
      />
    </keep-alive>
    <component
      :is="Component"
      v-if="route.meta.ignoreCache"
      :key="getTabKey(route)"
    />
  </router-view>
</template>

<script lang="ts" setup>
  import {
    cloneVNode,
    defineComponent,
    type Component,
    type PropType,
    type VNode,
  } from 'vue';
  import { useTabBarStore } from '@/store';
  import { getTabKey } from '@peanut-admin/ui-vue/tabs';

  const tabBarStore = useTabBarStore();
  const wrappersByPath = new Map<string, Component>();

  const wrapperName = (fullPath: string) => `PageTab_${encodeURIComponent(fullPath)}`;

  const cacheNamesFor = (fullPath: string, ignoreCache: boolean) => {
    const cachedPaths = new Set(tabBarStore.getCacheList);
    if (!ignoreCache) cachedPaths.add(fullPath);

    wrappersByPath.forEach((_wrapper, path) => {
      if (!cachedPaths.has(path)) wrappersByPath.delete(path);
    });

    return [...cachedPaths].map(wrapperName);
  };

  const tabWrapper = (fullPath: string): Component => {
    const existing = wrappersByPath.get(fullPath);
    if (existing) return existing;

    const wrapper = defineComponent({
      name: wrapperName(fullPath),
      props: {
        component: {
          type: Object as PropType<VNode>,
          required: true,
        },
      },
      setup(props) {
        return () => cloneVNode(props.component);
      },
    });
    wrappersByPath.set(fullPath, wrapper);
    return wrapper;
  };
</script>

<style scoped lang="less"></style>
