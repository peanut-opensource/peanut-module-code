// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { parseRichTextDocument } from '../src/document';
import { previewRichTextHtml } from '../src/editor';

describe('explicit HTML import preview', () => {
  it('returns structured blocks and marks without changing the source', () => {
    const source =
      '<h2>Title</h2><p>Hello <strong>bold</strong> <em>italic</em><br><u>underlined</u></p><blockquote><p>Quote</p></blockquote><ul><li><p>Item</p></li></ul><pre><code>a  b\nc</code></pre><hr>';
    const preview = previewRichTextHtml(source);
    expect(preview.ok).toBe(true);
    if (!preview.ok) return;
    expect(parseRichTextDocument(preview.document)).toEqual(preview.document);
    expect(preview.document.content.content?.map((node) => node.type)).toEqual([
      'heading',
      'paragraph',
      'blockquote',
      'bulletList',
      'codeBlock',
      'horizontalRule',
    ]);
    expect(preview.document.content.content?.[1].content?.[1].marks).toEqual([
      { type: 'bold' },
    ]);
  });

  it('preserves safe links and ordered list start', () => {
    const preview = previewRichTextHtml(
      '<ol start="3"><li><p><a href="https://example.com/a?x=1&amp;y=2">Link</a></p></li></ol>'
    );
    expect(preview, JSON.stringify(preview)).toMatchObject({ ok: true });
    if (!preview.ok) return;
    expect(preview.document.content.content?.[0].attrs?.start).toBe(3);
    expect(
      preview.document.content.content?.[0].content?.[0].content?.[0]
        .content?.[0].marks?.[0].attrs?.href
    ).toBe('https://example.com/a?x=1&y=2');
  });

  it.each([
    '<img src="https://example.com/image.png">',
    '<video src="https://example.com/video.mp4"></video>',
    '<iframe src="https://example.com"></iframe>',
    '<script>alert(1)</script>',
    '<p onclick="alert(1)">Text</p>',
    '<html><body onload="alert(1)"><p>Text</p></body></html>',
    '<p style="text-align:center">Text</p>',
    '<span style="color:red">Text</span>',
    '<custom-element>Text</custom-element>',
    '<a href="javascript:alert(1)">Link</a>',
    '<p><a href="https:example.com">Link</a></p>',
    '<p><a href="/storage/delivery/example">Link</a></p>',
    '<p><a href="https://example.com" title="lost">Link</a></p>',
    '<table><tr><td colspan="2"><p>Cell</p></td></tr></table>',
    '<p><strong></strong>Empty mark</p>',
    'unwrapped text',
    '',
  ])('blocks unsupported or lossy input %s', (html) => {
    const preview = previewRichTextHtml(html);
    expect(preview.ok).toBe(false);
    expect(preview.limitations.length).toBeGreaterThan(0);
    expect(preview).not.toHaveProperty('document');
  });

  it('preserves whitespace and entities inside paragraph text', () => {
    const preview = previewRichTextHtml('<p> a  b &amp; &lt;x&gt; </p>');
    expect(preview.ok).toBe(true);
    if (!preview.ok) return;
    expect(preview.document.content.content?.[0].content?.[0].text).toBe(
      ' a  b & <x> '
    );
  });

  it('accepts adjacent equivalent inline marks without losing their text', () => {
    const preview = previewRichTextHtml(
      '<p><strike>S</strike><del>D</del></p>'
    );
    expect(preview, JSON.stringify(preview)).toMatchObject({ ok: true });
    if (!preview.ok) return;
    expect(preview.document.content.content?.[0].content).toEqual([
      { type: 'text', text: 'SD', marks: [{ type: 'strike' }] },
    ]);
  });

  it('retains block boundaries and different link destinations', () => {
    const preview = previewRichTextHtml(
      '<p><b>A</b></p><p><strong>B</strong><a href="https://one.invalid">C</a><a href="https://two.invalid">D</a></p>'
    );
    expect(preview, JSON.stringify(preview)).toMatchObject({ ok: true });
    if (!preview.ok) return;
    const paragraphs = preview.document.content.content;
    expect(paragraphs?.length).toBe(2);
    expect(paragraphs?.[0].content?.[0].text).toBe('A');
    expect(paragraphs?.[1].content?.map((node) => node.text)).toEqual([
      'B',
      'C',
      'D',
    ]);
    expect(
      paragraphs?.[1].content
        ?.slice(1)
        .map((node) => node.marks?.[0].attrs?.href)
    ).toEqual(['https://one.invalid', 'https://two.invalid']);
  });
});
