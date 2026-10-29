import type { JSONContent } from '@tiptap/core';

export const DOCUMENT_VERSION = 'peanut.richtext/1' as const;
export interface RichTextDocumentValue {
  schemaVersion: typeof DOCUMENT_VERSION;
  editorModel: 'tiptap-prosemirror';
  content: JSONContent;
  annotations: [];
}
export const emptyDocument = (): RichTextDocumentValue => ({
  schemaVersion: DOCUMENT_VERSION,
  editorModel: 'tiptap-prosemirror',
  content: { type: 'doc', content: [{ type: 'paragraph' }] },
  annotations: [],
});
const fail = (): never => {
  throw new Error('RICH_TEXT_PROJECTION_INVALID');
};
const record = (value: unknown): Record<string, unknown> => {
  if (!value || typeof value !== 'object' || Array.isArray(value))
    return fail();
  return value as Record<string, unknown>;
};
const keys = (value: Record<string, unknown>, allowed: readonly string[]) => {
  if (Object.keys(value).some((key) => !allowed.includes(key))) fail();
};
const bytes = (value: string) => new TextEncoder().encode(value).length;
export const validLink = (value: unknown): value is string => {
  if (
    typeof value !== 'string' ||
    bytes(value) > 2048 ||
    Array.from(value).some((character) => character.charCodeAt(0) <= 32) ||
    value.includes('/storage/delivery')
  )
    return false;
  try {
    const url = new URL(value);
    // Require the host in the persisted input, before WHATWG URL repair.
    const rawAuthority = /^[a-z][a-z0-9+.-]*:\/\/([^/?#]+)/i.exec(value);
    return (
      ['http:', 'https:', 'mailto:'].includes(url.protocol) &&
      (!['http:', 'https:'].includes(url.protocol) ||
        (rawAuthority !== null &&
          !rawAuthority[1].includes('\\') &&
          url.hostname !== ''))
    );
  } catch {
    return false;
  }
};
const inline = ['text', 'hardBreak'];
const media = ['image', 'video', 'audio', 'file', 'media'];
const blocks = [
  'paragraph',
  'heading',
  'blockquote',
  'bulletList',
  'orderedList',
  'codeBlock',
  'horizontalRule',
  'table',
  ...media,
];
const required = [
  'doc',
  'blockquote',
  'bulletList',
  'orderedList',
  'listItem',
  'table',
  'tableRow',
  'tableCell',
  'tableHeader',
];
/** Single browser projection boundary; mirrors the public PHP RichTextProjection schema.
 * Invalid/legacy references are rejected and displayed as placeholders, never rewritten or saved.
 */
export const parseRichTextDocument = (
  input: unknown
): RichTextDocumentValue => {
  const value = record(input);
  keys(value, ['schemaVersion', 'editorModel', 'content', 'annotations']);
  if (
    value.schemaVersion !== DOCUMENT_VERSION ||
    value.editorModel !== 'tiptap-prosemirror' ||
    !Array.isArray(value.annotations) ||
    value.annotations.length ||
    record(value.content).type !== 'doc'
  )
    fail();
  let count = 0;
  const references = new Set<string>();
  const visit = (inputNode: unknown, depth: number): void => {
    count += 1;
    if (count > 50000 || depth > 60) fail();
    const node = record(inputNode);
    keys(node, ['type', 'content', 'text', 'attrs', 'marks']);
    if (typeof node.type !== 'string') fail();
    const type = node.type as string;
    if (
      ![
        'doc',
        'tableRow',
        'tableCell',
        'tableHeader',
        'listItem',
        ...inline,
        ...blocks,
      ].includes(type)
    )
      fail();
    if (
      node.text !== undefined &&
      (type !== 'text' || typeof node.text !== 'string')
    )
      fail();
    const attrs = node.attrs === undefined ? {} : record(node.attrs);
    if (media.includes(type)) {
      keys(attrs, ['fileKey', 'kind', 'variantKey', 'alt', 'title']);
      if (
        typeof attrs.fileKey !== 'string' ||
        !/^file_[0-9a-f]{32}$/.test(attrs.fileKey) ||
        typeof attrs.kind !== 'string' ||
        !['image', 'video', 'audio', 'file'].includes(attrs.kind)
      )
        fail();
      if (
        attrs.variantKey !== undefined &&
        attrs.variantKey !== null &&
        (typeof attrs.variantKey !== 'string' ||
          !/^[a-z][a-z0-9-]{0,31}$/.test(attrs.variantKey))
      )
        fail();
      ['alt', 'title'].forEach((field) => {
        if (
          attrs[field] !== undefined &&
          attrs[field] !== null &&
          (typeof attrs[field] !== 'string' ||
            bytes(attrs[field] as string) > 1000)
        )
          fail();
      });
      references.add(attrs.fileKey as string);
    } else {
      const allowed: Record<string, string[]> = {
        heading: ['level', 'textAlign'],
        paragraph: ['textAlign'],
        orderedList: ['start'],
        codeBlock: ['language'],
        tableCell: ['colspan', 'rowspan', 'colwidth'],
        tableHeader: ['colspan', 'rowspan', 'colwidth'],
      };
      keys(attrs, allowed[type] ?? []);
      Object.entries(attrs).forEach(([name, val]) => {
        let valid = false;
        switch (name) {
          case 'level':
            valid =
              Number.isInteger(val) && Number(val) >= 1 && Number(val) <= 6;
            break;
          case 'textAlign':
            valid =
              val === null ||
              (typeof val === 'string' &&
                ['left', 'right', 'center', 'justify'].includes(val));
            break;
          case 'start':
            valid =
              Number.isInteger(val) &&
              Number(val) >= 1 &&
              Number(val) <= 1000000;
            break;
          case 'language':
            valid =
              val === null ||
              (typeof val === 'string' && /^[a-zA-Z0-9_+.-]{0,64}$/.test(val));
            break;
          case 'colspan':
          case 'rowspan':
            valid =
              Number.isInteger(val) && Number(val) >= 1 && Number(val) <= 100;
            break;
          case 'colwidth':
            valid =
              val === null ||
              (Array.isArray(val) &&
                val.length <= 100 &&
                val.every((n) => Number.isInteger(n) && n >= 1 && n <= 10000));
            break;
          default:
            valid = false;
        }
        if (!valid) fail();
      });
    }
    const marks = node.marks ?? [];
    if (!Array.isArray(marks)) fail();
    (marks as unknown[]).forEach((inputMark) => {
      const mark = record(inputMark);
      keys(mark, ['type', 'attrs']);
      if (
        typeof mark.type !== 'string' ||
        ![
          'bold',
          'italic',
          'strike',
          'underline',
          'code',
          'link',
          'textStyle',
        ].includes(String(mark.type))
      )
        fail();
      const markAttrs = mark.attrs === undefined ? {} : record(mark.attrs);
      if (mark.type === 'link') {
        keys(markAttrs, ['href', 'target', 'rel', 'class']);
        if (
          !validLink(markAttrs.href) ||
          (markAttrs.target !== undefined &&
            markAttrs.target !== null &&
            (typeof markAttrs.target !== 'string' ||
              !['_blank', '_self'].includes(markAttrs.target))) ||
          (markAttrs.rel !== undefined &&
            markAttrs.rel !== null &&
            (typeof markAttrs.rel !== 'string' ||
              !['noopener noreferrer', 'noopener noreferrer nofollow'].includes(
                markAttrs.rel
              ))) ||
          (markAttrs.class !== undefined && markAttrs.class !== null)
        )
          fail();
      } else if (mark.type === 'textStyle') {
        keys(markAttrs, ['color']);
        if (
          markAttrs.color !== undefined &&
          markAttrs.color !== null &&
          (typeof markAttrs.color !== 'string' ||
            !/^#[0-9a-fA-F]{3,8}$/.test(markAttrs.color))
        )
          fail();
      } else if (Object.keys(markAttrs).length) fail();
    });
    const rawChildren = node.content ?? [];
    if (!Array.isArray(rawChildren)) fail();
    const children = rawChildren as unknown[];
    if (
      (required.includes(type) && !children.length) ||
      (type === 'text' &&
        (typeof node.text !== 'string' ||
          node.text === '' ||
          children.length)) ||
      (type === 'listItem' && record(children[0]).type !== 'paragraph') ||
      (!inline.includes(type) && (marks as unknown[]).length)
    )
      fail();
    let allowed: string[] = [];
    if (
      ['doc', 'blockquote', 'tableCell', 'tableHeader', 'listItem'].includes(
        type
      )
    )
      allowed = blocks;
    else if (['paragraph', 'heading'].includes(type)) allowed = inline;
    else if (type === 'codeBlock') allowed = ['text'];
    else if (['bulletList', 'orderedList'].includes(type))
      allowed = ['listItem'];
    else if (type === 'table') allowed = ['tableRow'];
    else if (type === 'tableRow') allowed = ['tableCell', 'tableHeader'];
    children.forEach((child) => {
      const entry = record(child);
      if (
        !allowed.includes(String(entry.type)) ||
        (type === 'codeBlock' &&
          Array.isArray(entry.marks) &&
          entry.marks.length)
      )
        fail();
      visit(child, depth + 1);
    });
  };
  visit(value.content, 0);
  if (references.size > 1000 || bytes(JSON.stringify(value)) > 2 * 1024 * 1024)
    fail();
  return JSON.parse(JSON.stringify(value)) as RichTextDocumentValue;
};
