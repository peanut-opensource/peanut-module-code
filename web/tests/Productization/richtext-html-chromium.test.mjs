import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';

/**
 * Browser-owned gate for all 22 HTML import cases with actual RichTextEditor.
 * DOMPurify WHOLE_DOCUMENT needs a real DOM; happy-dom has an insertBefore
 * compatibility issue. This does not alter product routes or sanitizer rules.
 */
test('RichText HTML import: 22 security/lossless cases in Chromium', { timeout: 130_000 }, () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
  const fixture = path.join(root, 'web/tests/Productization/fixtures/richtext-html-browser');
  const casesSource = readFileSync(path.join(fixture, 'cases.ts'), 'utf8');
  assert.equal((casesSource.match(/expected:'/g) || []).length, 22);

  const chrome = [
    process.env.CHROME_BIN,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
  ].find(x => x && existsSync(x));
  assert.ok(chrome, 'Real Chrome/Chromium is required; set CHROME_BIN for this browser gate');

  const temp = mkdtempSync(path.join(os.tmpdir(), 'peanut-richtext-browser-gate-'));
  try {
    const dist = path.join(temp, 'dist');
    const build = spawnSync(process.execPath, [
      path.join(root, 'web/node_modules/vite/bin/vite.js'),
      'build',
      '--config', path.join(fixture, 'vite.config.mjs'),
      '--outDir', dist,
      '--emptyOutDir',
    ], { cwd: root, encoding: 'utf8', timeout: 90_000, maxBuffer: 8 * 1024 * 1024 });
    assert.equal(build.status, 0, 'Native browser fixture build failed: ' + (build.stderr || build.stdout || '').slice(-3500));

    const index = readFileSync(path.join(dist, 'index.html'), 'utf8');
    const script = index.match(/<script type="module" crossorigin src="\.\/(assets\/[^"]+\.js)"><\/script>/);
    const style = index.match(/<link rel="stylesheet" crossorigin href="\.\/(assets\/[^"]+\.css)">/);
    assert.ok(script && style, 'Actual Vite bundle/stylesheet missing');
    const js = readFileSync(path.join(dist, script[1]), 'utf8').replace(/<\/script/gi, '<\\/script');
    const css = readFileSync(path.join(dist, style[1]), 'utf8').replace(/<\/style/gi, '<\\/style');
    const single = index
      // Replacement callbacks avoid special dollar substitutions in bundled JS.
      .replace(script[0], () => '<script type="module">\n' + js + '\n</script>')
      .replace(style[0], () => '<style>\n' + css + '\n</style>');
    assert.equal(single.split('</script>').length - 1, 1, 'Inline JS leaked an HTML script terminator');
    const page = path.join(temp, 'standalone.html');
    writeFileSync(page, single, { mode: 0o600 });

    const output = spawnSync(chrome, [
      '--headless=new', '--no-first-run', '--no-default-browser-check',
      '--disable-extensions', '--disable-background-networking',
      // Page scripts can be scheduled after DOMContentLoaded on a fresh profile.
      '--virtual-time-budget=5000',
      '--user-data-dir=' + path.join(temp, 'chrome-profile'),
      '--dump-dom', pathToFileURL(page).href,
    ], { cwd: root, encoding: 'utf8', timeout: 45_000, maxBuffer: 16 * 1024 * 1024 });
    assert.equal(output.status, 0, 'Real Chrome gate failed: ' + (output.stderr || '').slice(-2200));
    const document = output.stdout;
    const result = document.match(/<pre id="checks">([\s\S]*?)<\/pre>/);
    assert.ok(result, 'Browser did not mount actual RichText component: ' + document.slice(-450));
    const checks = JSON.parse(result[1]);
    assert.equal(checks.length, 22);
    assert.equal(new Set(checks.map(x => x.id)).size, 22);
    assert.deepEqual(checks.filter(x => !x.pass), [], 'RichText security cases failed');
    assert.match(document, /22\/22 PASS/);
    assert.match(document, /Editor ready: true/);
    assert.match(document, /class="ProseMirror[^"]*"/);
    assert.match(document, /contenteditable="true"/);
    console.log('RICHTEXT-HTML-CHROMIUM-22-OF-22 PASS; real RichTextEditor and sanitizer');
  } finally {
    rmSync(temp, { recursive: true, force: true });
  }
});
