import { afterEach, describe, expect, it, vi } from 'vitest';
import printContent from '@/components/crud/printContent';

afterEach(() => {
  document.head.innerHTML = '';
  document.body.innerHTML = '';
  vi.restoreAllMocks();
  vi.useRealTimers();
});

describe('printContent', () => {
  it('copies rendered content and source styles before printing', async () => {
    const style = document.createElement('style');
    style.textContent = '.invoice { color: red }';
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'data:text/css,.invoice%7Bcolor%3Ared%7D';
    document.head.append(style, link);
    document.body.className = 'theme-dark';
    const content = document.createElement('section');
    const image = document.createElement('img');
    image.setAttribute('src', 'images/logo.png');
    content.append(image);
    const target = document.implementation.createHTMLDocument('');
    const print = vi.fn();
    vi.spyOn(window, 'open').mockReturnValue({
      document: target,
      print,
    } as Window);
    const pending = printContent(content);
    expect(print).not.toHaveBeenCalled();
    expect(target.head.querySelector('base')?.href).toBe(document.baseURI);
    expect(target.head.querySelector('style')?.textContent).toBe(
      style.textContent
    );
    expect(target.body.className).toBe('theme-dark');
    expect(target.body.querySelector('img')?.getAttribute('src')).toBe(
      'images/logo.png'
    );
    target.head.querySelector('link')?.dispatchEvent(new Event('load'));
    await pending;
    expect(print).toHaveBeenCalledOnce();
  });
});
