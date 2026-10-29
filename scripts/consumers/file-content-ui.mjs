import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

/** Validate offline, then emit one snippet for an already admitted browser page. */
export function validateInput(c) {
  const require = (ok, code) => {
    if (!ok) throw new Error(code);
  };
  require(c?.version === 1, 'INPUT_VERSION');
  require(/^[0-9a-f]{40}$/.test(c.sourceCommit), 'SOURCE_COMMIT');
  require(/^[0-9a-f]{64}$/.test(c.installedFileDigest), 'INSTALLED_FILE_DIGEST');
  const url = new URL(c.pageUrl);
  require(['http:', 'https:'].includes(url.protocol) && !url.username && !url.password, 'PAGE_URL');
  require(!url.search && !url.hash, 'PAGE_URL_NO_SECRET_QUERY');
  require(Array.isArray(c.pageResources) && c.pageResources.every(resource => {
    if (!['script', 'stylesheet', 'image', 'font'].includes(resource?.type)) return false;
    const asset = new URL(resource.url, url.origin);
    return asset.origin === url.origin && !asset.username && !asset.password && !asset.search && !asset.hash &&
      !/^\/(?:adminapi|api)(?:\/|$)/.test(asset.pathname);
  }), 'EXACT_PAGE_RESOURCES');
  require(c.browser === 'mac14-profile2' || c.browser === 'codex-iab', 'BROWSER');
  require(c.browser !== 'codex-iab' || typeof c.fallbackReason === 'string' && c.fallbackReason.length > 0, 'IAB_FALLBACK_REASON');
  for (const key of ['runtime', 'sourceAdoption', 'identity', 'tenantRows', 'dataProtection', 'requestBudget', 'browserExclusive']) {
    require(typeof c.admission?.[key] === 'string' && c.admission[key].length > 0, `ADMISSION_${key}`);
  }
  require(Number.isSafeInteger(c.expiresAt) && c.expiresAt > 0, 'ADMISSION_EXPIRY');
  require(Number.isSafeInteger(c.maxApiRequests) && c.maxApiRequests > 0 && c.maxApiRequests <= 40, 'REQUEST_BUDGET');
  require(Number.isSafeInteger(c.identity?.id) && c.identity.id > 0 && typeof c.identity.tenantName === 'string' && c.identity.tenantName.length > 0, 'IDENTITY');
  require(Array.isArray(c.allowedFileKeys) && c.allowedFileKeys.length > 0 && c.allowedFileKeys.every(k => /^file_[0-9a-f]{32}$/.test(k)), 'FILE_SCOPE');
  require(c.allowedFileKeys.includes(c.file?.key) && /^[0-9a-f]{64}$/.test(c.file?.sha256), 'FILE_IDENTITY');
  require(typeof c.file.name === 'string' && c.file.name.length > 0 && /^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/.test(c.file.mime), 'FILE_METADATA');
  require(['preview', 'download'].includes(c.action), 'ACTION');
  require(['available', 'read_only', 'preview_unavailable', 'service_unavailable', 'permission_denied'].includes(c.expected?.state), 'EXPECTED_STATE');
  require(typeof c.expected.canDownload === 'boolean', 'EXPECTED_DOWNLOAD');
  require(c.action !== 'download' || c.expected.canDownload, 'DOWNLOAD_NOT_ADMITTED');
  const viewable = ['available', 'read_only'].includes(c.expected.state);
  require(!viewable || ['img', 'object', 'audio', 'video'].includes(c.expected.media), 'EXPECTED_MEDIA');
  require(viewable || typeof c.expected.message === 'string' && c.expected.message.length > 0, 'EXPECTED_MESSAGE');
  require(c.expected.viewerAdapterId === null || typeof c.expected.viewerAdapterId === 'string' && c.expected.viewerAdapterId.length > 0, 'EXPECTED_ADAPTER');
  return c;
}

/** Uses only the supplied page. No browser/context, login, upload or fixture creation. */
export async function runFileContentUi(page, c) {
  const check = (ok, code) => { if (!ok) throw new Error(code); };
  check(Date.now() < c.expiresAt, 'ADMISSION_EXPIRED');
  check(page.url() === c.pageUrl, 'OWN_ADMITTED_PAGE_REQUIRED');
  const origin = new URL(c.pageUrl).origin;
  const violations = [];
  let apiRequests = 0;
  let identityVerified = false;
  let fileVerified = false;
  let previewResponses = 0;
  let downloadResponses = 0;
  const pending = new Set();
  const authorizedDeliveries = new Set();
  const admittedFiles = new Map();
  const pageResources = new Set(c.pageResources.map(resource =>
    `${resource.type}\n${new URL(resource.url, origin).href}`));
  // These are current public read endpoints, even where the host uses POST.
  const reads = new Map([
    ['/adminapi/user/info', 'POST'],
    ['/adminapi/user/menu', 'POST'],
    ['/adminapi/peanut.file.list', 'GET'],
    ['/adminapi/peanut.file.category.list', 'GET'],
    ['/adminapi/api/v1/files/assets', 'GET'],
  ]);
  const guard = async route => {
    const request = route.request();
    const url = new URL(request.url());
    const method = request.method();
    let allowed = url.origin === origin && Date.now() < c.expiresAt;
    if (url.pathname.startsWith('/adminapi/')) {
      apiRequests += 1;
      allowed &&= apiRequests <= c.maxApiRequests && Date.now() < c.expiresAt;
      if (url.pathname === '/adminapi/api/v1/files/resolve' && method === 'POST') {
        // Shell identity/list reads can finish after thumbnails start resolving.
        for (let n = 0; n < 30 && !(identityVerified && fileVerified); n += 1) {
          await new Promise(resolve => setTimeout(resolve, 100));
        }
        let body;
        try { body = request.postDataJSON(); } catch { body = null; }
        allowed &&= identityVerified && fileVerified && body !== null &&
          c.allowedFileKeys.includes(body.file_key) &&
          (body.operation === 'preview' || c.action === 'download' && body.file_key === c.file.key && body.operation === 'download_original');
      } else allowed &&= reads.get(url.pathname) === method;
    } else if (url.pathname === '/api/storage/delivery') {
      await Promise.all([...pending]);
      allowed &&= method === 'GET' && identityVerified && fileVerified &&
        ['fetch', 'xhr', 'image', 'media'].includes(request.resourceType()) &&
        authorizedDeliveries.has(url.href);
    } else {
      allowed &&= method === 'GET' && (
        request.resourceType() === 'document' && url.href === c.pageUrl ||
        pageResources.has(`${request.resourceType()}\n${url.href}`)
      );
    }
    allowed &&= Date.now() < c.expiresAt;
    if (!allowed) {
      violations.push('UNADMITTED_REQUEST');
      await route.abort('blockedbyclient');
    } else await route.continue();
  };
  const inspect = async response => {
    const path = new URL(response.url()).pathname;
    if (!['/adminapi/user/info', '/adminapi/peanut.file.list', '/adminapi/api/v1/files/resolve'].includes(path)) return;
    const body = await response.json();
    const data = body?.data;
    if (path === '/adminapi/user/info') {
      check(response.ok() && body.code === 20000 && data?.id === c.identity.id && data.tenantName === c.identity.tenantName, 'ACTUAL_IDENTITY_MISMATCH');
      identityVerified = true;
    }
    if (path === '/adminapi/peanut.file.list') {
      check(response.ok() && body.code === 20000 && Array.isArray(data?.lists), 'ACTUAL_FILE_LIST_INVALID');
      check(data.lists.every(item => c.allowedFileKeys.includes(item.file_key)), 'VISIBLE_ROWS_OUTSIDE_SCOPE');
      const item = data.lists.find(item => item.file_key === c.file.key);
      check(item && item.source_sha256 === c.file.sha256 && item.verified_mime_type === c.file.mime && item.name === c.file.name, 'ACTUAL_FILE_IDENTITY_MISMATCH');
      check(item.preview_state === c.expected.state && item.can_download_original === c.expected.canDownload, 'ACTUAL_FILE_CAPABILITY_MISMATCH');
      check(item.capabilities?.viewerAdapterId === c.expected.viewerAdapterId, 'ACTUAL_ADAPTER_MISMATCH');
      for (const visible of data.lists) admittedFiles.set(visible.file_key, visible);
      fileVerified = true;
    }
    if (path === '/adminapi/api/v1/files/resolve') {
      const request = response.request().postDataJSON();
      const visible = admittedFiles.get(request.file_key);
      check(visible && response.ok() && body.code === 20000 && data?.file_key === request.file_key &&
        data.source_sha256 === visible.source_sha256 && (request.operation === 'preview' ||
        request.file_key === c.file.key && request.operation === 'download_original'), 'ACTUAL_VISIBLE_ACCESS_INVALID');
      const delivery = new URL(data.url, origin);
      check(delivery.origin === origin && delivery.pathname === '/api/storage/delivery' &&
        !delivery.username && !delivery.password && !delivery.hash, 'DELIVERY_TARGET_INVALID');
      if (request.file_key !== c.file.key) {
        check(data.purpose === 'preview' && (visible.can_download_original || data.bytes === 'derivative'), 'VISIBLE_PREVIEW_ORIGINAL_LEAK');
        authorizedDeliveries.add(delivery.href);
        return;
      }
      check(response.ok() && body.code === 20000 && data?.file_key === c.file.key && data.source_sha256 === c.file.sha256, 'ACTUAL_ACCESS_INVALID');
      if (request.operation === 'preview') {
        check(data.purpose === 'preview' && (c.expected.canDownload || data.bytes === 'derivative'), 'PREVIEW_ORIGINAL_LEAK');
        previewResponses += 1;
      } else {
        check(data.purpose === 'download_original' && data.bytes === 'original' && data.can_download_original === true, 'DOWNLOAD_GRANT_INVALID');
        downloadResponses += 1;
      }
      authorizedDeliveries.add(delivery.href);
    }
  };
  const onResponse = response => {
    const promise = inspect(response).catch(error => { violations.push(error.message); });
    pending.add(promise);
    promise.finally(() => pending.delete(promise));
  };
  await page.route('**/*', guard);
  page.on('response', onResponse);
  try {
    await page.reload({ waitUntil: 'networkidle', timeout: 15000 });
    await Promise.all([...pending]);
    check(identityVerified && fileVerified, 'CURRENT_IDENTITY_AND_FILE_NOT_OBSERVED');
    check(violations.length === 0, violations[0]);
    const card = page.locator('.grid-item').filter({ has: page.locator('.name').filter({ hasText: c.file.name }) });
    check(await card.count() === 1, 'UNIQUE_FILE_CARD_REQUIRED');
    await card.getByRole('button', { name: '预览', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: '文件预览' });
    await dialog.waitFor({ state: 'visible', timeout: 5000 });
    const surface = dialog.locator('.content-tools-surface');
    const download = surface.getByRole('button', { name: '下载原件', exact: true });
    check(await download.count() === Number(c.expected.canDownload), 'DOWNLOAD_UI_PERMISSION_MISMATCH');
    if (['available', 'read_only'].includes(c.expected.state)) {
      await surface.locator(c.expected.media).waitFor({ state: 'visible', timeout: 10000 });
      check(await surface.locator('iframe, script').count() === 0, 'UNSAFE_RENDER_ELEMENT');
    } else {
      await dialog.getByText(c.expected.message, { exact: true }).first().waitFor({ state: 'visible', timeout: 5000 });
      check(await surface.locator('img, object, audio, video, iframe, script').count() === 0, 'UNAVAILABLE_MEDIA_MOUNTED');
    }
    let downloaded = false;
    if (c.action === 'download') {
      const event = page.waitForEvent('download', { timeout: 10000 });
      await download.click();
      const result = await event;
      check(result.suggestedFilename() === c.file.name, 'DOWNLOAD_FILENAME_MISMATCH');
      check(await result.failure() === null, 'DOWNLOAD_BROWSER_FAILURE');
      await result.delete();
      downloaded = true;
    }
    await Promise.all([...pending]);
    check(violations.length === 0, violations[0]);
    check(!['available', 'read_only'].includes(c.expected.state) || previewResponses > 0, 'NO_REAL_PREVIEW_RESPONSE');
    check(c.action !== 'download' || downloadResponses === 1, 'NO_UNIQUE_REAL_DOWNLOAD_RESPONSE');
    return { state: 'PASS_SCOPED_UI', sourceCommit: c.sourceCommit, fileKey: c.file.key, capabilityState: c.expected.state, apiRequests, previewResponses, downloadResponses, downloaded, limits: 'One admitted existing file/current identity/UI state only; no cross-tenant, upload, Cloud, historical-byte or full FUN09/10 acceptance.' };
  } catch (error) {
    return { state: 'FAIL_OR_UNKNOWN_PRESERVED', code: String(error.message), apiRequests, previewResponses, downloadResponses, violations: [...new Set(violations)], next: 'Observe original request before any replay; no automatic retry.' };
  } finally {
    page.off('response', onResponse);
    await page.unroute('**/*', guard);
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  if (process.argv.length !== 4 || process.argv[2] !== '--emit') {
    throw new Error('Usage: node scripts/consumers/file-content-ui.mjs --emit /absolute/admitted-input.json');
  }
  const c = validateInput(JSON.parse(readFileSync(process.argv[3], 'utf8')));
  // Printing never executes browser code or connects to a service.
  process.stdout.write(`async (page) => { const run = ${runFileContentUi.toString()}; return await run(page, ${JSON.stringify(c)}); }\n`);
}
