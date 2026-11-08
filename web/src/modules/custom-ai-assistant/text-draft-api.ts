import axios from 'axios';
import { base, current } from './api-session';

export interface Lookup {
  id: number;
  title: string;
  version: number;
  capability: string | null;
  provider_model_id?: string | null;
  consumer_module?: string | null;
}

export interface GenerateTextInput {
  route_id: number;
  consumer_module: string;
  topic: string;
  outline?: string;
  request_id: string;
  confirm_send: true;
}

export async function generateText(
  input: GenerateTextInput,
  signal: AbortSignal
) {
  const result = await current<{
    candidate_id: number;
    request_id: string;
    text: string;
  }>(() =>
    axios.post(`${base}generateText`, input, { signal, timeout: 45000 })
  );
  if (
    !Number.isSafeInteger(result?.candidate_id) ||
    result.candidate_id < 1 ||
    result.request_id !== input.request_id ||
    typeof result.text !== 'string' ||
    !result.text.trim()
  )
    throw new Error('未取得匹配原请求的正文候选，请核对原请求。');
  return result;
}

export const textRoutes = (
  consumer: string,
  page: number,
  signal: AbortSignal
) =>
  current<{ list: Lookup[]; count: number; page_size: number }>(() =>
    axios.get(`${base}textRoutes`, {
      params: { consumer_module: consumer, page },
      signal,
    })
  );
