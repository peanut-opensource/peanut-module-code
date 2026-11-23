import type { DefineComponent, InjectionKey } from 'vue';

export interface MaintenanceScheduleInput {
  readonly reasonKey: string;
  readonly startsAt: string;
  readonly endsAt: string;
}

export interface OpsTransportResult {
  readonly body: unknown;
  readonly headers: Headers;
  readonly status: number;
}

export interface OpsConsoleTransport {
  overview: (signal: AbortSignal) => Promise<OpsTransportResult>;
  submitBackup: (
    providerKey: string,
    idempotencyKey: string,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
  submitRestore: (
    providerKey: string,
    backupReferenceKey: string,
    targetKey: string,
    idempotencyKey: string,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
  task: (taskKey: string, signal: AbortSignal) => Promise<OpsTransportResult>;
  maintenance: (signal: AbortSignal) => Promise<OpsTransportResult>;
  scheduleMaintenance: (
    input: MaintenanceScheduleInput,
    expectedRevision: number,
    idempotencyKey: string,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
  closeMaintenance: (
    maintenanceKey: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
  logs: (
    sourceKey: string,
    severity: 'info' | 'warning' | 'error' | 'critical',
    cursor: string | null,
    pageSize: number,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
}

export interface OpsConsoleRuntimeStub {
  dispose: () => void;
  load: (section: 'all' | 'overview' | 'maintenance') => Promise<void>;
  loadLogs: () => Promise<void>;
}

export declare const opsConsoleRuntimeKey: InjectionKey<OpsConsoleRuntimeStub>;
export declare function createOpsConsoleRuntime(
  options: Record<string, unknown>
): OpsConsoleRuntimeStub;
export declare const OpsConsolePage: DefineComponent<Record<string, unknown>>;
