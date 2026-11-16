import DOMPurify from 'dompurify';

const sanitizeRichText = (html: string | null | undefined): string =>
  DOMPurify.sanitize(html ?? '');

export default sanitizeRichText;
