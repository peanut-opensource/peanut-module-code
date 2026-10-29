import { inject, reactive } from 'vue';
import type { InjectionKey } from 'vue';

import {
  groupSettingRecords,
  parseSettingResponse,
  parseSettingsList,
  settingEditorKind,
} from './contracts';
import type {
  SettingGroup,
  SettingRecord,
  SettingsTransport,
  SettingsTransportResult,
} from './contracts';

export type { SettingsTransport, SettingsTransportResult } from './contracts';

export const SETTINGS_MODULE_KEY = 'peanut.settings' as const;
export const SETTINGS_ROUTE_NAME = 'peanut.settings.list' as const;
export const SETTINGS_ROUTE_PATH = '/system/settings' as const;
export const SETTINGS_READ_PERMISSION = 'peanut.settings.read' as const;
export const SETTINGS_MANAGE_PERMISSION = 'peanut.settings.manage' as const;
export const SETTINGS_STORE_KEY = 'peanut.settings.runtime' as const;

export interface SettingFormState {
  value: unknown;
  dirty: boolean;
  editRevision: number;
}

export interface SettingRequestError {
  kind: 'http' | 'protocol' | 'transport' | 'validation';
  message: string;
  requestId: string | null;
  status: number | null;
}

export interface SettingConflictState {
  message: string;
  requestId: string | null;
}

export interface SettingsRuntimeState {
  records: SettingRecord[];
  groups: SettingGroup[];
  forms: Record<string, SettingFormState>;
  errors: Record<string, SettingRequestError>;
  etags: Record<string, string>;
  collectionEtag: string | null;
  conflicts: Record<string, SettingConflictState>;
  pendingResources: Set<string>;
  pendingVisibility: Set<string>;
  requests: Set<string>;
  loading: boolean;
}

export interface SettingsRuntime {
  readonly state: SettingsRuntimeState;
  canManage: () => boolean;
  load: () => Promise<void>;
  reload: (resourceKey: string) => Promise<void>;
  save: (resourceKey: string) => Promise<void>;
  unset: (resourceKey: string) => Promise<void>;
  updateForm: (resourceKey: string, value: unknown) => void;
  setSecretVisible: (resourceKey: string, visible: boolean) => void;
  isSecretVisible: (resourceKey: string) => boolean;
  isPending: (resourceKey: string) => boolean;
  dispose: () => void;
}

export interface SettingsRuntimeOptions {
  readonly transport: SettingsTransport;
  readonly canRead: () => boolean;
  readonly canManage: () => boolean;
  readonly createIdempotencyKey?: () => string;
}

const resourceKey = (
  record: Pick<SettingRecord, 'moduleKey' | 'settingKey'>
): string => `${record.moduleKey}/${record.settingKey}`;

const idempotencyKey = (): string => {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return `idem_${globalThis.crypto.randomUUID().replace(/-/g, '')}`;
  }
  return `idem_${Date.now().toString(36)}_${Math.random()
    .toString(36)
    .slice(2)}`;
};

const strongEtag = (value: string | null): string | null =>
  value !== null && /^"[^"\r\n]+"$/.test(value) ? value : null;

const responseRequestId = (result: SettingsTransportResult): string | null => {
  if (
    typeof result.body === 'object' &&
    result.body !== null &&
    !Array.isArray(result.body)
  ) {
    const requestId = (result.body as Record<string, unknown>).request_id;
    if (typeof requestId === 'string' && requestId !== '') return requestId;
  }
  const requestId = result.headers.get('X-Request-Id');
  return requestId === null || requestId === '' ? null : requestId;
};

const requestError = (result: SettingsTransportResult): SettingRequestError => {
  if (
    typeof result.body !== 'object' ||
    result.body === null ||
    Array.isArray(result.body)
  ) {
    return {
      kind: 'http',
      message: `Settings request failed (${result.status}).`,
      requestId: responseRequestId(result),
      status: result.status,
    };
  }
  const body = result.body as Record<string, unknown>;
  return {
    kind: 'http',
    message:
      typeof body.detail === 'string' && body.detail !== ''
        ? body.detail
        : `Settings request failed (${result.status}).`,
    requestId: responseRequestId(result),
    status: result.status,
  };
};

const protocolError = (
  result: SettingsTransportResult
): SettingRequestError => ({
  kind: 'protocol',
  message: 'The settings response could not be validated.',
  requestId: responseRequestId(result),
  status: result.status,
});

const transportError = (): SettingRequestError => ({
  kind: 'transport',
  message: 'The settings service could not be reached.',
  requestId: null,
  status: null,
});

const validationError = (message: string): SettingRequestError => ({
  kind: 'validation',
  message,
  requestId: null,
  status: null,
});

const isAbortError = (error: unknown): boolean =>
  typeof error === 'object' &&
  error !== null &&
  'name' in error &&
  error.name === 'AbortError';

const initialValue = (record: SettingRecord): unknown =>
  record.secret ? '' : record.value;

const initialFormState = (record: SettingRecord): SettingFormState => ({
  value: initialValue(record),
  dirty: false,
  editRevision: 0,
});

const formState = (
  records: readonly SettingRecord[]
): Record<string, SettingFormState> =>
  Object.fromEntries(
    records.map((record) => [resourceKey(record), initialFormState(record)])
  );

const etagState = (records: readonly SettingRecord[]): Record<string, string> =>
  Object.fromEntries(
    records.flatMap((record) =>
      record.etag === null ? [] : [[resourceKey(record), record.etag]]
    )
  );

const assertSuccessful = (result: SettingsTransportResult): void => {
  if (result.status < 200 || result.status >= 300) {
    throw new Error(`SETTINGS_REQUEST_FAILED_${result.status}`);
  }
};

export const createSettingsRuntime = (
  options: SettingsRuntimeOptions
): SettingsRuntime => {
  const state = reactive<SettingsRuntimeState>({
    records: [],
    groups: [],
    forms: {},
    errors: {},
    etags: {},
    collectionEtag: null,
    conflicts: {},
    pendingResources: new Set<string>(),
    pendingVisibility: new Set<string>(),
    requests: new Set<string>(),
    loading: false,
  });
  const controllers = new Map<string, AbortController>();
  const resourceRequests = new Map<
    string,
    { controller: AbortController; generation: number; id: string }
  >();
  const createIdempotencyKey = options.createIdempotencyKey ?? idempotencyKey;
  let listRequest: {
    controller: AbortController;
    generation: number;
    id: string;
  } | null = null;
  let generation = 0;
  let requestSequence = 0;

  const beginRequest = (
    kind: string
  ): { controller: AbortController; generation: number; id: string } => {
    const controller = new AbortController();
    requestSequence += 1;
    const id = `${kind}:${requestSequence}`;
    controllers.set(id, controller);
    state.requests.add(id);
    return { controller, generation, id };
  };

  const finishRequest = (id: string): void => {
    controllers.delete(id);
    state.requests.delete(id);
  };

  const cancelListRequest = (): void => {
    if (listRequest === null) return;
    const request = listRequest;
    listRequest = null;
    request.controller.abort();
    finishRequest(request.id);
    state.loading = false;
  };

  const beginListRequest = (): {
    controller: AbortController;
    generation: number;
    id: string;
  } => {
    if (resourceRequests.size > 0) throw new Error('SETTINGS_MUTATION_PENDING');
    cancelListRequest();
    const request = beginRequest('list');
    listRequest = request;
    state.loading = true;
    return request;
  };

  const beginResourceRequest = (
    kind: 'save' | 'unset',
    key: string
  ): { controller: AbortController; generation: number; id: string } => {
    if (resourceRequests.has(key))
      throw new Error('SETTINGS_RESOURCE_REQUEST_PENDING');
    cancelListRequest();
    const request = beginRequest(`${kind}:${key}`);
    resourceRequests.set(key, request);
    state.pendingResources.add(key);
    return request;
  };

  const isCurrentListRequest = (request: {
    generation: number;
    id: string;
  }): boolean =>
    request.generation === generation && listRequest?.id === request.id;

  const isCurrentResourceRequest = (
    key: string,
    request: { generation: number; id: string }
  ): boolean =>
    request.generation === generation &&
    resourceRequests.get(key)?.id === request.id;

  const finishListRequest = (request: { id: string }): void => {
    if (listRequest?.id === request.id) {
      listRequest = null;
      state.loading = false;
    }
    finishRequest(request.id);
  };

  const finishResourceRequest = (
    key: string,
    request: { id: string }
  ): void => {
    if (resourceRequests.get(key)?.id === request.id) {
      resourceRequests.delete(key);
      state.pendingResources.delete(key);
    }
    finishRequest(request.id);
  };

  const applyRecords = (
    records: SettingRecord[],
    collectionEtag: string
  ): void => {
    state.records = records;
    state.groups = groupSettingRecords(records);
    state.forms = formState(records);
    state.etags = etagState(records);
    state.collectionEtag = collectionEtag;
    state.errors = {};
    state.conflicts = {};
    state.pendingResources.clear();
    state.pendingVisibility.clear();
  };

  const applyPageError = (error: SettingRequestError): void => {
    state.records = [];
    state.groups = [];
    state.forms = {};
    state.etags = {};
    state.collectionEtag = null;
    state.conflicts = {};
    state.pendingResources.clear();
    state.pendingVisibility.clear();
    state.errors = { page: error };
  };

  const currentRecord = (key: string): SettingRecord => {
    const record = state.records.find(
      (candidate) => resourceKey(candidate) === key
    );
    if (record === undefined) throw new Error('SETTINGS_RECORD_UNKNOWN');
    return record;
  };

  const applyResourceValidationError = (key: string, message: string): void => {
    state.errors[key] = validationError(message);
    delete state.conflicts[key];
  };

  const applyRecord = (
    record: SettingRecord,
    preservedForm?: SettingFormState
  ): void => {
    const key = resourceKey(record);
    const index = state.records.findIndex(
      (candidate) => resourceKey(candidate) === key
    );
    if (index === -1) {
      state.records = [...state.records, record];
    } else {
      state.records = state.records.map((candidate, candidateIndex) =>
        candidateIndex === index ? record : candidate
      );
    }
    state.groups = groupSettingRecords(state.records);
    state.forms[key] = preservedForm ?? initialFormState(record);
    if (record.etag === null) delete state.etags[key];
    else state.etags[key] = record.etag;
    delete state.errors[key];
    delete state.conflicts[key];
    state.pendingVisibility.delete(key);
  };

  const updatedRecord = (result: SettingsTransportResult): SettingRecord => {
    assertSuccessful(result);
    const responseEtag = strongEtag(result.headers.get('ETag'));
    if (responseEtag === null)
      throw new Error('SETTINGS_RESPONSE_ETAG_INVALID');
    return parseSettingResponse(result.body, responseEtag);
  };

  const load = async (): Promise<void> => {
    if (!options.canRead()) throw new Error('SETTINGS_READ_FORBIDDEN');
    const request = beginListRequest();
    try {
      let result: SettingsTransportResult;
      try {
        result = await options.transport.list(request.controller.signal);
      } catch (error) {
        if (
          !isCurrentListRequest(request) ||
          request.controller.signal.aborted ||
          isAbortError(error)
        )
          return;
        applyPageError(transportError());
        return;
      }
      if (!isCurrentListRequest(request) || request.controller.signal.aborted)
        return;
      if (result.status < 200 || result.status >= 300) {
        applyPageError(requestError(result));
        return;
      }
      try {
        const collectionEtag = strongEtag(result.headers.get('ETag'));
        if (collectionEtag === null)
          throw new Error('SETTINGS_RESPONSE_ETAG_INVALID');
        applyRecords(parseSettingsList(result.body), collectionEtag);
      } catch {
        applyPageError(protocolError(result));
      }
    } finally {
      finishListRequest(request);
    }
  };

  const save = async (key: string): Promise<void> => {
    const record = currentRecord(key);
    if (!options.canManage()) {
      applyResourceValidationError(
        key,
        'You do not have permission to manage this setting.'
      );
      return;
    }
    if (settingEditorKind(record) === 'unsupported') {
      applyResourceValidationError(key, 'This setting type is read-only.');
      return;
    }
    const form = state.forms[key];
    if (form === undefined) {
      applyResourceValidationError(
        key,
        'The setting form is unavailable. Reload settings and try again.'
      );
      return;
    }
    if (!form.dirty) {
      applyResourceValidationError(
        key,
        'Change the setting value before saving.'
      );
      return;
    }
    if (
      record.secret &&
      (typeof form.value !== 'string' || form.value === '')
    ) {
      applyResourceValidationError(
        key,
        'Enter a non-empty secret value before saving.'
      );
      return;
    }

    const submittedValue = form.value;
    const submittedEditRevision = form.editRevision;
    const request = beginResourceRequest('save', key);
    delete state.errors[key];
    delete state.conflicts[key];
    try {
      const etag = state.etags[key];
      let result: SettingsTransportResult;
      try {
        result = await options.transport.replace(
          record.moduleKey,
          record.settingKey,
          {
            value: submittedValue,
            idempotencyKey: createIdempotencyKey(),
            precondition:
              etag === undefined
                ? { kind: 'create' }
                : { kind: 'replace', etag },
            signal: request.controller.signal,
          }
        );
      } catch (error) {
        if (
          !isCurrentResourceRequest(key, request) ||
          request.controller.signal.aborted ||
          isAbortError(error)
        )
          return;
        state.errors[key] = transportError();
        return;
      }
      if (
        !isCurrentResourceRequest(key, request) ||
        request.controller.signal.aborted
      )
        return;
      if (result.status === 412) {
        state.conflicts[key] = requestError(result);
        return;
      }
      if (result.status < 200 || result.status >= 300) {
        state.errors[key] = requestError(result);
        return;
      }
      let parsedRecord: SettingRecord;
      try {
        parsedRecord = updatedRecord(result);
      } catch {
        state.errors[key] = protocolError(result);
        return;
      }
      const currentForm = state.forms[key];
      const preservedForm =
        currentForm !== undefined &&
        currentForm.editRevision !== submittedEditRevision
          ? { ...currentForm }
          : undefined;
      applyRecord(parsedRecord, preservedForm);
    } finally {
      finishResourceRequest(key, request);
    }
  };

  const unset = async (key: string): Promise<void> => {
    const record = currentRecord(key);
    if (!options.canManage()) {
      applyResourceValidationError(
        key,
        'You do not have permission to manage this setting.'
      );
      return;
    }
    if (settingEditorKind(record) === 'unsupported') {
      applyResourceValidationError(key, 'This setting type is read-only.');
      return;
    }
    const etag = state.etags[key];
    if (etag === undefined) {
      applyResourceValidationError(
        key,
        'Reload this setting before unsetting it.'
      );
      return;
    }
    const request = beginResourceRequest('unset', key);
    delete state.errors[key];
    delete state.conflicts[key];
    try {
      let result: SettingsTransportResult;
      try {
        result = await options.transport.unset(
          record.moduleKey,
          record.settingKey,
          {
            idempotencyKey: createIdempotencyKey(),
            etag,
            signal: request.controller.signal,
          }
        );
      } catch (error) {
        if (
          !isCurrentResourceRequest(key, request) ||
          request.controller.signal.aborted ||
          isAbortError(error)
        )
          return;
        state.errors[key] = transportError();
        return;
      }
      if (
        !isCurrentResourceRequest(key, request) ||
        request.controller.signal.aborted
      )
        return;
      if (result.status === 412) {
        state.conflicts[key] = requestError(result);
        return;
      }
      if (result.status < 200 || result.status >= 300) {
        state.errors[key] = requestError(result);
        return;
      }
      try {
        applyRecord(updatedRecord(result));
      } catch {
        state.errors[key] = protocolError(result);
      }
    } finally {
      finishResourceRequest(key, request);
    }
  };

  const runtime: SettingsRuntime = {
    state,
    canManage: options.canManage,
    load,
    async reload(key) {
      currentRecord(key);
      await load();
    },
    save,
    unset,
    updateForm(key, value) {
      const form = state.forms[key];
      if (form === undefined) throw new Error('SETTINGS_FORM_MISSING');
      form.value = value;
      form.dirty = true;
      form.editRevision += 1;
      delete state.errors[key];
    },
    setSecretVisible(key, visible) {
      currentRecord(key);
      if (visible) state.pendingVisibility.add(key);
      else state.pendingVisibility.delete(key);
    },
    isSecretVisible: (key) => state.pendingVisibility.has(key),
    isPending: (key) => state.pendingResources.has(key),
    dispose() {
      generation += 1;
      listRequest = null;
      resourceRequests.clear();
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
      state.records = [];
      state.groups = [];
      state.forms = {};
      state.errors = {};
      state.etags = {};
      state.collectionEtag = null;
      state.conflicts = {};
      state.pendingResources.clear();
      state.pendingVisibility.clear();
      state.requests.clear();
      state.loading = false;
    },
  };

  return runtime;
};

export const settingsRuntimeKey: InjectionKey<SettingsRuntime> =
  Symbol(SETTINGS_STORE_KEY);

export const useSettingsRuntime = (): SettingsRuntime => {
  const runtime = inject(settingsRuntimeKey, null);
  if (runtime === null) throw new Error('SETTINGS_RUNTIME_NOT_INSTALLED');
  return runtime;
};
