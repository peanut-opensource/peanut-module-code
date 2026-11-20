<template>
  <div ref="anchor" :style="{ minHeight: reservedHeight }">
    <Teleport to="body" :disabled="!active">
      <div
        ref="region"
        class="focus-region"
        :class="{ 'focus-region-active': active }"
        @keydown.esc="handleEscape"
      >
        <div v-if="active" class="focus-region-toolbar">
          <button
            ref="exitButton"
            type="button"
            class="focus-region-exit"
            @click="exit"
          >
            {{ exitLabel }}
          </button>
        </div>
        <div class="focus-region-content">
          <slot :active="active" :enter="enter" :exit="exit" />
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
  import { nextTick, onBeforeUnmount, onDeactivated, ref } from 'vue';

  withDefaults(defineProps<{ exitLabel?: string }>(), {
    exitLabel: '退出专注模式',
  });

  const active = ref(false);
  const anchor = ref<HTMLElement>();
  const region = ref<HTMLElement>();
  const exitButton = ref<HTMLButtonElement>();
  const reservedHeight = ref<string>();
  let opener: HTMLElement | null = null;
  let inertShell: HTMLElement | undefined;

  function releaseShell() {
    inertShell?.removeAttribute('inert');
    inertShell = undefined;
  }

  async function enter(event?: Event) {
    if (active.value || !anchor.value) return;

    const previousFocus = document.activeElement;
    const trigger = event?.currentTarget ?? previousFocus;
    const takeFocus =
      previousFocus === document.body ||
      previousFocus === event?.currentTarget ||
      anchor.value.contains(previousFocus);
    opener = trigger instanceof HTMLElement ? trigger : null;
    // Keep the page at its current scroll position while the content moves to the viewport.
    reservedHeight.value = `${anchor.value.getBoundingClientRect().height}px`;
    active.value = true;
    await nextTick();
    if (!active.value || !region.value?.isConnected) return;

    // The region and body-teleported overlays stay interactive outside the app.
    const shell = anchor.value?.closest<HTMLElement>('#app');
    if (
      shell &&
      !shell.contains(region.value) &&
      !shell.hasAttribute('inert')
    ) {
      shell.setAttribute('inert', '');
      inertShell = shell;
    }
    if (
      takeFocus &&
      (document.activeElement === previousFocus ||
        document.activeElement === document.body)
    ) {
      exitButton.value?.focus({ preventScroll: true });
    }
  }

  async function exit() {
    if (!active.value) return;

    const previousFocus = document.activeElement;
    const restoreFocus =
      previousFocus === document.body || region.value?.contains(previousFocus);
    const target = opener;
    active.value = false;
    reservedHeight.value = undefined;
    opener = null;
    releaseShell();

    await nextTick();
    if (
      restoreFocus &&
      !active.value &&
      target?.isConnected &&
      (document.activeElement === document.body ||
        document.activeElement === previousFocus)
    ) {
      target.focus({ preventScroll: true });
    }
  }

  async function handleEscape(event: KeyboardEvent) {
    if (!active.value || event.defaultPrevented || event.isComposing) return;

    event.preventDefault();
    event.stopPropagation();
    await exit();
  }

  onDeactivated(() => {
    active.value = false;
    reservedHeight.value = undefined;
    opener = null;
    releaseShell();
  });
  onBeforeUnmount(releaseShell);
</script>

<style scoped>
  /* Element Plus overlays start at 2000. */
  .focus-region-active {
    position: fixed;
    inset: 0;
    z-index: calc(var(--el-index-popper, 2000) - 1);
    display: flex;
    flex-direction: column;
    background: var(--el-bg-color-page, Canvas);
  }

  .focus-region-toolbar {
    display: flex;
    flex: none;
    justify-content: flex-end;
    padding: 8px 16px;
    background: var(--el-bg-color, Canvas);
    border-bottom: 1px solid var(--el-border-color, ButtonBorder);
  }

  .focus-region-exit {
    padding: 6px 12px;
    color: var(--el-text-color-primary, ButtonText);
    font: inherit;
    background: var(--el-bg-color, ButtonFace);
    border: 1px solid var(--el-border-color, ButtonBorder);
    border-radius: var(--el-border-radius-base, 4px);
    cursor: pointer;
  }

  .focus-region-exit:hover {
    color: var(--el-color-primary, LinkText);
    border-color: currentcolor;
  }

  .focus-region-active > .focus-region-content {
    flex: 1;
    min-height: 0;
    overflow: auto;
    overscroll-behavior: contain;
  }
</style>
