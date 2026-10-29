/**
 * Browser-side OAuth contract for the PC client.
 *
 * The API client is injected so these helpers remain usable with Nuxt's
 * composable request wrapper without creating a composable outside setup.
 */
export interface OAuthRequestClient {
  post<T = unknown>(
    url: string,
    body?: Record<string, unknown> | null,
    auth?: boolean
  ): Promise<T>;
}

export interface OAuthMember {
  id: number;
  sn: string;
  nickname: string;
  avatar: string;
  mobile: string;
}

export interface OAuthBeginResult {
  authorization_url: string;
  expires_in: number;
}

export interface OAuthCompletedResult {
  completed: true;
  token: string;
  member: OAuthMember;
  return_path?: string;
}

export interface OAuthCompletionResult {
  completed: false;
  completion_ticket: string;
  expires_in: number;
  need_profile: boolean;
  need_mobile: boolean;
  member: OAuthMember;
  return_path?: string;
}

export type OAuthCallbackResult = OAuthCompletedResult | OAuthCompletionResult;

export function beginWechatPc(
  client: OAuthRequestClient,
  returnPath: string
): Promise<OAuthBeginResult> {
  return client.post<OAuthBeginResult>(
    'api/oauth/wechat/begin',
    {
      scene: 'open_pc',
      return_path: returnPath,
    },
    false
  );
}

export function callbackWechatPc(
  client: OAuthRequestClient,
  code: string,
  state: string
): Promise<OAuthCallbackResult> {
  return client.post<OAuthCallbackResult>(
    'api/oauth/wechat/callback',
    {
      scene: 'open_pc',
      code,
      state,
    },
    false
  );
}

export interface OAuthCompletePayload extends Record<string, unknown> {
  ticket: string;
  nickname?: string;
  avatar?: string;
  mobile?: string;
  verification_code?: string;
}

export function completeWechat(
  client: OAuthRequestClient,
  payload: OAuthCompletePayload
): Promise<OAuthCompletedResult> {
  return client.post<OAuthCompletedResult>(
    'api/oauth/wechat/complete',
    payload,
    false
  );
}
