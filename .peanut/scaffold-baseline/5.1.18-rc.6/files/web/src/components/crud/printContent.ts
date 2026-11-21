/** Print trusted, already-rendered content with the host document's styles. */
export default async function printContent(content: HTMLElement) {
  const popup = window.open('', '_blank');
  if (!popup) return;

  const target = popup.document;
  target.title = document.title;
  target.documentElement.className = document.documentElement.className;
  target.body.className = document.body.className;
  [
    [document.documentElement, target.documentElement],
    [document.body, target.body],
  ].forEach(([source, destination]) => {
    const style = source.getAttribute('style');
    if (style) destination.setAttribute('style', style);
  });
  const base = target.createElement('base');
  base.href = document.baseURI;
  target.head.append(base);

  const styles = Array.from(
    document.querySelectorAll('style, link[rel~="stylesheet"]')
  );
  const pending = styles.map((source) => {
    const copy = source.cloneNode(true) as HTMLElement;
    if (copy.tagName !== 'LINK') {
      target.head.append(copy);
      return Promise.resolve();
    }
    return new Promise<void>((resolve) => {
      copy.addEventListener('load', () => resolve(), { once: true });
      copy.addEventListener('error', () => resolve(), { once: true });
      target.head.append(copy);
    });
  });
  target.body.append(content.cloneNode(true));
  let timeout: ReturnType<typeof setTimeout> | undefined;
  await Promise.race([
    Promise.all(pending),
    new Promise<void>((resolve) => {
      timeout = setTimeout(resolve, 3000);
    }),
  ]);
  if (timeout !== undefined) clearTimeout(timeout);
  popup.print();
}
