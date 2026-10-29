import type { ModuleApiOperations } from '../generated/openapi';
import type {
  DocumentBusinessAction,
  DocumentBusinessOperation,
  DocumentBusinessResult,
  DocumentSoloBusinessReceipt,
  DocumentSaveTargetReceipt,
} from './contracts';

type Body<
  K extends
    | 'createDocumentVersion'
    | 'restoreDocumentVersion'
    | 'archiveDocument'
    | 'restoreDocument'
> = ModuleApiOperations[K]['requestBody']['content']['application/json'];
export type DocumentBusinessCommand =
  | { action: 'version.create'; input: Body<'createDocumentVersion'> }
  | { action: 'version.restore'; input: Body<'restoreDocumentVersion'> }
  | { action: 'archive'; input: Body<'archiveDocument'> }
  | { action: 'restore'; input: Body<'restoreDocument'> };
const record = (value: unknown): Record<string, unknown> => {
  if (!value || typeof value !== 'object' || Array.isArray(value))
    throw new Error('DOCUMENT_OPERATION_RESPONSE_INVALID');
  return value as Record<string, unknown>;
};
const integer = (value: unknown, min = 0) =>
  typeof value === 'number' && Number.isSafeInteger(value) && value >= min;
const digest = (value: unknown) =>
  typeof value === 'string' && /^[0-9a-f]{64}$/.test(value);
export function parseSaveTarget(
  value: unknown,
  documentId: number,
  requestId: string
): DocumentSaveTargetReceipt {
  const row = record(value);
  const unprepared =
    ['not_prepared', 'canceled'].includes(String(row.phase)) &&
    row.target_binary_sha256 === null &&
    row.target_projection_sha256 === null &&
    row.target_progress === null &&
    row.store_id === null &&
    row.state_seq === null &&
    row.committed_binary_sha256 === null &&
    row.revision_id === null;
  if (
    row.document_id !== documentId ||
    row.request_id !== requestId ||
    !['not_prepared', 'prepared', 'confirmed', 'consumed', 'canceled'].includes(
      String(row.phase)
    ) ||
    (row.phase === 'not_prepared' && !unprepared) ||
    (!unprepared &&
      (!digest(row.target_binary_sha256) ||
        !digest(row.target_projection_sha256) ||
        !integer(row.target_progress))) ||
    (row.revision_id !== null && !integer(row.revision_id, 1)) ||
    (row.phase === 'prepared' &&
      (row.store_id !== null ||
        row.state_seq !== null ||
        row.committed_binary_sha256 !== null ||
        row.revision_id !== null)) ||
    (['confirmed', 'consumed'].includes(String(row.phase)) &&
      (typeof row.store_id !== 'string' ||
        !row.store_id ||
        !integer(row.state_seq) ||
        !digest(row.committed_binary_sha256))) ||
    (row.phase === 'canceled' &&
      !(
        (row.store_id === null &&
          row.state_seq === null &&
          row.committed_binary_sha256 === null) ||
        (typeof row.store_id === 'string' &&
          !!row.store_id &&
          integer(row.state_seq) &&
          digest(row.committed_binary_sha256))
      )) ||
    (row.phase === 'consumed' && !integer(row.revision_id, 1))
  )
    throw new Error('DOCUMENT_SAVE_TARGET_RESPONSE_INVALID');
  return value as DocumentSaveTargetReceipt;
}
export const operationKey = (command: DocumentBusinessCommand) =>
  command.action === 'version.create'
    ? command.input.idempotency_key
    : command.input.request_id;
export function parseBusinessResult(
  value: unknown,
  documentId: number,
  operationId: string,
  action: DocumentBusinessAction
): DocumentBusinessResult {
  const row = record(value);
  if (
    row.document_id !== documentId ||
    row.operation_id !== operationId ||
    row.phase !== 'completed' ||
    !integer(row.state_seq) ||
    !integer(row.auth_revision) ||
    !integer(row.lease_epoch, 1) ||
    !digest(row.binary_sha256) ||
    !integer(row.target_progress) ||
    (row.store_id !== null &&
      (typeof row.store_id !== 'string' || !row.store_id)) ||
    !['active', 'archiving', 'archived', 'blocked_recovery'].includes(
      String(row.status)
    ) ||
    (action === 'archive' && row.status !== 'archived') ||
    (action === 'restore' && row.status !== 'active') ||
    (action.startsWith('version.') &&
      (!integer(row.revision_id, 1) ||
        !integer(row.revision_no, 1) ||
        !integer(row.source_state_seq) ||
        !digest(row.digest)))
  )
    throw new Error('DOCUMENT_OPERATION_RESULT_INVALID');
  return value as DocumentBusinessResult;
}
export function parseDocumentOperation(
  value: unknown,
  command: DocumentBusinessCommand
): DocumentBusinessOperation {
  const row = record(value);
  const key = operationKey(command);
  if (
    row.document_id !== command.input.document_id ||
    row.operation_id !== key ||
    row.action !== command.action ||
    !digest(row.input_sha256) ||
    !['prepared', 'attention', 'completed'].includes(String(row.phase)) ||
    (row.target_progress !== null && !integer(row.target_progress)) ||
    (row.store_id !== null &&
      (typeof row.store_id !== 'string' || !row.store_id)) ||
    (row.state_seq !== null && !integer(row.state_seq)) ||
    (row.binary_sha256 !== null && !digest(row.binary_sha256)) ||
    typeof row.released !== 'boolean' ||
    (row.phase !== 'completed' && (row.result !== null || row.released))
  )
    throw new Error('DOCUMENT_OPERATION_RESPONSE_INVALID');
  if (row.phase === 'completed') {
    const result = parseBusinessResult(
      row.result,
      command.input.document_id,
      key,
      command.action
    );
    if (
      command.action === 'version.create' &&
      result.source_state_seq !== Number(command.input.expected_state_seq)
    )
      throw new Error('DOCUMENT_OPERATION_TARGET_MISMATCH');
    if (
      !integer(row.target_progress) ||
      !integer(row.state_seq) ||
      !digest(row.binary_sha256)
    )
      throw new Error('DOCUMENT_OPERATION_RESULT_INVALID');
  }
  return value as DocumentBusinessOperation;
}
export function validateSoloResult(
  value: unknown,
  command: DocumentBusinessCommand
): DocumentSoloBusinessReceipt {
  const row = record(value);
  if (row.document_id !== command.input.document_id)
    throw new Error('DOCUMENT_OPERATION_RESULT_INVALID');
  if (command.action.startsWith('version.')) {
    if (
      !integer(row.revision_id, 1) ||
      !integer(row.revision_no, 1) ||
      !integer(row.source_state_seq) ||
      !digest(row.digest)
    )
      throw new Error('DOCUMENT_OPERATION_RESULT_INVALID');
  } else if (
    !integer(row.state_seq) ||
    !integer(row.auth_revision) ||
    row.status !== (command.action === 'archive' ? 'archived' : 'active') ||
    !['rich_text', 'file_resource'].includes(String(row.content_kind)) ||
    typeof row.schema_version !== 'string' ||
    !row.schema_version
  )
    throw new Error('DOCUMENT_OPERATION_RESULT_INVALID');
  return value as DocumentSoloBusinessReceipt;
}
