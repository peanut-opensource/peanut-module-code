import type { RichTextDocumentValue } from '../../peanut-rich-text/src/document';

export interface DocumentChange {
  change_id: number;
  document_id: number;
  state_seq: number;
  kind:
    | 'solo.save'
    | 'version.restore'
    | 'suggestion.accept'
    | 'collaboration.update';
  actor_member_id: number | null;
  source_label: string;
  from_projection_sha256: string;
  to_projection_sha256: string;
  before_text: string;
  after_text: string;
  created_at: string;
  operation_id: string;
}
export interface DocumentChangesPage {
  items: DocumentChange[];
  next_before_change_id: number | null;
}
export function parseDocumentChanges(
  value: unknown,
  documentId: number
): DocumentChangesPage {
  const fail = (): never => {
    throw new Error('DOCUMENT_CHANGES_RESPONSE_INVALID');
  };
  const record = (input: unknown): Record<string, unknown> => {
    if (!input || typeof input !== 'object' || Array.isArray(input))
      return fail();
    return input as Record<string, unknown>;
  };
  const integer = (input: unknown, min = 1): input is number =>
    typeof input === 'number' && Number.isSafeInteger(input) && input >= min;
  const digest = (input: unknown) =>
    typeof input === 'string' && /^[a-f0-9]{64}$/.test(input);
  const page = record(value);
  if (
    !Array.isArray(page.items) ||
    page.items.length > 100 ||
    (page.next_before_change_id !== null &&
      !integer(page.next_before_change_id))
  )
    return fail();
  const items = page.items.map((input) => {
    const row = record(input);
    if (
      !integer(row.change_id) ||
      row.document_id !== documentId ||
      !integer(row.state_seq, 0) ||
      ![
        'solo.save',
        'version.restore',
        'suggestion.accept',
        'collaboration.update',
      ].includes(String(row.kind)) ||
      (row.actor_member_id !== null && !integer(row.actor_member_id)) ||
      typeof row.source_label !== 'string' ||
      !digest(row.from_projection_sha256) ||
      !digest(row.to_projection_sha256) ||
      ['before_text', 'after_text', 'created_at', 'operation_id'].some(
        (key) => typeof row[key] !== 'string'
      )
    )
      return fail();
    return input as DocumentChange;
  });
  if (
    items.some(
      (item, i) => i > 0 && item.change_id >= items[i - 1].change_id
    ) ||
    (page.next_before_change_id !== null &&
      page.next_before_change_id !== items[items.length - 1]?.change_id)
  )
    return fail();
  return {
    items,
    next_before_change_id: page.next_before_change_id as number | null,
  };
}

/** Readable candidate summary only; full formatting is reviewed in the native readers. */
export function suggestionText(value: RichTextDocumentValue): string {
  const visit = (node: RichTextDocumentValue['content']): string => {
    if (node.type === 'text') return node.text ?? '';
    if (node.type === 'hardBreak') return '\n';
    if (['image', 'audio', 'video', 'file', 'media'].includes(node.type ?? ''))
      return `[素材：${
        node.attrs?.title || node.attrs?.alt || node.attrs?.fileKey || node.type
      }]`;
    const children = (node.content ?? []).map(visit);
    return children.join(
      [
        'doc',
        'blockquote',
        'bulletList',
        'orderedList',
        'listItem',
        'table',
        'tableRow',
      ].includes(node.type ?? '')
        ? '\n'
        : ''
    );
  };
  return visit(value.content);
}

export function suggestionTextChange(before: string, after: string) {
  const left = Array.from(before);
  const right = Array.from(after);
  let start = 0;
  while (
    start < left.length &&
    start < right.length &&
    left[start] === right[start]
  )
    start += 1;
  let end = 0;
  while (
    end < left.length - start &&
    end < right.length - start &&
    left[left.length - end - 1] === right[right.length - end - 1]
  )
    end += 1;
  return {
    prefix: left.slice(0, start).join(''),
    removed: left.slice(start, left.length - end).join(''),
    added: right.slice(start, right.length - end).join(''),
    suffix: end ? left.slice(left.length - end).join('') : '',
  };
}
