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
  remote_model: string;
  protocols: Record<string, string>;
  priority: number;
  input_limit_chars: number;
  max_output_tokens: number;
}

export interface Catalog {
  providers: Provider[];
  credentials: Credential[];
  models: AiModel[];
  can_configure: boolean;
}

export type SavePayload = {
  kind: 'provider' | 'credential' | 'model';
  key: string;
  name: string;
  enabled: boolean;
  [field: string]: unknown;
};

export const getCatalog = () =>
  axios.get<Catalog>('/adminapi/custom.ai-access.catalog');

export const saveConfig = (payload: SavePayload) =>
  axios.post('/adminapi/custom.ai-access.save', payload);
