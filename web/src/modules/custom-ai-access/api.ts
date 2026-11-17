import axios from 'axios';

export interface Provider {
  key: string;
  name: string;
  enabled: boolean;
  origin: string;
  api_prefix: string;
  priority: number;
}

export interface Credential {
  key: string;
  provider: string;
  name: string;
  enabled: boolean;
  models: string[];
  configured: boolean;
}

export interface AiModel {
  key: string;
  provider: string;
  name: string;
  enabled: boolean;
  definition?: string;
  remote_model: string;
  protocols: Record<string, string>;
  priority: number;
  input_limit_chars: number;
  max_output_tokens: number;
}

export type Capability = 'text' | 'image';

export interface Author {
  key: string;
  name: string;
  enabled: boolean;
}

export interface Definition extends Author {
  author: string;
  capabilities: Capability[];
}

export interface ModelDefault extends Author {
  key: Capability;
  provider: string;
  offering: string;
}

export interface Catalog {
  providers: Provider[];
  credentials: Credential[];
  models: AiModel[];
  authors: Author[];
  definitions: Definition[];
  defaults: ModelDefault[];
  can_configure: boolean;
}

export type SavePayload = {
  kind:
    | 'provider'
    | 'credential'
    | 'model'
    | 'author'
    | 'definition'
    | 'default';
  key: string;
  name: string;
  enabled: boolean;
  [field: string]: unknown;
};

export const getCatalog = () =>
  axios.get<Catalog>('/adminapi/custom.ai-access.catalog');

export const saveConfig = (payload: SavePayload) =>
  axios.post('/adminapi/custom.ai-access.save', payload);

export interface RemoteModel {
  id: string;
  owner: string;
}

export const discoverModels = (provider: string, credential: string) =>
  axios.post<{ items: RemoteModel[] }>('/adminapi/custom.ai-access.discover', {
    provider,
    credential,
  });

interface GenerationRequest {
  capability: Capability;
  prompt: string;
  request_key: string;
  provider?: string;
  model?: string;
  credential?: string;
}

interface Receipt {
  request_key: string;
  provider_key: string;
  model_key: string;
  credential_key: string;
  status: string;
}

export interface GenerationResult {
  text?: string;
  image?: string;
  mime_type?: string;
  request: Receipt;
}

export const generate = (payload: GenerationRequest) =>
  axios.post<GenerationResult>('/adminapi/custom.ai-access.generate', payload, {
    timeout: 135000,
  });

export const requestStatus = (requestKey: string) =>
  axios.get<Receipt>('/adminapi/custom.ai-access.request-status', {
    params: { request_key: requestKey },
  });
