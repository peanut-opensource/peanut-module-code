import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { downloadChartPng } from '../src/png-download.ts';

describe('ChartExamples PNG download', () => {
  it('converts the ECharts PNG into a Blob object URL and clicks an attached anchor', async () => {
    const png = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
    let actualBlob;
    let clicked;
    let removed = false;
    let deferredRevoke;
    const fakeUrl = {
      createObjectURL(blob) {
        actualBlob = blob;
        return 'blob:http://localhost/chart-download';
      },
      revokeObjectURL(url) {
        assert.equal(url, 'blob:http://localhost/chart-download');
      },
    };
    const link = {
      isConnected: false,
      href: '',
      download: '',
      click() {
        assert.equal(this.isConnected, true);
        clicked = { href: this.href, download: this.download };
      },
      remove() {
        removed = true;
        this.isConnected = false;
      },
    };
    const fakeDocument = {
      createElement(tag) {
        assert.equal(tag, 'a');
        return link;
      },
      body: {
        appendChild(node) {
          assert.equal(node, link);
          link.isConnected = true;
        },
      },
    };
    const originalTimer = globalThis.setTimeout;
    globalThis.setTimeout = (callback, ms) => {
      assert.equal(ms, 30_000);
      deferredRevoke = callback;
      return 1;
    };
    try {
      downloadChartPng(
        { getDataURL: (options) => {
          assert.deepEqual(options, { type: 'png', pixelRatio: 2 });
          return 'data:image/png;base64,' + png.toString('base64');
        } },
        fakeDocument,
        fakeUrl
      );
    } finally {
      globalThis.setTimeout = originalTimer;
    }
    assert.deepEqual(clicked, {
      href: 'blob:http://localhost/chart-download',
      download: 'chart-example.png',
    });
    assert.equal(removed, true);
    assert.equal(actualBlob.type, 'image/png');
    assert.deepEqual(Buffer.from(await actualBlob.arrayBuffer()), png);
    assert.equal(typeof deferredRevoke, 'function');
    deferredRevoke();
  });

  it('refuses unsupported images or empty data without creating a download', () => {
    const never = {
      createObjectURL() { assert.fail('Unexpected object URL creation'); },
      revokeObjectURL() { assert.fail('Unexpected object URL revoke'); },
    };
    const neverDom = {
      createElement() { assert.fail('Unexpected DOM write'); },
      body: { appendChild() { assert.fail('Unexpected DOM insertion'); } },
    };
    for (const uri of ['data:image/svg+xml;base64,PHN2Zz4=', 'data:image/png;base64,']) {
      assert.throws(
        () => downloadChartPng({ getDataURL: () => uri }, neverDom, never),
        { message: /CHART_PNG_DATA_/ }
      );
    }
  });
});
