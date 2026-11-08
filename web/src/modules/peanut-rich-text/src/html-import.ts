import { getSchema, type JSONContent } from '@tiptap/core';
import { DOMParser, DOMSerializer } from '@tiptap/pm/model';
import DOMPurify from 'dompurify';
import {
  emptyDocument,
  parseRichTextDocument,
  validLink,
  type RichTextDocumentValue,
} from './document';
import richTextExtensions from './extensions';

export interface RichTextHtmlImportLimitation {
  code: string;
  message: string;
}

export type RichTextHtmlImportPreview =
  | {
      ok: true;
      document: RichTextDocumentValue;
      limitations: [];
    }
  | {
      ok: false;
      limitations: RichTextHtmlImportLimitation[];
    };

const tags = [
  'p',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'blockquote',
  'ul',
  'ol',
  'li',
  'pre',
  'code',
  'hr',
  'br',
  'strong',
  'b',
  'em',
  'i',
  's',
  'strike',
  'del',
  'u',
  'a',
  'table',
  'tbody',
  'tr',
  'td',
  'th',
];
const attributes: Record<string, string[]> = {
  a: ['href', 'target', 'rel'],
  ol: ['start'],
};
const blocked = (code: string, message: string): RichTextHtmlImportPreview => ({
  ok: false,
  limitations: [{ code, message }],
});

// Normalize equivalent HTML spelling, adjacent identical inline marks and
// schema-owned default attributes. Block boundaries are never merged.
// Structural repairs, removed marks and text changes remain observable differences.
const canonical = (root: ParentNode): string => {
  const aliases: Record<string, string> = {
    b: 'strong',
    i: 'em',
    strike: 's',
    del: 's',
  };
  type CanonicalNode = string | [string, [string, string][], CanonicalNode[]];
  const inlineMarks = new Set(['strong', 'em', 's', 'u', 'a', 'code']);
  const mergeInline = (nodes: CanonicalNode[]): CanonicalNode[] => {
    const result: CanonicalNode[] = [];
    nodes.forEach((node) => {
      const previous = result[result.length - 1];
      if (typeof node === 'string' && typeof previous === 'string') {
        result[result.length - 1] = previous + node;
      } else if (
        Array.isArray(node) &&
        Array.isArray(previous) &&
        inlineMarks.has(node[0]) &&
        node[0] === previous[0] &&
        node[2].length > 0 &&
        previous[2].length > 0 &&
        JSON.stringify(node[1]) === JSON.stringify(previous[1])
      ) {
        previous[2] = mergeInline([...previous[2], ...node[2]]);
      } else {
        result.push(node);
      }
    });
    return result;
  };
  const children = (parent: ParentNode): CanonicalNode[] =>
    mergeInline(
      Array.from(parent.childNodes).flatMap((node): CanonicalNode[] => {
        if (node.nodeType === 3) return [node.textContent ?? ''];
        if (node.nodeType !== 1) return [];
        const element = node as Element;
        const tag = aliases[element.localName] ?? element.localName;
        if (tag === 'tbody') return children(element);
        const attrs = Object.fromEntries<string>(
          Array.from(element.attributes)
            .filter(
              (attr) =>
                !(
                  (tag === 'ol' &&
                    attr.name === 'start' &&
                    attr.value === '1') ||
                  (['td', 'th'].includes(tag) &&
                    ['colspan', 'rowspan'].includes(attr.name) &&
                    attr.value === '1')
                )
            )
            .map((attr): [string, string] => [attr.name, attr.value])
            .sort(([left], [right]) => left.localeCompare(right))
        );
        if (tag === 'a') {
          attrs.target ??= '_blank';
          attrs.rel ??= 'noopener noreferrer';
        }
        return [[tag, Object.entries(attrs).sort(), children(element)]];
      })
    );
  return JSON.stringify(children(root));
};

/** Explicit, browser-only preview. Never fetches media or modifies an editor.
 * The first import slice accepts unstyled semantic HTML only. All other input
 * needs an explicit source edit; sanitization never silently approves data loss.
 */
export const previewRichTextHtml = (
  html: string
): RichTextHtmlImportPreview => {
  if (typeof document === 'undefined')
    return blocked('HTML_BROWSER_REQUIRED', 'HTML 导入需要浏览器环境。');
  if (new TextEncoder().encode(html).length > 2 * 1024 * 1024)
    return blocked('HTML_TOO_LARGE', 'HTML 超过 2 MiB，无法导入。');
  if (!html.trim()) return blocked('HTML_EMPTY', '请输入需要预览的 HTML。');

  // Template contents stay inert and detached, including rejected media/scripts.
  const template = document.createElement('template');
  template.innerHTML = html;
  const pending = Array.from(template.content.childNodes).map((node) => ({
    node,
    depth: 1,
  }));
  let count = 0;
  while (pending.length) {
    const current = pending.pop();
    if (!current) break;
    count += 1;
    if (count > 50000 || current.depth > 60)
      return blocked('HTML_TOO_COMPLEX', 'HTML 节点数量或嵌套深度超过限制。');
    if (current.node.nodeType !== 3) {
      if (current.node.nodeType !== 1)
        return blocked(
          'HTML_NODE_UNSUPPORTED',
          'HTML 注释或其他非文本节点不在本次导入范围。'
        );
      const element = current.node as Element;
      const tag = element.localName;
      if (
        element.namespaceURI !== 'http://www.w3.org/1999/xhtml' ||
        !tags.includes(tag)
      )
        return blocked(
          'HTML_NODE_UNSUPPORTED',
          `暂不支持 <${tag}>；媒体需通过受控文件选择器添加。`
        );
      const unsupported = Array.from(element.attributes).find(
        (attr) => !(attributes[tag] ?? []).includes(attr.name)
      );
      if (unsupported)
        return blocked(
          'HTML_ATTRIBUTE_UNSUPPORTED',
          `<${tag}> 的 ${unsupported.name} 属性不在本次导入范围；样式与媒体属性不会自动丢弃。`
        );
      if (
        tag === 'a' &&
        (!validLink(element.getAttribute('href')) ||
          (element.hasAttribute('target') &&
            !['_blank', '_self'].includes(
              element.getAttribute('target') ?? ''
            )) ||
          (element.hasAttribute('rel') &&
            !['noopener noreferrer', 'noopener noreferrer nofollow'].includes(
              element.getAttribute('rel') ?? ''
            )))
      )
        return blocked(
          'HTML_LINK_UNSAFE',
          '链接必须是有效的 HTTP、HTTPS 或 mailto 地址，并使用支持的 target / rel。'
        );
      if (tag === 'ol' && element.hasAttribute('start')) {
        const start = element.getAttribute('start') ?? '';
        if (!/^[1-9][0-9]*$/.test(start) || Number(start) > 1000000)
          return blocked(
            'HTML_ATTRIBUTE_UNSUPPORTED',
            '有序列表 start 必须为 1 至 1000000 的整数。'
          );
      }
      if (count + pending.length + element.childNodes.length > 50000)
        return blocked('HTML_TOO_COMPLEX', 'HTML 节点数量或嵌套深度超过限制。');
      Array.from(element.childNodes).forEach((node) =>
        pending.push({ node, depth: current.depth + 1 })
      );
    }
  }

  const sanitizedDocument = DOMPurify.sanitize(html, {
    ALLOWED_TAGS: tags,
    ALLOWED_ATTR: ['href', 'target', 'rel', 'start'],
    ALLOW_DATA_ATTR: false,
    ALLOW_ARIA_ATTR: false,
    // Inspect wrapper attributes too: fragment parsing omits <html>/<body>.
    WHOLE_DOCUMENT: true,
    RETURN_DOM: true,
  });
  const sanitized = (sanitizedDocument as Element).querySelector('body');
  if (
    !sanitized ||
    DOMPurify.removed.length ||
    canonical(template.content) !== canonical(sanitized)
  )
    return blocked(
      'HTML_SANITIZATION_LOSS',
      '安全净化会修改 HTML，请先移除不受支持的内容。'
    );

  const schema = getSchema(
    richTextExtensions({ callbacks: {}, contextKey: 'html-import-preview' })
  );
  const parsed = DOMParser.fromSchema(schema).parse(sanitized, {
    preserveWhitespace: 'full',
  });
  const rendered = DOMSerializer.fromSchema(schema).serializeFragment(
    parsed.content
  );
  if (canonical(sanitized) !== canonical(rendered))
    return blocked(
      'HTML_NORMALIZATION_LOSS',
      '当前编辑模型会调整结构、文本或格式；请改用标准段落、列表及基础文本标记后重试。'
    );
  try {
    const content: JSONContent = parsed.toJSON();
    const nodesToVisit = [content];
    while (nodesToVisit.length) {
      const node = nodesToVisit.pop();
      if (node) {
        // Tiptap adds a null list-style default absent from the public projection.
        // Explicit HTML list styles are rejected above; no source format is removed.
        if (node.type === 'orderedList' && node.attrs?.type === null)
          delete node.attrs.type;
        nodesToVisit.push(...(node.content ?? []));
      }
    }
    const value = parseRichTextDocument({
      ...emptyDocument(),
      content,
    });
    return { ok: true, document: value, limitations: [] };
  } catch {
    return blocked(
      'HTML_PROJECTION_INVALID',
      '转换结果不符合当前富文本结构合同，无法应用。'
    );
  }
};
