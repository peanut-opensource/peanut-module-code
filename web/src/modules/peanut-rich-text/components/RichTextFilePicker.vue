<template>
  <FileAssetSelector
    :items="runtime.state.items"
    :loading="runtime.state.loading"
    :error="runtime.state.error?.message"
    :disabled="!canRead()"
    @select="select"
    @retry="runtime.load()"
  />
</template>

<script setup lang="ts">
  import { onBeforeUnmount, shallowRef, watch } from 'vue';
  import {
    createFileAssetRuntime,
    FileAssetSelector,
    type AssetCandidate,
  } from '@peanut-admin/peanut-file/optional-runtime';
  import type { StableMediaReference } from '@peanut-admin/peanut-content-tools/surface';

  const props = defineProps<{
    contextKey: string;
    canRead: () => boolean;
    variantKey?: string;
  }>();
  const emit = defineEmits<{
    (event: 'select', reference: StableMediaReference): void;
    (event: 'error', code: string): void;
  }>();
  const runtime = shallowRef(
    createFileAssetRuntime({ canRead: props.canRead })
  );
  watch(
    () => [props.contextKey, props.canRead],
    () => {
      runtime.value.dispose();
      runtime.value = createFileAssetRuntime({ canRead: props.canRead });
      runtime.value
        .load()
        .catch(() => emit('error', 'RICH_TEXT_MEDIA_LOAD_FAILED'));
    },
    { immediate: true }
  );
  onBeforeUnmount(() => runtime.value.dispose());
  const select = (asset: AssetCandidate) => {
    if (!props.canRead()) return;
    if (
      props.variantKey &&
      !asset.variants.some((variant) => variant.variantKey === props.variantKey)
    ) {
      emit('error', 'RICH_TEXT_MEDIA_VARIANT_UNAVAILABLE');
      return;
    }
    emit('select', {
      fileKey: asset.fileKey,
      kind: 'image',
      ...(props.variantKey ? { variantKey: props.variantKey } : {}),
      title: asset.originalName,
    });
  };
</script>
