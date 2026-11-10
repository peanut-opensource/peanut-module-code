export const FILE_MEDIA_PACKAGE =
  '@peanut-admin/peanut-file/optional-runtime' as const;
export const FILE_MEDIA_VERSION = '1.0.0' as const;
export { createFileContentCallbacks } from './contentCallbacks';
export type { FileContentCallbackOptions } from './contentCallbacks';
export { default as FileAssetSelector } from './FileAssetSelector.vue';
export { default as FileMediaPage } from './FileMediaPage.vue';
export { parseAssetCandidate, parseAssetList } from './contracts';
export type { AssetCandidate, AssetList, ImageVariant } from './contracts';
export {
  createFileAssetRuntime,
  FILE_ASSET_MODULE_KEY,
  FILE_ASSET_READ_PERMISSION,
  FILE_ASSET_ROUTE_NAME,
  FILE_ASSET_ROUTE_PATH,
} from './runtime';
export type {
  FileAssetError,
  FileAssetRuntime,
  FileAssetRuntimeOptions,
  FileAssetState,
} from './runtime';
