import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import {
  previewArticleHtml,
  migrateArticleHtml,
  ArticleHtmlMigrationRejected,
  type ArticleHtmlMigrationInput,
} from '../../api';
vi.mock('axios', () => ({
  default: { get: vi.fn(), post: vi.fn(), defaults: {} },
}));
const digest = 'a'.repeat(64);
const preview = {
  article_id: 7,
  source_html: '<p>Hello</p>',
  source_sha256: digest,
  owner_revision: digest,
};
const result = {
  article_id: 7,
  request_id: 'request',
  document_id: 9,
  state_seq: 1,
  source_sha256: digest,
  phase: 'completed',
  published: false,
};
const input: ArticleHtmlMigrationInput = {
  id: 7,
  request_id: 'request',
  confirmed: true,
  source_sha256: digest,
  owner_revision: digest,
  structured_content: {
    schemaVersion: 'peanut.richtext/1',
    editorModel: 'tiptap-prosemirror',
    content: { type: 'doc', content: [{ type: 'paragraph' }] },
    annotations: [],
  },
};
beforeEach(() => vi.clearAllMocks());
describe('Article HTML migration response boundary', () => {
  it('uses the explicit GET preview and POST migration endpoints', async () => {
    const signal = new AbortController().signal;
    vi.mocked(axios.get).mockResolvedValue({ data: preview });
    vi.mocked(axios.post).mockResolvedValue({ data: result });
    expect((await previewArticleHtml(7, signal)).data).toEqual(preview);
    expect((await migrateArticleHtml(input, signal)).data).toEqual(result);
    expect(axios.get).toHaveBeenCalledWith(
      '/adminapi/peanut.article.document.html-preview',
      { params: { id: 7 }, signal }
    );
    expect(axios.post).toHaveBeenCalledWith(
      '/adminapi/peanut.article.document.html-migrate',
      input,
      expect.objectContaining({ signal })
    );
  });
  it.each([
    null,
    [],
    { ...preview, article_id: '7' },
    { ...preview, source_html: null },
    { ...preview, source_sha256: 'abc' },
    { ...preview, owner_revision: [] },
  ])('rejects malformed preview %#', async (data) => {
    vi.mocked(axios.get).mockResolvedValue({ data });
    await expect(
      previewArticleHtml(7, new AbortController().signal)
    ).rejects.toThrow('ARTICLE_HTML_RESPONSE_INVALID');
  });
  it.each([
    null,
    [],
    { ...result, document_id: 0 },
    { ...result, request_id: 1 },
    { ...result, state_seq: 2 },
    { ...result, phase: 'pending' },
    { ...result, published: true },
    { ...result, source_sha256: null },
  ])('rejects malformed migration %#', async (data) => {
    vi.mocked(axios.post).mockResolvedValue({ data });
    await expect(
      migrateArticleHtml(input, new AbortController().signal)
    ).rejects.toThrow('ARTICLE_HTML_RESPONSE_INVALID');
  });
  it('preserves network errors for recovery', async () => {
    const error = new Error('network unknown');
    vi.mocked(axios.post).mockRejectedValue(error);
    await expect(
      migrateArticleHtml(input, new AbortController().signal)
    ).rejects.toBe(error);
  });
  it('retains the exact source-conflict rejection across the shared response transform', async () => {
    vi.mocked(axios.post).mockImplementationOnce(
      async (_path, _input, config) => {
        const transforms = config?.transformResponse;
        if (!Array.isArray(transforms))
          throw new Error('Expected response transforms');
        transforms.forEach((transform) =>
          transform.call(
            {},
            {
              code: 40900,
              data: { error_code: 'ARTICLE_HTML_SOURCE_CHANGED' },
            },
            {},
            409
          )
        );
        throw new Error('shared interceptor message');
      }
    );
    await expect(
      migrateArticleHtml(input, new AbortController().signal)
    ).rejects.toBeInstanceOf(ArticleHtmlMigrationRejected);
  });
});
