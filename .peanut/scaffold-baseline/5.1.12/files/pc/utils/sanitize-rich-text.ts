import DOMPurify from 'dompurify';

const sanitizeRichText = (html: string | null | undefined): string => {
  const value = html ?? '';
  return typeof window === 'undefined' ? '' : DOMPurify.sanitize(value);
};

const entity = /&(#x[0-9a-f]+|#\d+|amp|lt|gt|quot|apos|nbsp);/gi;

/** Safe, text-only SSR representation used before DOMPurify can run. */
export const richTextToPlainText = (html: string | null | undefined): string =>
  (html ?? '')
    .replace(/<(script|style|template)[^>]*>[\s\S]*?<\/\1\s*>/gi, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/<\s*br\s*\/?\s*>/gi, '\n')
    .replace(/<\/\s*(p|div|li|h[1-6])\s*>/gi, '\n')
    .replace(/<[^>]*>/g, ' ')
    .replace(entity, (match, value: string) => {
      const named: Record<string, string> = {
        amp: '&',
        lt: '<',
        gt: '>',
        quot: '"',
        apos: "'",
        nbsp: ' ',
      };
      const normalized = value.toLowerCase();
      if (normalized in named) return named[normalized];
      const radix = normalized.startsWith('#x') ? 16 : 10;
      const digits = normalized.replace(/^#x?/, '');
      const codePoint = Number.parseInt(digits, radix);
      return Number.isInteger(codePoint) &&
        codePoint > 0 &&
        codePoint <= 0x10ffff
        ? String.fromCodePoint(codePoint)
        : match;
    })
    .replace(/[\t ]+/g, ' ')
    .replace(/\n\s*\n+/g, '\n')
    .trim();

export default sanitizeRichText;
