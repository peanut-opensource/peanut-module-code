import { getSessionSnapshot } from '@/utils/auth';
import { createWorkflowApi } from './api';
import { createWorkflowTransport } from './transport';

// eslint-disable-next-line import/prefer-default-export -- 公共模块入口按named export消费
export function createHostWorkflowApi() {
  return createWorkflowApi(
    createWorkflowTransport({
      baseUrl: new URL(
        import.meta.env.VITE_API_BASE_URL || '/',
        window.location.origin
      ).href,
      session: getSessionSnapshot,
    })
  );
}
