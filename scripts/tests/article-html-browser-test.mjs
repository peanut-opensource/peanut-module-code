#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawn, spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const output = path.join(root, '.local/mrm-007-html/chrome');
const receiptFile = path.join(root, '.local/mrm-007-html/007c-browser-receipt.json');
const chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const fixture = 'scripts/tests/article-html-browser-fixture.ts';
const sha256 = file => createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const hashes = files => Object.fromEntries(files.sort().map(file => [file, sha256(path.resolve(root, file))]));
const sourceFiles = directory => fs.readdirSync(path.join(root, directory), { withFileTypes: true }).flatMap(entry => {
  const file = `${directory}/${entry.name}`;
  return entry.isDirectory() ? sourceFiles(file) : /\.(ts|vue)$/.test(file) ? [file] : [];
});
const protectedSources = [
  ...sourceFiles('web/src/modules/peanut-rich-text/src'),
  ...sourceFiles('web/src/modules/peanut-content-tools/src'),
  'web/src/modules/peanut-rich-text/package.json', 'web/package.json', 'web/pnpm-lock.yaml',
];
// A real-time CDP pipe waits for native Web Locks/WebCrypto tasks; virtual-time
// --dump-dom can finish while those browser tasks are still pending. No TCP port.
async function runBrowser(args, url) {
  const child = spawn(chrome, args, { stdio: ['ignore', 'pipe', 'pipe', 'pipe', 'pipe'] });
  const pending = new Map();
  const exceptions = [];
  const requests = [];
  let serial = 0, buffer = '', stdout = '', stderr = '', exited = false;
  let status = null, signal = null, failure, timedOut = false;
  const stopped = new Promise(resolve => child.once('close', (code, reason) => {
    exited = true; status = code; signal = reason;
    for (const operation of pending.values()) operation.reject(new Error('Owned Chrome exited before protocol reply'));
    pending.clear(); resolve();
  }));
  child.once('error', error => {
    failure = error;
    for (const operation of pending.values()) operation.reject(error);
    pending.clear();
  });
  child.stdout.on('data', chunk => { stdout = (stdout + chunk).slice(-8 * 1024 * 1024); });
  child.stderr.on('data', chunk => { stderr = (stderr + chunk).slice(-1024 * 1024); });
  child.stdio[4].on('data', chunk => {
    buffer += chunk.toString();
    let boundary;
    while ((boundary = buffer.indexOf('\0')) >= 0) {
      const packet = buffer.slice(0, boundary); buffer = buffer.slice(boundary + 1);
      if (!packet) continue;
      const message = JSON.parse(packet);
      if (message.method === 'Network.requestWillBeSent') requests.push(message.params.request.url);
      if (message.method === 'Runtime.exceptionThrown' && exceptions.length < 20) exceptions.push(message.params);
      const operation = pending.get(message.id);
      if (!operation) continue;
      pending.delete(message.id);
      message.error ? operation.reject(new Error(JSON.stringify(message.error))) : operation.resolve(message.result);
    }
  });
  const request = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    if (exited || failure) return reject(failure ?? new Error('Owned Chrome already exited'));
    const id = ++serial; pending.set(id, { resolve, reject });
    child.stdio[3].write(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }) + '\0', error => {
      if (error) { pending.delete(id); reject(error); }
    });
  });
  const deadline = setTimeout(() => {
    timedOut = true;
    failure = new Error('Owned Chrome execution exceeded 45 seconds');
    for (const operation of pending.values()) operation.reject(failure);
    pending.clear();
    child.kill('SIGKILL');
  }, 45000);
  let dom = '', browserVersion = null;
  try {
    browserVersion = await request('Browser.getVersion');
    const { targetId } = await request('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await request('Target.attachToTarget', { targetId, flatten: true });
    await request('Runtime.enable', {}, sessionId);
    await request('Page.enable', {}, sessionId);
    await request('Network.enable', {}, sessionId);
    await request('Network.setBlockedURLs', { urls: ['http://*', 'https://*', 'ws://*', 'wss://*', 'ftp://*'] }, sessionId);
    await request('Page.navigate', { url }, sessionId);
    const until = Date.now() + 35000;
    while (Date.now() < until && !exited && !timedOut) {
      const value = await request('Runtime.evaluate', { expression: 'document.getElementById("007c-browser-result")?.textContent', returnByValue: true }, sessionId);
      if (typeof value.result?.value === 'string' || exceptions.length) break;
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    const value = await request('Runtime.evaluate', { expression: 'document.documentElement.outerHTML', returnByValue: true }, sessionId);
    dom = value.result?.value ?? '';
    await request('Browser.close').catch(() => undefined);
    await stopped;
  } catch (error) {
    failure = error;
    if (!exited) child.kill('SIGKILL');
    await stopped;
  } finally {
    clearTimeout(deadline);
    child.stdio[3].destroy(); child.stdio[4].destroy();
  }
  return { status, signal, stdout: dom, stderr: stderr + (exceptions.length ? '\nCDP exceptions: ' + JSON.stringify(exceptions) : ''),
    error: failure, timedOut, processOutput: stdout, requests, exceptions, exited, browserVersion };
}

fs.mkdirSync(output, { recursive: true });
const profile = fs.mkdtempSync(path.join(output, 'chrome-profile-'));
const receipt = {
  schemaVersion: 1, startedAt: new Date().toISOString(), status: 'not-executed',
  command: 'python3 scripts/project-env exec -- node scripts/tests/article-html-browser-test.mjs',
  sourceHashes: hashes(protectedSources), fixtureHash: sha256(path.join(root, fixture)),
  driverHash: sha256(fileURLToPath(import.meta.url)), expectedCases: 48,
  node: { executable: process.execPath, version: process.version }, chrome: { executable: chrome },
  output, profile, cleanup: {},
  scope: 'Actual public editor export, DOMPurify, Tiptap schema/parser and document projection in real Chrome; empty module contributions mean no media plugins.',
  unverified: ['Whole application HTTP/browser flows', 'PHP and database', 'Migration apply/rollback', 'Media plugin integration'],
};
let exitCode = 1;
try {
  const webRequire = createRequire(path.join(root, 'web/package.json'));
  const viteRequire = createRequire(webRequire.resolve('vite/package.json'));
  const esbuild = viteRequire('esbuild');
  receipt.dependencies = { vite: fs.realpathSync(webRequire.resolve('vite/package.json')), esbuild: { path: fs.realpathSync(viteRequire.resolve('esbuild')), version: esbuild.version } };
  const unmountedComponents = [];
  const built = await esbuild.build({
    absWorkingDir: root, entryPoints: [fixture], bundle: true, write: false,
    format: 'iife', platform: 'browser', target: 'chrome120', metafile: true,
    alias: { '@': path.join(root, 'web/src') }, nodePaths: [path.join(root, 'web/node_modules')],
    define: { 'process.env.NODE_ENV': '"production"' },
    // This test executes the public HTML conversion export, not its re-exported
    // viewers. Rendering one of these sentinels fails instead of pretending UI
    // qualification; DOMPurify, Tiptap, schema and projection remain actual.
    plugins: [{ name: 'unmounted-component-boundary', setup(build) {
      build.onLoad({ filter: /\.vue$/ }, args => {
        unmountedComponents.push(path.relative(root, args.path));
        return { contents: 'export default {setup(){throw new Error("UI mounting is outside this HTML conversion fixture")}};', loader: 'js' };
      });
    } }, { name: 'no-media-contributions', setup(build) {
      build.onResolve({ filter: /^virtual:peanut-module-contributions$/ }, () => ({ path: 'virtual:peanut-module-contributions', namespace: 'empty-contributions' }));
      build.onLoad({ filter: /.*/, namespace: 'empty-contributions' }, () => ({ contents: 'export default [];', loader: 'js' }));
    } }],
  });
  const inputs = Object.keys(built.metafile.inputs).filter(file => !file.startsWith('empty-contributions:'));
  receipt.bundleInputHashes = hashes(inputs);
  receipt.bundleInputRealPaths = Object.fromEntries(inputs.map(file => [file, fs.realpathSync(path.resolve(root, file))]));
  for (const file of ['editor.ts', 'html-import.ts', 'extensions.ts', 'document.ts']) {
    const key = `web/src/modules/peanut-rich-text/src/${file}`;
    if (receipt.bundleInputHashes[key] !== receipt.sourceHashes[key]) throw new Error(`Missing or changed actual source: ${key}`);
  }
  for (const dependency of ['/dompurify/', '/@tiptap/core/', '/prosemirror-model/'])
    if (!Object.values(receipt.bundleInputRealPaths).some(file => file.includes(dependency))) throw new Error(`Missing actual dependency ${dependency}`);
  receipt.bundleMetafile = built.metafile;
  const bundle = built.outputFiles[0].text;
  receipt.unmountedComponents = unmountedComponents;
  receipt.componentBoundary = 'Re-exported .vue UI modules use throwing sentinels and are never mounted. HTML preview, DOMPurify, Tiptap/schema and projection are actual. Whole-application build is validated separately.';
  fs.writeFileSync(path.join(output, 'fixture.js'), bundle);
  const html = path.join(output, 'fixture.html');
  const csp = "default-src 'none'; script-src 'unsafe-inline'; connect-src 'none'; img-src 'none'; media-src 'none'; frame-src 'none'; style-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'";
  fs.writeFileSync(html, `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}"></head><body><script>${bundle.replace(/<\/script/gi, '<\\/script')}</script></body></html>`);
  receipt.network = { csp, blockedURLs: ['http://*', 'https://*', 'ws://*', 'wss://*', 'ftp://*'], transport: 'CDP pipe, no listener', externalUrlsRead: false };
  receipt.artifactHashes = hashes([path.relative(root, html), path.relative(root, path.join(output, 'fixture.js'))]);
  const plist = spawnSync('/usr/libexec/PlistBuddy', ['-c', 'Print :CFBundleShortVersionString', '/Applications/Google Chrome.app/Contents/Info.plist'], { encoding: 'utf8', timeout: 5000 });
  receipt.chrome.installedBundleVersion = plist.status === 0 ? plist.stdout.trim() : null;
  const args = ['--headless=new', `--user-data-dir=${profile}`, '--disable-background-networking', '--no-first-run', '--no-default-browser-check', '--disable-component-update', '--disable-sync', '--disable-extensions', '--remote-debugging-pipe'];
  receipt.chrome.arguments = args;
  const execution = await runBrowser(args, pathToFileURL(html).href);
  receipt.chrome.runtimeVersion = execution.browserVersion;
  fs.writeFileSync(path.join(output, 'chrome.stdout.html'), execution.stdout);
  fs.writeFileSync(path.join(output, 'chrome.stderr.log'), execution.stderr);
  receipt.execution = { exitCode: execution.status, signal: execution.signal, error: execution.error?.message, timedOut: execution.timedOut, exited: execution.exited, requests: execution.requests, exceptions: execution.exceptions };
  const match = execution.stdout.match(/<pre id="007c-browser-result">([\s\S]*?)<\/pre>/);
  receipt.marker = Boolean(match);
  if (!match) receipt.failure = 'No execution marker; browser acceptance is incomplete and no assertions count as passed. Native startup failure does not establish a product bug.';
  if (match) receipt.browser = JSON.parse(match[1].replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
  receipt.bundleInputHashesAfter = hashes(inputs);
  receipt.bundleInputsUnchanged = JSON.stringify(receipt.bundleInputHashes) === JSON.stringify(receipt.bundleInputHashesAfter);
  const rows = receipt.browser?.cases ?? [];
  receipt.counts = { cases: rows.length, passed: rows.filter(row => row.passed).length, failed: rows.filter(row => !row.passed).length, assertions: receipt.browser?.assertions ?? 0 };
  const complete = Boolean(match) && !execution.timedOut && !execution.error && execution.status === 0 && execution.signal === null && receipt.browser?.completed === true && rows.length === 48 && receipt.counts.assertions > 0;
  receipt.status = !complete ? 'browser-did-not-complete' : receipt.counts.failed ? 'failed' : 'pending-source-check';
  if (receipt.status === 'pending-source-check' && receipt.bundleInputsUnchanged && execution.exceptions.length === 0 && execution.requests.every(url => url === pathToFileURL(html).href)) exitCode = 0;
} catch (error) {
  receipt.status = 'driver-failed';
  receipt.error = error.stack ?? String(error);
} finally {
  receipt.sourceHashesAfter = hashes(protectedSources);
  receipt.sourceUnchanged = JSON.stringify(receipt.sourceHashes) === JSON.stringify(receipt.sourceHashesAfter);
  if (!receipt.sourceUnchanged) exitCode = 1;
  try {
    fs.rmSync(profile, { recursive: true, force: true });
    receipt.cleanup.profileRemoved = !fs.existsSync(profile);
  } catch (error) {
    receipt.cleanup.error = String(error);
    exitCode = 1;
  }
  if (receipt.status === 'pending-source-check') receipt.status = exitCode === 0 ? 'passed' : 'failed';
  receipt.finishedAt = new Date().toISOString();
  receipt.exitCode = exitCode;
  fs.writeFileSync(receiptFile, JSON.stringify(receipt, null, 2) + '\n');
  console.log(JSON.stringify({ status: receipt.status, exitCode, counts: receipt.counts, sourceUnchanged: receipt.sourceUnchanged, profileRemoved: receipt.cleanup.profileRemoved, receipt: receiptFile }));
}
process.exitCode = exitCode;
