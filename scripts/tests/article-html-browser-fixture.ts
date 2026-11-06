import { previewRichTextHtml } from '../../web/src/modules/peanut-rich-text/src/editor';
import { parseRichTextDocument } from '../../web/src/modules/peanut-rich-text/src/document';
import type { JSONContent } from '@tiptap/core';

const cases: { name: string; passed: boolean; assertions: number; error?: string }[] = [];
let assertions = 0;
function assert(value: unknown, message: string): asserts value {
  assertions += 1;
  if (!value) throw new Error(message);
}
const equal = (actual: unknown, expected: unknown) =>
  assert(JSON.stringify(actual) === JSON.stringify(expected), `Expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
const run = (name: string, body: () => void) => {
  const before = assertions;
  try {
    body();
    cases.push({ name, passed: true, assertions: assertions - before });
  } catch (error) {
    cases.push({ name, passed: false, assertions: assertions - before, error: String(error) });
  }
};
const accepted = (html: string) => {
  const preview = previewRichTextHtml(html);
  assert(preview.ok, JSON.stringify(preview));
  equal(preview.limitations, []);
  equal(parseRichTextDocument(preview.document), preview.document);
  return preview.document.content;
};
const nodes = (node: JSONContent): JSONContent[] => [node, ...(node.content ?? []).flatMap(nodes)];
const text = (node: JSONContent) => nodes(node).map((item) => item.text ?? '').join('');
const reject = (name: string, html: string, code: string) => run(name, () => {
  const preview = previewRichTextHtml(html);
  equal(preview.ok, false);
  assert(!('document' in preview), 'Rejected input must not expose an applicable document');
  equal(preview.limitations.map((item) => item.code), [code]);
  assert(preview.limitations.every((item) => item.message.length > 0), 'Missing refusal reason');
});

run('original-01 semantic blocks and marks', () => {
  const doc = accepted('<h2>Title</h2><p>Hello <strong>bold</strong> <em>italic</em><br><u>underlined</u></p><blockquote><p>Quote</p></blockquote><ul><li><p>Item</p></li></ul><pre><code>a  b\nc</code></pre><hr>');
  equal(doc.content?.map((node) => node.type), ['heading', 'paragraph', 'blockquote', 'bulletList', 'codeBlock', 'horizontalRule']);
  equal(doc.content?.[0].attrs?.level, 2);
  equal(text(doc), 'TitleHello bold italicunderlinedQuoteItema  b\nc');
  for (const [value, mark] of [['bold', 'bold'], ['italic', 'italic'], ['underlined', 'underline']])
    equal(nodes(doc).find((node) => node.text === value)?.marks, [{ type: mark }]);
  equal(nodes(doc).filter((node) => node.type === 'hardBreak').length, 1);
});
run('original-02 safe link and ordered start', () => {
  const doc = accepted('<ol start="3"><li><p><a href="https://example.com/a?x=1&amp;y=2">Link</a></p></li></ol>');
  equal(doc.content?.[0].attrs?.start, 3);
  equal(text(doc), 'Link');
  equal(nodes(doc).find((node) => node.text === 'Link')?.marks, [{ type: 'link', attrs: { href: 'https://example.com/a?x=1&y=2', target: '_blank', rel: 'noopener noreferrer', class: null } }]);
});
const originalRejected: [string, string, string][] = [
  ['image', '<img src="https://example.com/image.png">', 'HTML_NODE_UNSUPPORTED'],
  ['video', '<video src="https://example.com/video.mp4"></video>', 'HTML_NODE_UNSUPPORTED'],
  ['iframe', '<iframe src="https://example.com"></iframe>', 'HTML_NODE_UNSUPPORTED'],
  ['script', '<script>alert(1)</script>', 'HTML_NODE_UNSUPPORTED'],
  ['event', '<p onclick="alert(1)">Text</p>', 'HTML_ATTRIBUTE_UNSUPPORTED'],
  ['wrapper event', '<html><body onload="alert(1)"><p>Text</p></body></html>', 'HTML_SANITIZATION_LOSS'],
  ['style', '<p style="text-align:center">Text</p>', 'HTML_ATTRIBUTE_UNSUPPORTED'],
  ['styled span', '<span style="color:red">Text</span>', 'HTML_NODE_UNSUPPORTED'],
  ['custom element', '<custom-element>Text</custom-element>', 'HTML_NODE_UNSUPPORTED'],
  ['javascript', '<a href="javascript:alert(1)">Link</a>', 'HTML_LINK_UNSAFE'],
  ['malformed https', '<p><a href="https:example.com">Link</a></p>', 'HTML_LINK_UNSAFE'],
  ['relative delivery', '<p><a href="/storage/delivery/example">Link</a></p>', 'HTML_LINK_UNSAFE'],
  ['link title', '<p><a href="https://example.com" title="lost">Link</a></p>', 'HTML_ATTRIBUTE_UNSUPPORTED'],
  ['table colspan', '<table><tr><td colspan="2"><p>Cell</p></td></tr></table>', 'HTML_ATTRIBUTE_UNSUPPORTED'],
  ['empty mark', '<p><strong></strong>Empty mark</p>', 'HTML_NORMALIZATION_LOSS'],
  ['unwrapped text', 'unwrapped text', 'HTML_NORMALIZATION_LOSS'],
  ['empty', '', 'HTML_EMPTY'],
];
originalRejected.forEach(([name, html, code], index) => reject(`original-${String(index + 3).padStart(2, '0')} ${name}`, html, code));
run('original-20 whitespace and entities', () => {
  const doc = accepted('<p> a  b &amp; &lt;x&gt; </p>');
  equal(text(doc), ' a  b & <x> ');
  equal(doc.content?.[0].content?.[0].text, ' a  b & <x> ');
});
for (const href of ['http://example.com/path', 'https://example.com/path', 'mailto:reader@example.com'])
  run(`safe URL ${href}`, () => {
    const doc = accepted(`<p><a href="${href}" target="_self" rel="noopener noreferrer nofollow">Read</a></p>`);
    equal(text(doc), 'Read');
    equal(nodes(doc).find((node) => node.text === 'Read')?.marks?.[0].attrs, { href, target: '_self', rel: 'noopener noreferrer nofollow', class: null });
  });
run('equivalent format aliases retain every mark', () => {
  const doc = accepted('<p><b>B</b><i>I</i><strike>S</strike><del>D</del><u>U</u><code>C</code></p>');
  equal(text(doc), 'BISDUC');
  for (const [value, mark] of [['B', 'bold'], ['I', 'italic'], ['SD', 'strike'], ['U', 'underline'], ['C', 'code']])
    equal(nodes(doc).find((node) => node.text === value)?.marks, [{ type: mark }]);
});
for (const start of [1, 1000000]) run(`list start ${start}`, () => {
  const doc = accepted(`<ol start="${start}"><li><p>One</p></li><li><p>Two</p></li></ol>`);
  equal(doc.content?.[0].attrs?.start, start);
  equal(doc.content?.[0].content?.length, 2);
  equal(text(doc), 'OneTwo');
});
for (const href of ['javascript:alert(1)', 'java&#x73;cript:alert(1)', 'data:text/html,unsafe', 'file:///etc/passwd', '//example.com', '#anchor'])
  reject(`unsafe URL ${href}`, `<p><a href="${href}">Keep me</a></p>`, 'HTML_LINK_UNSAFE');
for (const [name, html, code] of [
  ['audio', '<p>Keep</p><audio src="https://example.com/a"></audio>', 'HTML_NODE_UNSUPPORTED'],
  ['svg', '<svg><text>Keep</text></svg>', 'HTML_NODE_UNSUPPORTED'],
  ['style element', '<style>p{color:red}</style><p>Keep</p>', 'HTML_NODE_UNSUPPORTED'],
  ['comment', '<p>Keep</p><!-- preserve -->', 'HTML_NODE_UNSUPPORTED'],
  ['table rowspan', '<table><tr><td rowspan="2"><p>Keep</p></td></tr></table>', 'HTML_ATTRIBUTE_UNSUPPORTED'],
  ['table width', '<table width="100"><tr><td><p>Keep</p></td></tr></table>', 'HTML_ATTRIBUTE_UNSUPPORTED'],
  ['table bare text', '<table><tr><td>Keep</td></tr></table>', 'HTML_NORMALIZATION_LOSS'],
  ['invalid nesting', '<p><strong><p>Keep</p></strong></p>', 'HTML_NORMALIZATION_LOSS'],
  ['unsafe target', '<p><a href="https://example.com" target="frame">Keep</a></p>', 'HTML_LINK_UNSAFE'],
  ['unsafe rel', '<p><a href="https://example.com" rel="opener">Keep</a></p>', 'HTML_LINK_UNSAFE'],
  ['zero start', '<ol start="0"><li><p>Keep</p></li></ol>', 'HTML_ATTRIBUTE_UNSUPPORTED'],
  ['large start', '<ol start="1000001"><li><p>Keep</p></li></ol>', 'HTML_ATTRIBUTE_UNSUPPORTED'],
  ['deep input', '<blockquote>'.repeat(61) + '<p>Keep</p>' + '</blockquote>'.repeat(61), 'HTML_TOO_COMPLEX'],
  ['many nodes', '<p>x</p>'.repeat(25001), 'HTML_TOO_COMPLEX'],
  ['large UTF8', '<p>' + '中'.repeat(700000) + '</p>', 'HTML_TOO_LARGE'],
]) reject(name, html, code);
run('semantic table preserves cells and text', () => {
  const doc = accepted('<table><tbody><tr><th><p>Head</p></th><td><p>Cell</p></td></tr></tbody></table>');
  equal(text(doc), 'HeadCell');
  equal(doc.content?.[0].type, 'table');
  equal(doc.content?.[0].content?.[0].content?.map((node) => node.type), ['tableHeader', 'tableCell']);
});
const marker = document.createElement('pre');
marker.id = '007c-browser-result';
marker.textContent = JSON.stringify({ completed: true, cases, assertions, passed: cases.filter((row) => row.passed).length, failed: cases.filter((row) => !row.passed).length, userAgent: navigator.userAgent });
document.body.append(marker);
