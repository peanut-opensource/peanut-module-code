/**
 * Trigger a real PNG download from a rendered ECharts instance.
 * The gallery previously clicked a detached anchor with a data: URL. In the
 * embedded browser this produced no download event although canvas was ready.
 * Match other working exports: use an object URL and a connected anchor.
 */
export function downloadChartPng(
  chart: { getDataURL(options: { type: 'png'; pixelRatio: number }): string },
  documentRef: Document = document,
  urlApi: Pick<typeof URL, 'createObjectURL' | 'revokeObjectURL'> = URL
): void {
  const imageUrl = chart.getDataURL({ type: 'png', pixelRatio: 2 });
  const prefix = 'data:image/png;base64,';
  if (typeof imageUrl !== 'string' || !imageUrl.startsWith(prefix))
    throw new Error('CHART_PNG_DATA_INVALID');

  const binary = atob(imageUrl.slice(prefix.length));
  if (binary.length === 0) throw new Error('CHART_PNG_DATA_EMPTY');
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);

  const objectUrl = urlApi.createObjectURL(
    new Blob([bytes], { type: 'image/png' })
  );
  const link = documentRef.createElement('a');
  link.href = objectUrl;
  link.download = 'chart-example.png';
  documentRef.body.appendChild(link);
  try {
    link.click();
  } finally {
    link.remove();
    // Download dialogs and embedded browser download observers can consume the
    // object URL asynchronously. Do not revoke it in the same click stack.
    setTimeout(() => urlApi.revokeObjectURL(objectUrl), 30_000);
  }
}
