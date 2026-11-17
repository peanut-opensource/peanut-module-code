import { createModuleClient } from '../../generated/openapi';

export type ReferenceCodeLifecycle = 'active' | 'retired';
export type ReferenceCodeStatus = 'active' | 'inactive';
export type ReferenceCodeMetadataScalar = boolean | number | string | null;
export type ReferenceCodeMetadata = Readonly<
  Record<string, ReferenceCodeMetadataScalar>
>;
export type ReferenceCodeEffectiveStatusFilter = ReferenceCodeStatus | 'all';

export interface ReferenceCodeSetSummary {
  readonly moduleKey: string;
  readonly setKey: string;
  readonly name: string;
  readonly description: string;
  readonly definitionRevision: number;
}

export interface EffectiveReferenceCodeVersion {
  readonly revision: number;
  readonly label: string;
  readonly metadata: ReferenceCodeMetadata;
  readonly status: ReferenceCodeStatus;
  readonly sortOrder: number;
  readonly effectiveAt: string;
  readonly expiresAt: string | null;
}

export interface ReferenceCodeEntry {
  readonly moduleKey: string;
  readonly setKey: string;
  readonly code: string;
  readonly lifecycle: ReferenceCodeLifecycle;
  readonly revision: number;
  readonly etag: string;
  readonly effective: EffectiveReferenceCodeVersion | null;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly retiredAt: string | null;
}

export interface ReferenceCodeList {
  readonly items: readonly ReferenceCodeEntry[];
  readonly asOf: string;
  readonly page: number;
  readonly pageSize: number;
  readonly total: number;
}

export interface ReferenceCodeListQuery {
  readonly asOf: string;
  readonly effectiveStatus: ReferenceCodeEffectiveStatusFilter;
  readonly includeRetired: boolean;
  readonly page: number;
  readonly pageSize: number;
}

export interface ReferenceCodeVersionInput {
  readonly label: string;
  readonly metadata: ReferenceCodeMetadata;
  readonly status: ReferenceCodeStatus;
  readonly sortOrder: number;
  readonly effectiveAt: string;
  readonly expiresAt: string | null;
}

export interface ReferenceCodeCreateInput extends ReferenceCodeVersionInput {
  readonly code: string;
}

export interface ReferenceCodesTransportResult {
  readonly body: unknown;
  readonly headers: Headers;
  readonly status: number;
}

export interface ReferenceCodeCreateRequest {
  readonly input: ReferenceCodeCreateInput;
  readonly idempotencyKey: string;
  readonly signal: AbortSignal;
}

export interface ReferenceCodeReplaceRequest {
  readonly input: ReferenceCodeVersionInput;
  readonly etag: string;
  readonly idempotencyKey: string;
  readonly signal: AbortSignal;
}

export interface ReferenceCodeRetireRequest {
  readonly etag: string;
  readonly idempotencyKey: string;
  readonly signal: AbortSignal;
}

export interface ReferenceCodesTransport {
  listSets: (signal: AbortSignal) => Promise<ReferenceCodesTransportResult>;
  listCodes: (
    moduleKey: string,
    setKey: string,
    query: ReferenceCodeListQuery,
    signal: AbortSignal
  ) => Promise<ReferenceCodesTransportResult>;
  getCode: (
    moduleKey: string,
    setKey: string,
    code: string,
    asOf: string,
    signal: AbortSignal
  ) => Promise<ReferenceCodesTransportResult>;
  create: (
    moduleKey: string,
    setKey: string,
    request: ReferenceCodeCreateRequest
  ) => Promise<ReferenceCodesTransportResult>;
  replace: (
    moduleKey: string,
    setKey: string,
    code: string,
    request: ReferenceCodeReplaceRequest
  ) => Promise<ReferenceCodesTransportResult>;
  retire: (
    moduleKey: string,
    setKey: string,
    code: string,
    request: ReferenceCodeRetireRequest
  ) => Promise<ReferenceCodesTransportResult>;
}

export interface ReferenceCodesFetchTransportOptions {
  readonly baseUrl: string;
  readonly fetch: (request: Request) => Promise<Response>;
}

const moduleKeyPattern =
  /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/;
const localKeyPattern = /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/;
const etagPattern = /^"rev-[1-9][0-9]*"$/;
const instantPattern =
  /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.(\d{3})(?:Z|([+-])(\d{2}):(\d{2}))$/;

const invalidResponse = (): never => {
  throw new Error('REFERENCE_CODES_RESPONSE_INVALID');
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const hasExactKeys = (
  value: Record<string, unknown>,
  keys: readonly string[]
): boolean => {
  const actual = Object.keys(value).sort();
  const expected = [...keys].sort();
  return (
    actual.length === expected.length &&
    actual.every((key, index) => key === expected[index])
  );
};

const exactRecord = (
  value: unknown,
  keys: readonly string[]
): Record<string, unknown> => {
  if (!isRecord(value) || !hasExactKeys(value, keys)) return invalidResponse();
  return value;
};

const boundedString = (
  value: unknown,
  maximum: number,
  trim = false
): string => {
  if (typeof value !== 'string') return invalidResponse();
  const normalized = trim ? value.trim() : value;
  if (
    normalized === '' ||
    normalized !== value ||
    [...normalized].length > maximum
  )
    return invalidResponse();
  return normalized;
};

const positiveInteger = (value: unknown): number => {
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 1)
    return invalidResponse();
  return value;
};

const boundedInteger = (
  value: unknown,
  minimum: number,
  maximum: number
): number => {
  if (
    typeof value !== 'number' ||
    !Number.isSafeInteger(value) ||
    value < minimum ||
    value > maximum
  ) {
    return invalidResponse();
  }
  return value;
};

export const normalizeReferenceCodeInstant = (value: string): string => {
  const match = instantPattern.exec(value);
  if (match === null) throw new Error('REFERENCE_CODES_INSTANT_INVALID');
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const hour = Number(match[4]);
  const minute = Number(match[5]);
  const second = Number(match[6]);
  const offsetHour = match[9] === undefined ? 0 : Number(match[9]);
  const offsetMinute = match[10] === undefined ? 0 : Number(match[10]);
  const leapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
  const daysInMonth = [
    31,
    leapYear ? 29 : 28,
    31,
    30,
    31,
    30,
    31,
    31,
    30,
    31,
    30,
    31,
  ];
  if (
    month < 1 ||
    month > 12 ||
    day < 1 ||
    day > (daysInMonth[month - 1] ?? 0) ||
    hour > 23 ||
    minute > 59 ||
    second > 59 ||
    offsetHour > 23 ||
    offsetMinute > 59
  ) {
    throw new Error('REFERENCE_CODES_INSTANT_INVALID');
  }
  const timestamp = Date.parse(value);
  if (!Number.isFinite(timestamp))
    throw new Error('REFERENCE_CODES_INSTANT_INVALID');
  return new Date(timestamp).toISOString();
};

const instant = (value: unknown): string => {
  if (typeof value !== 'string') return invalidResponse();
  try {
    return normalizeReferenceCodeInstant(value);
  } catch {
    return invalidResponse();
  }
};

const nullableInstant = (value: unknown): string | null =>
  value === null ? null : instant(value);

const moduleKey = (value: unknown): string => {
  const parsed = boundedString(value, 96);
  return moduleKeyPattern.test(parsed) ? parsed : invalidResponse();
};

const localKey = (value: unknown): string => {
  const parsed = boundedString(value, 64);
  return localKeyPattern.test(parsed) ? parsed : invalidResponse();
};

const strongEtag = (value: unknown): string => {
  if (typeof value !== 'string' || !etagPattern.test(value))
    return invalidResponse();
  return value;
};

const metadata = (value: unknown): ReferenceCodeMetadata => {
  if (!isRecord(value) || Object.keys(value).length > 32)
    return invalidResponse();
  const parsed: Record<string, ReferenceCodeMetadataScalar> = {};
  Object.entries(value).forEach(([key, scalar]) => {
    if (!localKeyPattern.test(key) || key.length > 64) invalidResponse();
    if (scalar === null || typeof scalar === 'boolean') {
      parsed[key] = scalar as ReferenceCodeMetadataScalar;
    } else if (typeof scalar === 'number' && Number.isFinite(scalar)) {
      parsed[key] = scalar;
    } else if (typeof scalar === 'string' && [...scalar].length <= 500) {
      parsed[key] = scalar;
    } else {
      invalidResponse();
    }
  });
  if (new TextEncoder().encode(JSON.stringify(parsed)).byteLength > 8192)
    return invalidResponse();
  return parsed;
};

export const parseReferenceCodeMetadataText = (
  value: string
): ReferenceCodeMetadata => {
  let parsed: unknown;
  try {
    parsed = JSON.parse(value) as unknown;
  } catch {
    throw new Error('REFERENCE_CODES_METADATA_INVALID');
  }
  try {
    return metadata(parsed);
  } catch {
    throw new Error('REFERENCE_CODES_METADATA_INVALID');
  }
};

const setSummary = (value: unknown): ReferenceCodeSetSummary => {
  const record = exactRecord(value, [
    'module_key',
    'set_key',
    'name',
    'description',
    'definition_revision',
  ]);
  return {
    moduleKey: moduleKey(record.module_key),
    setKey: localKey(record.set_key),
    name: boundedString(record.name, 160, true),
    description: boundedString(record.description, 500, true),
    definitionRevision: positiveInteger(record.definition_revision),
  };
};

const effectiveVersion = (
  value: unknown
): EffectiveReferenceCodeVersion | null => {
  if (value === null) return null;
  const record = exactRecord(value, [
    'revision',
    'label',
    'metadata',
    'status',
    'sort_order',
    'effective_at',
    'expires_at',
  ]);
  if (record.status !== 'active' && record.status !== 'inactive')
    return invalidResponse();
  const effectiveAt = instant(record.effective_at);
  const expiresAt = nullableInstant(record.expires_at);
  if (expiresAt !== null && Date.parse(expiresAt) <= Date.parse(effectiveAt))
    return invalidResponse();
  return {
    revision: positiveInteger(record.revision),
    label: boundedString(record.label, 160, true),
    metadata: metadata(record.metadata),
    status: record.status,
    sortOrder: boundedInteger(record.sort_order, -1_000_000, 1_000_000),
    effectiveAt,
    expiresAt,
  };
};

const entryRecord = (value: unknown, asOf?: string): ReferenceCodeEntry => {
  const record = exactRecord(value, [
    'module_key',
    'set_key',
    'code',
    'lifecycle',
    'revision',
    'etag',
    'effective',
    'created_at',
    'updated_at',
    'retired_at',
  ]);
  if (record.lifecycle !== 'active' && record.lifecycle !== 'retired')
    return invalidResponse();
  const retiredAt = nullableInstant(record.retired_at);
  if (record.lifecycle === 'retired' && retiredAt === null)
    return invalidResponse();
  if (asOf !== undefined) {
    const retiredAtSnapshot =
      retiredAt !== null && Date.parse(retiredAt) <= Date.parse(asOf);
    if ((record.lifecycle === 'retired') !== retiredAtSnapshot)
      return invalidResponse();
  }
  const revision = positiveInteger(record.revision);
  const etag = strongEtag(record.etag);
  if (etag !== `"rev-${revision}"`) return invalidResponse();
  return {
    moduleKey: moduleKey(record.module_key),
    setKey: localKey(record.set_key),
    code: localKey(record.code),
    lifecycle: record.lifecycle,
    revision,
    etag,
    effective: effectiveVersion(record.effective),
    createdAt: instant(record.created_at),
    updatedAt: instant(record.updated_at),
    retiredAt,
  };
};

const dataEnvelope = (value: unknown): Record<string, unknown> => {
  const envelope = exactRecord(value, ['data', 'meta']);
  const meta = exactRecord(envelope.meta, ['request_id']);
  if (typeof meta.request_id !== 'string' || meta.request_id === '')
    return invalidResponse();
  if (!isRecord(envelope.data)) return invalidResponse();
  return envelope.data;
};

export const parseReferenceCodeSets = (
  value: unknown
): ReferenceCodeSetSummary[] => {
  const data = exactRecord(dataEnvelope(value), ['items']);
  if (!Array.isArray(data.items)) return invalidResponse();
  return data.items.map(setSummary);
};

export const parseReferenceCode = (
  value: unknown,
  responseEtag?: string
): ReferenceCodeEntry => {
  const parsed = entryRecord(dataEnvelope(value));
  if (responseEtag !== undefined) {
    let parsedHeader: string;
    try {
      parsedHeader = strongEtag(responseEtag);
    } catch {
      throw new Error('REFERENCE_CODES_RESPONSE_ETAG_INVALID');
    }
    if (parsedHeader !== parsed.etag)
      throw new Error('REFERENCE_CODES_RESPONSE_ETAG_MISMATCH');
  }
  return parsed;
};

export const parseReferenceCodeList = (value: unknown): ReferenceCodeList => {
  const data = exactRecord(dataEnvelope(value), [
    'items',
    'as_of',
    'page',
    'page_size',
    'total',
  ]);
  if (!Array.isArray(data.items)) return invalidResponse();
  const asOf = instant(data.as_of);
  const page = boundedInteger(data.page, 1, 10_000);
  const pageSize = boundedInteger(data.page_size, 1, 100);
  const total = boundedInteger(data.total, 0, Number.MAX_SAFE_INTEGER);
  return {
    items: data.items.map((item) => entryRecord(item, asOf)),
    asOf,
    page,
    pageSize,
    total,
  };
};

export const createReferenceCodesFetchTransport = (
  options: ReferenceCodesFetchTransportOptions
): ReferenceCodesTransport => {
  const sdk = createModuleClient({
    baseUrl: options.baseUrl,
    fetch: options.fetch,
  });
  const result = async (
    request: Promise<{ data?: unknown; error?: unknown; response: Response }>
  ): Promise<ReferenceCodesTransportResult> => {
    const { data, error, response } = await request;
    return {
      body: data ?? error ?? null,
      headers: response.headers,
      status: response.status,
    };
  };
  const headers = (
    idempotencyKey: string,
    precondition: Record<string, string>
  ): HeadersInit => ({
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'Idempotency-Key': idempotencyKey,
    ...precondition,
  });

  return {
    listSets(signal) {
      return result(
        sdk.listReferenceCodeSets({
          credentials: 'include',
          headers: { Accept: 'application/json' },
          signal,
        })
      );
    },
    listCodes(moduleKeyValue, setKeyValue, query, signal) {
      return result(
        sdk.listReferenceCodes({
          credentials: 'include',
          headers: { Accept: 'application/json' },
          params: {
            path: { moduleKey: moduleKeyValue, setKey: setKeyValue },
            query: {
              as_of: query.asOf,
              effective_status: query.effectiveStatus,
              include_retired: query.includeRetired,
              page: query.page,
              page_size: query.pageSize,
            },
          },
          signal,
        })
      );
    },
    getCode(moduleKeyValue, setKeyValue, codeValue, asOf, signal) {
      return result(
        sdk.getReferenceCode({
          credentials: 'include',
          headers: { Accept: 'application/json' },
          params: {
            path: {
              moduleKey: moduleKeyValue,
              setKey: setKeyValue,
              code: codeValue,
            },
            query: { as_of: asOf },
          },
          signal,
        })
      );
    },
    create(moduleKeyValue, setKeyValue, request) {
      return result(
        sdk.createReferenceCode({
          body: {
            code: request.input.code,
            label: request.input.label,
            metadata: request.input.metadata,
            status: request.input.status,
            sort_order: request.input.sortOrder,
            effective_at: request.input.effectiveAt,
            expires_at: request.input.expiresAt,
          },
          credentials: 'include',
          headers: headers(request.idempotencyKey, { 'If-None-Match': '*' }),
          params: {
            header: {
              'Idempotency-Key': request.idempotencyKey,
              'If-None-Match': '*',
            },
            path: { moduleKey: moduleKeyValue, setKey: setKeyValue },
          },
          signal: request.signal,
        })
      );
    },
    replace(moduleKeyValue, setKeyValue, codeValue, request) {
      return result(
        sdk.replaceReferenceCode({
          body: {
            label: request.input.label,
            metadata: request.input.metadata,
            status: request.input.status,
            sort_order: request.input.sortOrder,
            effective_at: request.input.effectiveAt,
            expires_at: request.input.expiresAt,
          },
          credentials: 'include',
          headers: headers(request.idempotencyKey, {
            'If-Match': request.etag,
          }),
          params: {
            header: {
              'Idempotency-Key': request.idempotencyKey,
              'If-Match': request.etag,
            },
            path: {
              moduleKey: moduleKeyValue,
              setKey: setKeyValue,
              code: codeValue,
            },
          },
          signal: request.signal,
        })
      );
    },
    retire(moduleKeyValue, setKeyValue, codeValue, request) {
      return result(
        sdk.retireReferenceCode({
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Idempotency-Key': request.idempotencyKey,
            'If-Match': request.etag,
          },
          params: {
            header: {
              'Idempotency-Key': request.idempotencyKey,
              'If-Match': request.etag,
            },
            path: {
              moduleKey: moduleKeyValue,
              setKey: setKeyValue,
              code: codeValue,
            },
          },
          signal: request.signal,
        })
      );
    },
  };
};
