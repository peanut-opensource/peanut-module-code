import { inject, reactive } from 'vue';
import type { InjectionKey } from 'vue';

import {
  normalizeReferenceCodeInstant,
  parseReferenceCode,
  parseReferenceCodeList,
  parseReferenceCodeMetadataText,
  parseReferenceCodeSets,
} from './contracts';
import type {
  ReferenceCodeCreateInput,
  ReferenceCodeEntry,
  ReferenceCodeListQuery,
  ReferenceCodeMetadata,
  ReferenceCodeSetSummary,
  ReferenceCodesTransport,
  ReferenceCodesTransportResult,
  ReferenceCodeStatus,
  ReferenceCodeVersionInput,
} from './contracts';

export const REFERENCE_CODES_MODULE_KEY = 'peanut.reference-codes' as const;
export const REFERENCE_CODES_ROUTE_NAME =
  'peanut.reference-codes.list' as const;
export const REFERENCE_CODES_ROUTE_PATH = '/system/reference-codes' as const;
export const REFERENCE_CODES_READ_PERMISSION =
  'peanut.reference-codes.read' as const;
export const REFERENCE_CODES_MANAGE_PERMISSION =
  'peanut.reference-codes.manage' as const;
export const REFERENCE_CODES_STORE_KEY =
  'peanut.reference-codes.runtime' as const;

export interface ReferenceCodeRequestError {
  readonly kind: 'http' | 'protocol' | 'transport' | 'validation';
  readonly message: string;
  readonly requestId: string | null;
  readonly status: number | null;
}

export interface ReferenceCodeStaleState {
  readonly message: string;
  readonly requestId: string | null;
}

export interface ReferenceCodeDraftFields {
  label: string;
  metadataText: string;
  status: ReferenceCodeStatus;
  sortOrder: number;
  effectiveAt: string;
  expiresAt: string | null;
}

export interface ReferenceCodeCreateDraft extends ReferenceCodeDraftFields {
  code: string;
}

export interface ReferenceCodeAppendDraft extends ReferenceCodeDraftFields {
  readonly code: string;
  etag: string;
}

export interface ReferenceCodesRuntimeState {
  sets: ReferenceCodeSetSummary[];
  selectedSet: ReferenceCodeSetSummary | null;
  asOf: string;
  effectiveStatus: ReferenceCodeListQuery['effectiveStatus'];
  includeRetired: boolean;
  entries: ReferenceCodeEntry[];
  page: number;
  pageSize: number;
  total: number;
  createDraft: ReferenceCodeCreateDraft | null;
  appendDraft: ReferenceCodeAppendDraft | null;
  retireCode: string | null;
  errors: Record<string, ReferenceCodeRequestError>;
  stale: Record<string, ReferenceCodeStaleState>;
  pendingResources: Set<string>;
  requests: Set<string>;
  loading: boolean;
}

export interface ReferenceCodesRuntime {
  readonly state: ReferenceCodesRuntimeState;
  canManage: () => boolean;
  loadSets: () => Promise<void>;
  selectSet: (moduleKey: string, setKey: string) => Promise<void>;
  setAsOf: (value: string) => Promise<void>;
  setFilters: (
    effectiveStatus: ReferenceCodeListQuery['effectiveStatus'],
    includeRetired: boolean
  ) => Promise<void>;
  setPage: (page: number) => Promise<void>;
  loadEntries: () => Promise<void>;
  beginCreate: () => void;
  updateCreateDraft: (changes: Partial<ReferenceCodeCreateDraft>) => void;
  cancelCreate: () => void;
  create: () => Promise<void>;
  beginAppend: (entry: ReferenceCodeEntry) => void;
  updateAppendDraft: (changes: Partial<ReferenceCodeDraftFields>) => void;
  cancelAppend: () => void;
  appendVersion: () => Promise<void>;
  reloadStale: (code: string) => Promise<void>;
  beginRetire: (entry: ReferenceCodeEntry) => void;
  cancelRetire: () => void;
  retire: () => Promise<void>;
  isPending: (code: string) => boolean;
  dispose: () => void;
}

export interface ReferenceCodesRuntimeOptions {
  readonly transport: ReferenceCodesTransport;
  readonly canRead: () => boolean;
  readonly canManage: () => boolean;
  readonly createIdempotencyKey?: () => string;
  readonly now?: () => string;
}

interface ActiveRequest {
  readonly controller: AbortController;
  readonly generation: number;
  readonly id: string;
}

const idempotencyKey = (): string => {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return `idem_${globalThis.crypto.randomUUID().replace(/-/g, '')}`;
  }
  return `idem_${Date.now().toString(36)}_${Math.random()
    .toString(36)
    .slice(2)}`;
};

const isAbortError = (error: unknown): boolean =>
  typeof error === 'object' &&
  error !== null &&
  'name' in error &&
  error.name === 'AbortError';

const requestId = (result: ReferenceCodesTransportResult): string | null => {
  if (
    typeof result.body === 'object' &&
    result.body !== null &&
    !Array.isArray(result.body)
  ) {
    const value = (result.body as Record<string, unknown>).request_id;
    if (typeof value === 'string' && value !== '') return value;
  }
  const value = result.headers.get('X-Request-Id');
  return value === null || value === '' ? null : value;
};

const httpError = (
  result: ReferenceCodesTransportResult
): ReferenceCodeRequestError => {
  const body =
    typeof result.body === 'object' &&
    result.body !== null &&
    !Array.isArray(result.body)
      ? (result.body as Record<string, unknown>)
      : {};
  return {
    kind: 'http',
    message:
      typeof body.detail === 'string' && body.detail !== ''
        ? body.detail
        : `Reference-code request failed (${result.status}).`,
    requestId: requestId(result),
    status: result.status,
  };
};

const protocolError = (
  result: ReferenceCodesTransportResult
): ReferenceCodeRequestError => ({
  kind: 'protocol',
  message: 'The reference-code response could not be validated.',
  requestId: requestId(result),
  status: result.status,
});

const statusError = (
  result: ReferenceCodesTransportResult
): ReferenceCodeRequestError =>
  result.status >= 200 && result.status < 300
    ? protocolError(result)
    : httpError(result);

const transportError = (): ReferenceCodeRequestError => ({
  kind: 'transport',
  message: 'The reference-code service could not be reached.',
  requestId: null,
  status: null,
});

const validationError = (message: string): ReferenceCodeRequestError => ({
  kind: 'validation',
  message,
  requestId: null,
  status: null,
});

const metadataText = (value: ReferenceCodeMetadata): string =>
  JSON.stringify(value);

const emptyDraft = (now: string): ReferenceCodeCreateDraft => ({
  code: '',
  label: '',
  metadataText: '{}',
  status: 'active',
  sortOrder: 0,
  effectiveAt: now,
  expiresAt: null,
});

const appendDraft = (entry: ReferenceCodeEntry): ReferenceCodeAppendDraft => {
  if (entry.lifecycle === 'retired') throw new Error('REFERENCE_CODE_RETIRED');
  if (entry.effective === null)
    throw new Error('REFERENCE_CODE_EFFECTIVE_VERSION_MISSING');
  return {
    code: entry.code,
    etag: entry.etag,
    label: entry.effective.label,
    metadataText: metadataText(entry.effective.metadata),
    status: entry.effective.status,
    sortOrder: entry.effective.sortOrder,
    effectiveAt: entry.effective.effectiveAt,
    expiresAt: entry.effective.expiresAt,
  };
};

const validateDraft = (
  draft: ReferenceCodeDraftFields
): ReferenceCodeVersionInput => {
  const label = draft.label.trim();
  if (label === '' || [...label].length > 160)
    throw new Error('Enter a label from 1 to 160 characters.');
  if (draft.status !== 'active' && draft.status !== 'inactive')
    throw new Error('Select a valid status.');
  if (
    !Number.isInteger(draft.sortOrder) ||
    draft.sortOrder < -1_000_000 ||
    draft.sortOrder > 1_000_000
  ) {
    throw new Error('Sort order must be an integer from -1000000 to 1000000.');
  }
  const effectiveAt = normalizeReferenceCodeInstant(draft.effectiveAt);
  const expiresAt =
    draft.expiresAt === null || draft.expiresAt === ''
      ? null
      : normalizeReferenceCodeInstant(draft.expiresAt);
  if (expiresAt !== null && Date.parse(expiresAt) <= Date.parse(effectiveAt)) {
    throw new Error('Expiration must be later than the effective instant.');
  }
  return {
    label,
    metadata: parseReferenceCodeMetadataText(draft.metadataText),
    status: draft.status,
    sortOrder: draft.sortOrder,
    effectiveAt,
    expiresAt,
  };
};

const validateCreateDraft = (
  draft: ReferenceCodeCreateDraft
): ReferenceCodeCreateInput => {
  if (
    !/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test(draft.code) ||
    draft.code.length > 64
  ) {
    throw new Error(
      'Code must use lower-case slug syntax and be at most 64 characters.'
    );
  }
  return { code: draft.code, ...validateDraft(draft) };
};

const versionFingerprint = (input: ReferenceCodeVersionInput): string =>
  JSON.stringify({
    label: input.label,
    metadata: Object.fromEntries(
      Object.entries(input.metadata).sort(([left], [right]) => {
        if (left < right) return -1;
        if (left > right) return 1;
        return 0;
      })
    ),
    status: input.status,
    sort_order: input.sortOrder,
    effective_at: input.effectiveAt,
    expires_at: input.expiresAt,
  });

const createFingerprint = (input: ReferenceCodeCreateInput): string =>
  JSON.stringify({
    code: input.code,
    version: versionFingerprint(input),
  });

const replaceFingerprint = (
  input: ReferenceCodeVersionInput,
  etag: string
): string =>
  JSON.stringify({
    etag,
    version: versionFingerprint(input),
  });

export const createReferenceCodesRuntime = (
  options: ReferenceCodesRuntimeOptions
): ReferenceCodesRuntime => {
  const state = reactive<ReferenceCodesRuntimeState>({
    sets: [],
    selectedSet: null,
    asOf: '',
    effectiveStatus: 'all',
    includeRetired: false,
    entries: [],
    page: 1,
    pageSize: 50,
    total: 0,
    createDraft: null,
    appendDraft: null,
    retireCode: null,
    errors: {},
    stale: {},
    pendingResources: new Set<string>(),
    requests: new Set<string>(),
    loading: false,
  });
  const controllers = new Map<string, AbortController>();
  const resourceRequests = new Map<string, ActiveRequest>();
  const createKey = options.createIdempotencyKey ?? idempotencyKey;
  const mutationKeys = new Map<string, { fingerprint: string; key: string }>();
  const now = options.now ?? (() => new Date().toISOString());
  let collectionRequest: ActiveRequest | null = null;
  let generation = 0;
  let sequence = 0;

  const beginRequest = (kind: string): ActiveRequest => {
    sequence += 1;
    const id = `${kind}:${sequence}`;
    const controller = new AbortController();
    controllers.set(id, controller);
    state.requests.add(id);
    return { controller, generation, id };
  };

  const finishRequest = (request: ActiveRequest): void => {
    controllers.delete(request.id);
    state.requests.delete(request.id);
  };

  const cancelCollection = (): void => {
    if (collectionRequest === null) return;
    const request = collectionRequest;
    collectionRequest = null;
    request.controller.abort();
    finishRequest(request);
    state.loading = false;
  };

  const beginCollection = (kind: string): ActiveRequest => {
    if (resourceRequests.size > 0)
      throw new Error('REFERENCE_CODES_MUTATION_PENDING');
    cancelCollection();
    const request = beginRequest(kind);
    collectionRequest = request;
    state.loading = true;
    return request;
  };

  const currentCollection = (request: ActiveRequest): boolean =>
    request.generation === generation &&
    collectionRequest?.id === request.id &&
    !request.controller.signal.aborted;

  const finishCollection = (request: ActiveRequest): void => {
    if (collectionRequest?.id === request.id) {
      collectionRequest = null;
      state.loading = false;
    }
    finishRequest(request);
  };

  const beginResource = (kind: string, key: string): ActiveRequest => {
    if (resourceRequests.has(key))
      throw new Error('REFERENCE_CODE_REQUEST_PENDING');
    cancelCollection();
    const request = beginRequest(`${kind}:${key}`);
    resourceRequests.set(key, request);
    state.pendingResources.add(key);
    return request;
  };

  const currentResource = (key: string, request: ActiveRequest): boolean =>
    request.generation === generation &&
    resourceRequests.get(key)?.id === request.id &&
    !request.controller.signal.aborted;

  const finishResource = (key: string, request: ActiveRequest): void => {
    if (resourceRequests.get(key)?.id === request.id) {
      resourceRequests.delete(key);
      state.pendingResources.delete(key);
    }
    finishRequest(request);
  };

  const selectedSet = (): ReferenceCodeSetSummary => {
    if (state.selectedSet === null)
      throw new Error('REFERENCE_CODE_SET_NOT_SELECTED');
    return state.selectedSet;
  };

  const currentEntry = (code: string): ReferenceCodeEntry => {
    const entry = state.entries.find((candidate) => candidate.code === code);
    if (entry === undefined) throw new Error('REFERENCE_CODE_NOT_FOUND');
    return entry;
  };

  const assertMutationEntry = (
    entry: ReferenceCodeEntry,
    code: string
  ): void => {
    const set = selectedSet();
    if (
      entry.moduleKey !== set.moduleKey ||
      entry.setKey !== set.setKey ||
      entry.code !== code
    ) {
      throw new Error('REFERENCE_CODES_RESPONSE_SCOPE_MISMATCH');
    }
  };

  const mutationKey = (scope: string, fingerprint: string): string => {
    const current = mutationKeys.get(scope);
    if (current !== undefined && current.fingerprint === fingerprint)
      return current.key;
    const key = createKey();
    mutationKeys.set(scope, { fingerprint, key });
    return key;
  };

  const clearMutationKey = (scope: string): void => {
    mutationKeys.delete(scope);
  };

  const applyPageError = (error: ReferenceCodeRequestError): void => {
    state.entries = [];
    state.total = 0;
    state.errors = { page: error };
  };

  const setResourceError = (
    key: string,
    error: ReferenceCodeRequestError
  ): void => {
    state.errors[key] = error;
    delete state.stale[key];
  };

  const parsedMutation = (
    result: ReferenceCodesTransportResult
  ): ReferenceCodeEntry => {
    const etag = result.headers.get('ETag');
    if (etag === null) throw new Error('REFERENCE_CODES_RESPONSE_ETAG_INVALID');
    return parseReferenceCode(result.body, etag);
  };

  const loadSets = async (): Promise<void> => {
    if (!options.canRead()) throw new Error('REFERENCE_CODES_READ_FORBIDDEN');
    const request = beginCollection('sets');
    try {
      let result: ReferenceCodesTransportResult;
      try {
        result = await options.transport.listSets(request.controller.signal);
      } catch (error) {
        if (!currentCollection(request) || isAbortError(error)) return;
        state.sets = [];
        applyPageError(transportError());
        return;
      }
      if (!currentCollection(request)) return;
      if (result.status !== 200) {
        state.sets = [];
        applyPageError(statusError(result));
        return;
      }
      try {
        state.sets = parseReferenceCodeSets(result.body);
        state.asOf = normalizeReferenceCodeInstant(now());
        state.errors = {};
      } catch {
        state.sets = [];
        applyPageError(protocolError(result));
      }
    } finally {
      finishCollection(request);
    }
  };

  const loadEntries = async (): Promise<void> => {
    if (!options.canRead()) throw new Error('REFERENCE_CODES_READ_FORBIDDEN');
    const set = selectedSet();
    const staleCreateCode = state.createDraft?.code;
    const reloadsStaleCreate =
      staleCreateCode !== undefined &&
      state.stale[staleCreateCode] !== undefined;
    if (state.asOf === '') state.asOf = normalizeReferenceCodeInstant(now());
    const request = beginCollection('entries');
    try {
      let result: ReferenceCodesTransportResult;
      try {
        result = await options.transport.listCodes(
          set.moduleKey,
          set.setKey,
          {
            asOf: state.asOf,
            effectiveStatus: state.effectiveStatus,
            includeRetired: state.includeRetired,
            page: state.page,
            pageSize: state.pageSize,
          },
          request.controller.signal
        );
      } catch (error) {
        if (!currentCollection(request) || isAbortError(error)) return;
        applyPageError(transportError());
        return;
      }
      if (!currentCollection(request)) return;
      if (result.status !== 200) {
        applyPageError(statusError(result));
        return;
      }
      try {
        const list = parseReferenceCodeList(result.body);
        if (
          list.asOf !== state.asOf ||
          list.page !== state.page ||
          list.pageSize !== state.pageSize
        ) {
          throw new Error('REFERENCE_CODES_RESPONSE_QUERY_MISMATCH');
        }
        if (
          list.items.some(
            (entry) =>
              entry.moduleKey !== set.moduleKey || entry.setKey !== set.setKey
          )
        ) {
          throw new Error('REFERENCE_CODES_RESPONSE_SCOPE_MISMATCH');
        }
        state.entries = [...list.items];
        state.total = list.total;
        state.errors = {};
        state.stale = {};
        if (reloadsStaleCreate) clearMutationKey('create');
      } catch {
        applyPageError(protocolError(result));
      }
    } finally {
      finishCollection(request);
    }
  };

  const createEntry = async (): Promise<void> => {
    const draft = state.createDraft;
    if (draft === null) throw new Error('REFERENCE_CODE_CREATE_DRAFT_MISSING');
    if (!options.canManage()) {
      setResourceError(
        'create',
        validationError('You do not have permission to manage reference codes.')
      );
      return;
    }
    let input: ReferenceCodeCreateInput;
    try {
      input = validateCreateDraft(draft);
    } catch (error) {
      setResourceError(
        'create',
        validationError(
          error instanceof Error ? error.message : 'The create form is invalid.'
        )
      );
      return;
    }
    const set = selectedSet();
    const scope = 'create';
    const fingerprint = createFingerprint(input);
    const request = beginResource('create', input.code);
    let reload = false;
    delete state.errors.create;
    try {
      let result: ReferenceCodesTransportResult;
      try {
        result = await options.transport.create(set.moduleKey, set.setKey, {
          input,
          idempotencyKey: mutationKey(scope, fingerprint),
          signal: request.controller.signal,
        });
      } catch (error) {
        if (!currentResource(input.code, request) || isAbortError(error))
          return;
        setResourceError('create', transportError());
        return;
      }
      if (!currentResource(input.code, request)) return;
      if (result.status === 412) {
        const conflict = httpError(result);
        state.stale[input.code] = {
          message: conflict.message,
          requestId: conflict.requestId,
        };
        return;
      }
      if (result.status !== 201) {
        setResourceError('create', statusError(result));
        return;
      }
      try {
        const parsed = parsedMutation(result);
        assertMutationEntry(parsed, input.code);
        if (Date.parse(state.asOf) < Date.parse(parsed.createdAt))
          state.asOf = parsed.createdAt;
        state.createDraft = null;
        delete state.stale[input.code];
        clearMutationKey(scope);
        reload = true;
      } catch {
        setResourceError('create', protocolError(result));
      }
    } finally {
      finishResource(input.code, request);
    }
    if (reload) await loadEntries();
  };

  const replaceEntry = async (): Promise<void> => {
    const draft = state.appendDraft;
    if (draft === null) throw new Error('REFERENCE_CODE_APPEND_DRAFT_MISSING');
    if (!options.canManage()) {
      setResourceError(
        draft.code,
        validationError('You do not have permission to manage reference codes.')
      );
      return;
    }
    let input: ReferenceCodeVersionInput;
    try {
      input = validateDraft(draft);
    } catch (error) {
      setResourceError(
        draft.code,
        validationError(
          error instanceof Error
            ? error.message
            : 'The version form is invalid.'
        )
      );
      return;
    }
    const set = selectedSet();
    const scope = `replace:${draft.code}`;
    const fingerprint = replaceFingerprint(input, draft.etag);
    const request = beginResource('replace', draft.code);
    let reload = false;
    delete state.errors[draft.code];
    delete state.stale[draft.code];
    try {
      let result: ReferenceCodesTransportResult;
      try {
        result = await options.transport.replace(
          set.moduleKey,
          set.setKey,
          draft.code,
          {
            input,
            etag: draft.etag,
            idempotencyKey: mutationKey(scope, fingerprint),
            signal: request.controller.signal,
          }
        );
      } catch (error) {
        if (!currentResource(draft.code, request) || isAbortError(error))
          return;
        setResourceError(draft.code, transportError());
        return;
      }
      if (!currentResource(draft.code, request)) return;
      if (result.status === 412) {
        const conflict = httpError(result);
        state.stale[draft.code] = {
          message: conflict.message,
          requestId: conflict.requestId,
        };
        return;
      }
      if (result.status !== 200) {
        setResourceError(draft.code, statusError(result));
        return;
      }
      try {
        assertMutationEntry(parsedMutation(result), draft.code);
        state.appendDraft = null;
        clearMutationKey(scope);
        reload = true;
      } catch {
        setResourceError(draft.code, protocolError(result));
      }
    } finally {
      finishResource(draft.code, request);
    }
    if (reload) await loadEntries();
  };

  const reloadStale = async (code: string): Promise<void> => {
    currentEntry(code);
    const set = selectedSet();
    const request = beginResource('reload', code);
    let reload = false;
    try {
      let result: ReferenceCodesTransportResult;
      try {
        result = await options.transport.getCode(
          set.moduleKey,
          set.setKey,
          code,
          state.asOf,
          request.controller.signal
        );
      } catch (error) {
        if (!currentResource(code, request) || isAbortError(error)) return;
        setResourceError(code, transportError());
        return;
      }
      if (!currentResource(code, request)) return;
      if (result.status !== 200) {
        setResourceError(code, statusError(result));
        return;
      }
      try {
        const parsed = parsedMutation(result);
        assertMutationEntry(parsed, code);
        if (state.appendDraft?.code === code)
          state.appendDraft.etag = parsed.etag;
        delete state.stale[code];
        delete state.errors[code];
        clearMutationKey(`replace:${code}`);
        clearMutationKey(`retire:${code}`);
        reload = true;
      } catch {
        setResourceError(code, protocolError(result));
      }
    } finally {
      finishResource(code, request);
    }
    if (reload) await loadEntries();
  };

  const retireEntry = async (): Promise<void> => {
    const code = state.retireCode;
    if (code === null) throw new Error('REFERENCE_CODE_RETIRE_TARGET_MISSING');
    const entry = currentEntry(code);
    if (entry.lifecycle === 'retired') {
      setResourceError(
        code,
        validationError('This reference-code identity is already retired.')
      );
      return;
    }
    if (!options.canManage()) {
      setResourceError(
        code,
        validationError('You do not have permission to manage reference codes.')
      );
      return;
    }
    const set = selectedSet();
    const scope = `retire:${code}`;
    const fingerprint = entry.etag;
    const request = beginResource('retire', code);
    let reload = false;
    delete state.errors[code];
    delete state.stale[code];
    try {
      let result: ReferenceCodesTransportResult;
      try {
        result = await options.transport.retire(
          set.moduleKey,
          set.setKey,
          code,
          {
            etag: entry.etag,
            idempotencyKey: mutationKey(scope, fingerprint),
            signal: request.controller.signal,
          }
        );
      } catch (error) {
        if (!currentResource(code, request) || isAbortError(error)) return;
        setResourceError(code, transportError());
        return;
      }
      if (!currentResource(code, request)) return;
      if (result.status === 412) {
        const conflict = httpError(result);
        state.stale[code] = {
          message: conflict.message,
          requestId: conflict.requestId,
        };
        return;
      }
      if (result.status !== 200) {
        setResourceError(code, statusError(result));
        return;
      }
      try {
        assertMutationEntry(parsedMutation(result), code);
        state.retireCode = null;
        clearMutationKey(scope);
        reload = true;
      } catch {
        setResourceError(code, protocolError(result));
      }
    } finally {
      finishResource(code, request);
    }
    if (reload) await loadEntries();
  };

  const runtime: ReferenceCodesRuntime = {
    state,
    canManage: options.canManage,
    loadSets,
    async selectSet(moduleKey, setKey) {
      const selected = state.sets.find(
        (set) => set.moduleKey === moduleKey && set.setKey === setKey
      );
      if (selected === undefined) throw new Error('REFERENCE_CODE_SET_UNKNOWN');
      state.selectedSet = selected;
      state.page = 1;
      state.entries = [];
      state.total = 0;
      state.createDraft = null;
      state.appendDraft = null;
      state.retireCode = null;
      state.errors = {};
      state.stale = {};
      mutationKeys.clear();
      await loadEntries();
    },
    async setAsOf(value) {
      state.asOf = normalizeReferenceCodeInstant(value);
      state.page = 1;
      if (state.selectedSet !== null) await loadEntries();
    },
    async setFilters(effectiveStatus, includeRetired) {
      state.effectiveStatus = effectiveStatus;
      state.includeRetired = includeRetired;
      state.page = 1;
      if (state.selectedSet !== null) await loadEntries();
    },
    async setPage(page) {
      if (!Number.isInteger(page) || page < 1 || page > 10_000)
        throw new Error('REFERENCE_CODES_PAGE_INVALID');
      state.page = page;
      await loadEntries();
    },
    loadEntries,
    beginCreate() {
      selectedSet();
      if (state.appendDraft !== null)
        clearMutationKey(`replace:${state.appendDraft.code}`);
      if (state.retireCode !== null)
        clearMutationKey(`retire:${state.retireCode}`);
      clearMutationKey('create');
      state.appendDraft = null;
      state.retireCode = null;
      state.createDraft = emptyDraft(
        state.asOf === '' ? normalizeReferenceCodeInstant(now()) : state.asOf
      );
      delete state.errors.create;
    },
    updateCreateDraft(changes) {
      if (state.createDraft === null)
        throw new Error('REFERENCE_CODE_CREATE_DRAFT_MISSING');
      clearMutationKey('create');
      Object.assign(state.createDraft, changes);
      delete state.errors.create;
    },
    cancelCreate() {
      clearMutationKey('create');
      state.createDraft = null;
      delete state.errors.create;
    },
    create: createEntry,
    beginAppend(entry) {
      currentEntry(entry.code);
      clearMutationKey('create');
      if (state.appendDraft !== null)
        clearMutationKey(`replace:${state.appendDraft.code}`);
      if (state.retireCode !== null)
        clearMutationKey(`retire:${state.retireCode}`);
      clearMutationKey(`replace:${entry.code}`);
      state.createDraft = null;
      state.retireCode = null;
      state.appendDraft = appendDraft(entry);
      delete state.errors[entry.code];
    },
    updateAppendDraft(changes) {
      if (state.appendDraft === null)
        throw new Error('REFERENCE_CODE_APPEND_DRAFT_MISSING');
      clearMutationKey(`replace:${state.appendDraft.code}`);
      Object.assign(state.appendDraft, changes);
      delete state.errors[state.appendDraft.code];
    },
    cancelAppend() {
      if (state.appendDraft !== null)
        clearMutationKey(`replace:${state.appendDraft.code}`);
      state.appendDraft = null;
    },
    appendVersion: replaceEntry,
    reloadStale,
    beginRetire(entry) {
      const current = currentEntry(entry.code);
      if (current.lifecycle === 'retired')
        throw new Error('REFERENCE_CODE_RETIRED');
      clearMutationKey('create');
      if (state.appendDraft !== null)
        clearMutationKey(`replace:${state.appendDraft.code}`);
      if (state.retireCode !== null)
        clearMutationKey(`retire:${state.retireCode}`);
      clearMutationKey(`retire:${current.code}`);
      state.createDraft = null;
      state.appendDraft = null;
      state.retireCode = current.code;
      delete state.errors[current.code];
    },
    cancelRetire() {
      if (state.retireCode !== null)
        clearMutationKey(`retire:${state.retireCode}`);
      state.retireCode = null;
    },
    retire: retireEntry,
    isPending: (code) => state.pendingResources.has(code),
    dispose() {
      generation += 1;
      collectionRequest = null;
      resourceRequests.clear();
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
      mutationKeys.clear();
      state.sets = [];
      state.selectedSet = null;
      state.asOf = '';
      state.effectiveStatus = 'all';
      state.includeRetired = false;
      state.entries = [];
      state.page = 1;
      state.pageSize = 50;
      state.total = 0;
      state.createDraft = null;
      state.appendDraft = null;
      state.retireCode = null;
      state.errors = {};
      state.stale = {};
      state.pendingResources.clear();
      state.requests.clear();
      state.loading = false;
    },
  };

  return runtime;
};

export const referenceCodesRuntimeKey: InjectionKey<ReferenceCodesRuntime> =
  Symbol(REFERENCE_CODES_STORE_KEY);

export const useReferenceCodesRuntime = (): ReferenceCodesRuntime => {
  const runtime = inject(referenceCodesRuntimeKey, null);
  if (runtime === null)
    throw new Error('REFERENCE_CODES_RUNTIME_NOT_INSTALLED');
  return runtime;
};
