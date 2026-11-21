let activeFrame: HTMLIFrameElement | undefined;

/** Print trusted, rendered content in a document isolated from the workspace. */
export default async function printContent(content: HTMLElement) {
  const frame = document.createElement('iframe');
  frame.title = '打印文档';
  frame.setAttribute('aria-hidden', 'true');
  frame.setAttribute('sandbox', 'allow-same-origin allow-modals');
  frame.tabIndex = -1;
  Object.assign(frame.style, {
    position: 'fixed',
    bottom: '0',
    right: '0',
    width: `${Math.max(content.clientWidth, 1)}px`,
    height: '1px',
    border: '0',
    opacity: '0',
    pointerEvents: 'none',
  });
  activeFrame?.remove();
  activeFrame = frame;
  document.body.append(frame);

  const cleanup = () => {
    frame.remove();
    if (activeFrame === frame) activeFrame = undefined;
  };
  try {
    const target = frame.contentDocument;
    const preview = frame.contentWindow;
    if (!target || !preview) throw new Error('无法创建打印文档');

    target.title = document.title;
    for (const [source, destination] of [
      [document.documentElement, target.documentElement],
      [document.body, target.body],
    ]) {
      destination.className = source.className;
      const style = source.getAttribute('style');
      if (style) destination.setAttribute('style', style);
    }
    const base = target.createElement('base');
    base.href = document.baseURI;
    target.head.append(base);

    const pending = Array.from(
      document.querySelectorAll('style, link[rel~="stylesheet"]')
    ).map((source) => {
      const copy = target.importNode(source, true);
      const ready =
        copy.tagName === 'LINK'
          ? new Promise<void>((resolve) => {
              copy.addEventListener('load', () => resolve(), { once: true });
              copy.addEventListener('error', () => resolve(), { once: true });
            })
          : Promise.resolve();
      target.head.append(copy);
      return ready;
    });
    const rules = target.createElement('style');
    rules.textContent = `
      @media print {
        html, body {
          width: auto !important;
          height: auto !important;
          overflow: visible !important;
        }
        body { margin: 0 !important; }
        thead { display: table-header-group; }
        tr, img { break-inside: avoid; }
      }
    `;
    target.head.append(rules);
    const copy = target.importNode(content, true);
    copy
      .querySelectorAll('iframe, object, embed')
      .forEach((element) => element.remove());
    const images = Array.from(copy.querySelectorAll('img'));
    images.forEach((element) => {
      element.loading = 'eager';
    });
    target.body.append(copy);

    let timeout: ReturnType<typeof setTimeout> | undefined;
    try {
      await Promise.race([
        Promise.all([
          ...pending,
          ...images.map((element) => element.decode().catch(() => undefined)),
        ]).then(() => {
          if (!frame.isConnected) return;
          // Apply the cloned styles before waiting for the fonts used by the content.
          copy.getBoundingClientRect();
          return target.fonts.ready;
        }),
        new Promise<void>((resolve) => {
          timeout = setTimeout(resolve, 3000);
        }),
      ]);
    } finally {
      clearTimeout(timeout);
    }
    if (!frame.isConnected) return;
    preview.addEventListener('afterprint', cleanup, { once: true });
    preview.focus();
    preview.print();
  } catch (error) {
    cleanup();
    throw error;
  }
}
