import createClient from 'openapi-fetch';
import type { Client } from 'openapi-fetch';

import type { paths } from '../generated/api';
import { createBrowserRefreshCoordinator } from '@peanut-admin/vue';
import type { RefreshCoordinator } from '@peanut-admin/vue';

export type ApiAudience = 'tenant' | 'platform';

export interface AudienceApiClientOptions {
  baseUrl?: string;
  allowedOrigin?: string;
  fetch?: (request: Request) => Promise<Response>;
  getAccessToken: () => string | null;
  setAccessToken: (token: string) => void;
  refresh: () => Promise<string | null>;
  refreshScope: string;
  refreshCoordinator?: RefreshCoordinator;
  createRequestId?: () => string;
}

export type AudienceApiClient = Client<paths>;

const requestId = (): string => {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return `req_${globalThis.crypto.randomUUID().replace(/-/g, '')}`;
  }

  return `req_${Date.now().toString(36)}_${Math.random()
    .toString(36)
    .slice(2)}`;
};

const isAudiencePath = (audience: ApiAudience, pathname: string): boolean =>
  audience === 'tenant'
    ? pathname.startsWith('/api/v1/')
    : pathname.startsWith('/api/platform/v1/');

const isCredentialExchange = (pathname: string): boolean =>
  /\/auth\/(?:login|refresh|tenants\/select)$/.test(pathname);

const canReplay = (request: Request): boolean =>
  ['GET', 'HEAD', 'OPTIONS'].includes(request.method.toUpperCase()) ||
  request.headers.has('Idempotency-Key');

const originError = (): Error => new Error('API_ORIGIN_INVALID');

const parseHttpUrl = (value: string, base?: string): URL => {
  let url: URL;
  try {
    url = base === undefined ? new URL(value) : new URL(value, base);
  } catch {
    throw originError();
  }

  if (
    !['http:', 'https:'].includes(url.protocol) ||
    url.origin === 'null' ||
    url.username !== '' ||
    url.password !== ''
  ) {
    throw originError();
  }

  return url;
};

const browserOrigin = (): string | undefined => {
  const origin = globalThis.location?.origin;
  if (typeof origin !== 'string' || origin === '' || origin === 'null')
    return undefined;

  return parseHttpUrl(origin).origin;
};

const resolveAllowedOrigin = (
  options: Pick<ProtectedFetchOptions, 'allowedOrigin' | 'baseUrl'>
): string => {
  if (options.allowedOrigin !== undefined) {
    const url = parseHttpUrl(options.allowedOrigin);
    if (url.pathname !== '/' || url.search !== '' || url.hash !== '')
      throw originError();
    return url.origin;
  }

  if (options.baseUrl !== undefined && options.baseUrl !== '') {
    try {
      return parseHttpUrl(options.baseUrl).origin;
    } catch {
      const fallbackOrigin = browserOrigin();
      if (fallbackOrigin === undefined) throw originError();
      return parseHttpUrl(options.baseUrl, fallbackOrigin).origin;
    }
  }
  const fallbackOrigin = browserOrigin();
  if (fallbackOrigin !== undefined) return fallbackOrigin;

  throw originError();
};

const assertProtectedRequest = (
  request: Request,
  allowedOrigin: string,
  isAllowedPath: (pathname: string) => boolean
): URL => {
  const url = parseHttpUrl(request.url);
  if (url.origin !== allowedOrigin) {
    throw new Error('API_ORIGIN_MISMATCH');
  }
  if (!isAllowedPath(url.pathname)) {
    throw new Error(
      `API_AUDIENCE_MISMATCH: protected client cannot request ${url.pathname}`
    );
  }

  return url;
};

const withSecurityHeaders = (
  request: Request,
  token: string | null,
  createRequestId: () => string
): Request => {
  const headers = new Headers(request.headers);
  if (token !== null && token !== '') {
    headers.set('Authorization', `Bearer ${token}`);
  } else {
    headers.delete('Authorization');
  }
  if (!headers.has('X-Request-Id')) {
    headers.set('X-Request-Id', createRequestId());
  }

  return new Request(request, {
    headers,
    credentials: 'include',
    redirect: 'manual',
  });
};

export interface ProtectedFetchOptions extends AudienceApiClientOptions {
  isAllowedPath: (pathname: string) => boolean;
  isCredentialExchange?: (pathname: string) => boolean;
}

const defaultRefreshCoordinator = createBrowserRefreshCoordinator();

export const createProtectedFetch = (
  options: ProtectedFetchOptions
): ((request: Request) => Promise<Response>) => {
  const allowedOrigin = resolveAllowedOrigin(options);
  const fetcher = options.fetch ?? globalThis.fetch.bind(globalThis);
  const createRequestId = options.createRequestId ?? requestId;
  const refreshCoordinator =
    options.refreshCoordinator ?? defaultRefreshCoordinator;
  const credentialExchange =
    options.isCredentialExchange ?? isCredentialExchange;

  const refreshOnce = async (
    failedToken: string | null
  ): Promise<string | null> => {
    return refreshCoordinator.coordinate({
      scope: options.refreshScope,
      failedToken,
      getAccessToken: options.getAccessToken,
      refresh: options.refresh,
    });
  };

  return async (input: Request): Promise<Response> => {
    const url = assertProtectedRequest(
      input,
      allowedOrigin,
      options.isAllowedPath
    );

    const failedToken = options.getAccessToken();
    const firstRequest = withSecurityHeaders(
      input,
      failedToken,
      createRequestId
    );
    const retrySource = firstRequest.clone();
    const response = await fetcher(firstRequest);
    if (response.status !== 401 || credentialExchange(url.pathname)) {
      return response;
    }

    const refreshedToken = await refreshOnce(failedToken);
    if (refreshedToken === null || refreshedToken === '') {
      return response;
    }

    assertProtectedRequest(retrySource, allowedOrigin, options.isAllowedPath);
    options.setAccessToken(refreshedToken);
    if (!canReplay(retrySource)) return response;

    return fetcher(
      withSecurityHeaders(retrySource, refreshedToken, createRequestId)
    );
  };
};

const createAudienceClient = (
  audience: ApiAudience,
  options: AudienceApiClientOptions
): AudienceApiClient => {
  const securedFetch = createProtectedFetch({
    ...options,
    isAllowedPath: (pathname) => isAudiencePath(audience, pathname),
  });

  return createClient<paths>({
    baseUrl: options.baseUrl ?? '',
    credentials: 'include',
    fetch: securedFetch,
    querySerializer: { array: { style: 'form', explode: false } },
  });
};

export const createTenantApiClient = (
  options: AudienceApiClientOptions
): AudienceApiClient => createAudienceClient('tenant', options);

export const createPlatformApiClient = (
  options: AudienceApiClientOptions
): AudienceApiClient => createAudienceClient('platform', options);
