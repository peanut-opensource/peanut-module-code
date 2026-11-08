import { describe, expect, it, vi } from 'vitest';

import {
  createProtectedFetch,
  createPlatformApiClient,
  createTenantApiClient,
} from '../api/client';
import {
  createMemoryRefreshCoordinator,
  isProblemCode,
  parseProblemDetails,
} from '@peanut-admin/vue';

const json = (body: unknown, status = 200): Response =>
  new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type':
        status >= 400 ? 'application/problem+json' : 'application/json',
    },
  });

type ProtectedFetchOptions = Parameters<typeof createProtectedFetch>[0];

const protectedFetchOptions = (
  overrides: Partial<ProtectedFetchOptions> = {}
): ProtectedFetchOptions => ({
  allowedOrigin: 'https://example.test',
  fetch: vi.fn(async () => json({ data: { ok: true } })),
  getAccessToken: vi.fn(() => 'access-token'),
  setAccessToken: vi.fn(),
  refresh: vi.fn(async () => 'fresh-token'),
  refreshScope: 'test-web:tenant',
  isAllowedPath: (pathname: string) => pathname.startsWith('/api/v1/'),
  ...overrides,
});

describe('protected fetch origin boundary', () => {
  it('rejects invalid origin configuration synchronously', () => {
    const missingOrigin = protectedFetchOptions();
    delete missingOrigin.allowedOrigin;
    const credentialBaseUrl = {
      ...missingOrigin,
      baseUrl: 'https://user:secret@example.test/api',
    };
    const pathOrigin = protectedFetchOptions({
      allowedOrigin: 'https://example.test/api',
    });

    expect(() => createProtectedFetch(missingOrigin)).toThrow(
      'API_ORIGIN_INVALID'
    );
    expect(() => createProtectedFetch(credentialBaseUrl)).toThrow(
      'API_ORIGIN_INVALID'
    );
    expect(() => createProtectedFetch(pathOrigin)).toThrow(
      'API_ORIGIN_INVALID'
    );

    for (const allowedOrigin of [
      'https://user:secret@example.test',
      'https://user@example.test',
      'https://:secret@example.test',
    ]) {
      const options = protectedFetchOptions({ allowedOrigin });
      expect(() => createProtectedFetch(options)).toThrow('API_ORIGIN_INVALID');
      expect(options.getAccessToken).not.toHaveBeenCalled();
      expect(options.setAccessToken).not.toHaveBeenCalled();
      expect(options.refresh).not.toHaveBeenCalled();
      expect(options.fetch).not.toHaveBeenCalled();
    }
  });

  it('sends a token only to the configured origin with manual redirects', async () => {
    const redirectResponse = new Response(null, {
      status: 302,
      headers: { Location: 'https://other.test/next' },
    });
    const fetcher = vi.fn(async (request: Request) => {
      expect(request.headers.get('Authorization')).toBe('Bearer access-token');
      expect(request.credentials).toBe('include');
      expect(request.redirect).toBe('manual');
      return redirectResponse;
    });
    const options = protectedFetchOptions({ fetch: fetcher });
    const transport = createProtectedFetch(options);

    const response = await transport(
      new Request('https://example.test/api/v1/items')
    );

    expect(response).toBe(redirectResponse);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(options.getAccessToken).toHaveBeenCalledOnce();
  });

  it.each([
    'http://example.test/api/v1/items',
    'https://other.test/api/v1/items',
    'https://example.test:444/api/v1/items',
  ])(
    'rejects a different scheme, host, or port before security callbacks: %s',
    async (requestUrl) => {
      const isAllowedPath = vi.fn(() => true);
      const createRequestId = vi.fn(() => 'req_origin_test');
      const options = protectedFetchOptions({ isAllowedPath, createRequestId });
      const transport = createProtectedFetch(options);

      await expect(transport(new Request(requestUrl))).rejects.toThrow(
        'API_ORIGIN_MISMATCH'
      );

      expect(options.getAccessToken).not.toHaveBeenCalled();
      expect(options.setAccessToken).not.toHaveBeenCalled();
      expect(options.refresh).not.toHaveBeenCalled();
      expect(options.fetch).not.toHaveBeenCalled();
      expect(isAllowedPath).not.toHaveBeenCalled();
      expect(createRequestId).not.toHaveBeenCalled();
    }
  );

  it.each([
    ['https://example.test:443', 'https://example.test/api/v1/items'],
    ['http://example.test:80', 'http://example.test/api/v1/items'],
  ])(
    'normalizes a default port in the configured origin',
    async (allowedOrigin, requestUrl) => {
      const options = protectedFetchOptions();
      delete options.allowedOrigin;
      options.baseUrl = allowedOrigin;
      const transport = createProtectedFetch(options);

      await expect(transport(new Request(requestUrl))).resolves.toBeInstanceOf(
        Response
      );

      expect(options.fetch).toHaveBeenCalledOnce();
    }
  );

  it('rejects a denied path before token access or fetch', async () => {
    const createRequestId = vi.fn(() => 'req_path_test');
    const options = protectedFetchOptions({ createRequestId });
    const transport = createProtectedFetch(options);

    await expect(
      transport(new Request('https://example.test/api/platform/v1/tenants'))
    ).rejects.toThrow('API_AUDIENCE_MISMATCH');

    expect(options.getAccessToken).not.toHaveBeenCalled();
    expect(options.setAccessToken).not.toHaveBeenCalled();
    expect(options.refresh).not.toHaveBeenCalled();
    expect(options.fetch).not.toHaveBeenCalled();
    expect(createRequestId).not.toHaveBeenCalled();
  });

  it('resolves an absolute base URL before consulting the browser fallback', () => {
    vi.stubGlobal('location', { origin: 'chrome-extension://extension-id' });
    try {
      const absoluteOptions = protectedFetchOptions();
      delete absoluteOptions.allowedOrigin;
      absoluteOptions.baseUrl = 'https://example.test/api';
      expect(() => createProtectedFetch(absoluteOptions)).not.toThrow();

      const relativeOptions = protectedFetchOptions();
      delete relativeOptions.allowedOrigin;
      relativeOptions.baseUrl = '/api';
      expect(() => createProtectedFetch(relativeOptions)).toThrow(
        'API_ORIGIN_INVALID'
      );
    } finally {
      vi.unstubAllGlobals();
    }
  });

  it('preserves same-origin refresh and idempotent replay', async () => {
    let token = 'expired';
    const requests: Request[] = [];
    const fetcher = vi.fn(async (request: Request) => {
      requests.push(request);
      return request.headers.get('Authorization') === 'Bearer fresh'
        ? json({ data: { ok: true } })
        : json({ code: 'AUTH_SESSION_EXPIRED' }, 401);
    });
    const refresh = vi.fn(async () => 'fresh');
    const transport = createProtectedFetch(
      protectedFetchOptions({
        fetch: fetcher,
        getAccessToken: () => token,
        setAccessToken: (value) => {
          token = value;
        },
        refresh,
      })
    );

    const response = await transport(
      new Request('https://example.test/api/v1/items')
    );

    expect(response.ok).toBe(true);
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(refresh).toHaveBeenCalledOnce();
    expect(token).toBe('fresh');
    expect(requests.map((request) => request.url)).toEqual([
      'https://example.test/api/v1/items',
      'https://example.test/api/v1/items',
    ]);
    expect(requests[1]?.headers.get('Authorization')).toBe('Bearer fresh');
    expect(requests[1]?.credentials).toBe('include');
    expect(requests[1]?.redirect).toBe('manual');
  });

  it('rechecks the retry boundary before writing or attaching a refreshed token', async () => {
    const isAllowedPath = vi
      .fn()
      .mockReturnValueOnce(true)
      .mockReturnValueOnce(false);
    const setAccessToken = vi.fn();
    const fetcher = vi.fn(async () =>
      json({ code: 'AUTH_SESSION_EXPIRED' }, 401)
    );
    const transport = createProtectedFetch(
      protectedFetchOptions({
        isAllowedPath,
        setAccessToken,
        fetch: fetcher,
        refresh: vi.fn(async () => 'fresh'),
      })
    );

    await expect(
      transport(new Request('https://example.test/api/v1/items'))
    ).rejects.toThrow('API_AUDIENCE_MISMATCH');

    expect(isAllowedPath).toHaveBeenCalledTimes(2);
    expect(setAccessToken).not.toHaveBeenCalled();
    expect(fetcher).toHaveBeenCalledOnce();
  });

  it('does not replay a non-idempotent request without an idempotency key', async () => {
    const fetcher = vi.fn(async () =>
      json({ code: 'AUTH_SESSION_EXPIRED' }, 401)
    );
    const refresh = vi.fn(async () => 'fresh');
    const transport = createProtectedFetch(
      protectedFetchOptions({ fetch: fetcher, refresh })
    );

    const response = await transport(
      new Request('https://example.test/api/v1/items', {
        method: 'POST',
        body: JSON.stringify({ name: 'test' }),
        headers: { 'Content-Type': 'application/json' },
      })
    );

    expect(response.status).toBe(401);
    expect(fetcher).toHaveBeenCalledOnce();
  });
});

describe('audience API clients', () => {
  it('coordinates concurrent tenant refresh into one rotation', async () => {
    let token = 'expired';
    const refresh = vi.fn(async () => {
      token = 'fresh';
      return token;
    });
    const fetcher = vi.fn(async (request: Request) => {
      return request.headers.get('Authorization') === 'Bearer fresh'
        ? json({ data: { id: '10' } })
        : json(
            {
              type: '/docs/problems/session-expired',
              title: 'Expired',
              status: 401,
              detail: 'Expired',
              code: 'AUTH_SESSION_EXPIRED',
              request_id: 'req_test_1',
            },
            401
          );
    });
    const client = createTenantApiClient({
      baseUrl: 'https://example.test',
      fetch: fetcher,
      getAccessToken: () => token,
      setAccessToken: (value) => {
        token = value;
      },
      refresh,
      refreshScope: 'admin-web:tenant',
    });

    const [first, second] = await Promise.all([
      client.GET('/api/v1/tenant'),
      client.GET('/api/v1/tenant'),
    ]);

    expect(first.data).toEqual({ data: { id: '10' } });
    expect(second.data).toEqual({ data: { id: '10' } });
    expect(refresh).toHaveBeenCalledTimes(1);
  });

  it('rejects a request that crosses the configured audience', async () => {
    const client = createPlatformApiClient({
      baseUrl: 'https://example.test',
      fetch: vi.fn(),
      getAccessToken: () => 'platform-token',
      setAccessToken: () => undefined,
      refresh: async () => null,
      refreshScope: 'platform-web:platform',
    });

    await expect(client.GET('/api/v1/tenant')).rejects.toThrow(
      'API_AUDIENCE_MISMATCH'
    );
  });

  it('serializes multi-target query arrays without duplicate PHP keys', async () => {
    let requestedUrl = '';
    const client = createTenantApiClient({
      baseUrl: 'https://example.test',
      fetch: vi.fn(async (request: Request) => {
        requestedUrl = request.url;
        return json({
          data: [],
          meta: { page: 1, page_size: 20, total: 0, total_pages: 0 },
        });
      }),
      getAccessToken: () => 'tenant-token',
      setAccessToken: () => undefined,
      refresh: async () => null,
      refreshScope: 'admin-web:tenant',
    });

    await client.GET('/api/v1/example/work-items', {
      params: {
        query: {
          target_resource_key: 'example.project',
          target_role: 'primary',
          target_id: ['1', '2'],
        },
      },
    });

    expect(requestedUrl).toContain('target_id=1,2');
    expect(requestedUrl).not.toContain('target_id=1&target_id=2');
  });

  it('coordinates separate client instances within one registered refresh scope', async () => {
    const coordinator = createMemoryRefreshCoordinator();
    let firstToken = 'expired';
    let secondToken = 'expired';
    const refresh = vi.fn(async () => 'fresh');
    const fetcher = vi.fn(async (request: Request) =>
      request.headers.get('Authorization') === 'Bearer fresh'
        ? json({ data: { ok: true } })
        : json({ code: 'AUTH_SESSION_EXPIRED' }, 401)
    );
    const first = createTenantApiClient({
      baseUrl: 'https://example.test',
      fetch: fetcher,
      getAccessToken: () => firstToken,
      setAccessToken: (token) => {
        firstToken = token;
      },
      refresh,
      refreshScope: 'single-store-web:tenant',
      refreshCoordinator: coordinator,
    });
    const second = createTenantApiClient({
      baseUrl: 'https://example.test',
      fetch: fetcher,
      getAccessToken: () => secondToken,
      setAccessToken: (token) => {
        secondToken = token;
      },
      refresh,
      refreshScope: 'single-store-web:tenant',
      refreshCoordinator: coordinator,
    });

    const [firstResult, secondResult] = await Promise.all([
      first.GET('/api/v1/tenant'),
      second.GET('/api/v1/tenant'),
    ]);

    expect(firstResult.response.ok).toBe(true);
    expect(secondResult.response.ok).toBe(true);
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(firstToken).toBe('fresh');
    expect(secondToken).toBe('fresh');
  });

  it('does not coordinate refresh across different registered client scopes', async () => {
    const coordinator = createMemoryRefreshCoordinator();
    const refresh = vi.fn(async () => 'fresh');
    const makeClient = (scope: string) =>
      createTenantApiClient({
        baseUrl: 'https://example.test',
        fetch: vi.fn(async () => json({ code: 'AUTH_SESSION_EXPIRED' }, 401)),
        getAccessToken: () => 'expired',
        setAccessToken: () => undefined,
        refresh,
        refreshScope: scope,
        refreshCoordinator: coordinator,
      });

    await Promise.all([
      makeClient('single-store-web:tenant').GET('/api/v1/tenant'),
      makeClient('multi-store-web:tenant').GET('/api/v1/tenant'),
    ]);

    expect(refresh).toHaveBeenCalledTimes(2);
  });

  it('parses only RFC 9457-shaped problems', () => {
    const problem = parseProblemDetails({
      type: '/docs/problems/precondition-required',
      title: 'Precondition required',
      status: 428,
      detail: 'If-Match is required.',
      code: 'PRECONDITION_REQUIRED',
      request_id: 'req_test_2',
    });

    expect(isProblemCode(problem, 'PRECONDITION_REQUIRED')).toBe(true);
    expect(parseProblemDetails({ code: 'INCOMPLETE' })).toBeNull();
  });
});
