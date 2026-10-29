export const REFERENCE_CODES_PACKAGE =
  '@peanut-admin/peanut-reference-codes/runtime' as const;
export const REFERENCE_CODES_VERSION = '0.1.0' as const;

export {
  createReferenceCodesFetchTransport,
  normalizeReferenceCodeInstant,
  parseReferenceCode,
  parseReferenceCodeList,
  parseReferenceCodeMetadataText,
  parseReferenceCodeSets,
} from './contracts';
export type {
  EffectiveReferenceCodeVersion,
  ReferenceCodeCreateInput,
  ReferenceCodeCreateRequest,
  ReferenceCodeEffectiveStatusFilter,
  ReferenceCodeEntry,
  ReferenceCodeLifecycle,
  ReferenceCodeList,
  ReferenceCodeListQuery,
  ReferenceCodeMetadata,
  ReferenceCodeMetadataScalar,
  ReferenceCodeReplaceRequest,
  ReferenceCodeRetireRequest,
  ReferenceCodesFetchTransportOptions,
  ReferenceCodeSetSummary,
  ReferenceCodeStatus,
  ReferenceCodesTransport,
  ReferenceCodesTransportResult,
  ReferenceCodeVersionInput,
} from './contracts';
export {
  createReferenceCodesRuntime,
  REFERENCE_CODES_MANAGE_PERMISSION,
  REFERENCE_CODES_MODULE_KEY,
  REFERENCE_CODES_READ_PERMISSION,
  REFERENCE_CODES_ROUTE_NAME,
  REFERENCE_CODES_ROUTE_PATH,
  REFERENCE_CODES_STORE_KEY,
  referenceCodesRuntimeKey,
  useReferenceCodesRuntime,
} from './runtime';
export type {
  ReferenceCodeAppendDraft,
  ReferenceCodeCreateDraft,
  ReferenceCodeDraftFields,
  ReferenceCodeRequestError,
  ReferenceCodesRuntime,
  ReferenceCodesRuntimeOptions,
  ReferenceCodesRuntimeState,
  ReferenceCodeStaleState,
} from './runtime';
