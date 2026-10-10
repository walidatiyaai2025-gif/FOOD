import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { chromium } from 'playwright';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const root = path.resolve('.app-preview-parity');
const evidence = path.join(root, 'evidence');
await fs.mkdir(evidence, { recursive: true });

const mime = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.wasm': 'application/wasm',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
};

const contractVersion = 'shared-flutter-v1';

const cases = [
  { id: 'customer-b2b-guest-published-en', app: 'customer', channel: 'b2b', storeId: 11, authenticated: false, configuration: 'published', locale: 'en', width: 390, height: 844, profile: 'iphone-common', expectedState: 'ready' },
  { id: 'customer-b2b-auth-draft-ar', app: 'customer', channel: 'b2b', storeId: 12, authenticated: true, configuration: 'draft', locale: 'ar', width: 360, height: 800, profile: 'small-android', expectedState: 'ready' },
  { id: 'customer-b2c-guest-published-ar', app: 'customer', channel: 'b2c', storeId: 21, authenticated: false, configuration: 'published', locale: 'ar', width: 430, height: 900, profile: 'large-android', expectedState: 'ready' },
  { id: 'customer-b2c-auth-draft-en', app: 'customer', channel: 'b2c', storeId: 22, authenticated: true, configuration: 'draft', locale: 'en', width: 390, height: 844, profile: 'iphone-common', expectedState: 'ready' },
  { id: 'customer-b2c-missing-published-en', app: 'customer', channel: 'b2c', storeId: 99, authenticated: false, configuration: 'published', locale: 'en', width: 360, height: 800, profile: 'small-android', expectedState: 'error', expectedCode: 'preview_published_unavailable' },
  { id: 'driver-b2b-auth-rejected-ar', app: 'driver', channel: 'b2b', storeId: 31, authenticated: true, configuration: 'published', locale: 'ar', width: 390, height: 844, profile: 'iphone-common', expectedState: 'error', expectedCode: 'preview_bootstrap_failed' },
  { id: 'driver-b2c-auth-draft-en', app: 'driver', channel: 'b2c', storeId: 32, authenticated: true, configuration: 'draft', locale: 'en', width: 360, height: 800, profile: 'small-android', expectedState: 'ready' },
];

function credentialFor(testCase) {
  return 'fixture:' + testCase.id;
}

function bootstrapFor(testCase) {
  return {
    type: 'foodex.preview.bootstrap',
    version: contractVersion,
    payload: {
      context: {
        session_id: testCase.authenticated ? 'session-' + testCase.id : null,
        target_type: testCase.app,
        channel: testCase.channel,
        store_id: testCase.storeId,
        mode: 'read_only',
        read_only: true,
        support_access: false,
        target: testCase.authenticated ? {
          user_id: testCase.app === 'driver' ? 501 : 401,
          ...(testCase.app === 'driver' ? { driver_id: 601 } : {}),
          name: testCase.app === 'driver' ? 'Preview Driver' : 'Preview Customer',
          locale: testCase.locale,
        } : null,
      },
      credential: testCase.authenticated ? credentialFor(testCase) : null,
      configuration: testCase.configuration,
      locale: testCase.locale,
      device: { profile: testCase.profile, width: testCase.width },
      safe_mode: 'read_only',
    },
  };
}

function fixtureFromRequest(req, url) {
  const token = req.headers['x-foodex-preview-token'];
  if (typeof token === 'string') {
    const byToken = cases.find((item) => item.authenticated && credentialFor(item) === token);
    if (byToken) return byToken;
  }
  const storeId = Number(url.searchParams.get('store_id'));
  const channel = url.searchParams.get('channel');
  const mode = url.searchParams.get('mode');
  return cases.find((item) =>
    item.app === 'customer' &&
    !item.authenticated &&
    item.storeId === storeId &&
    item.channel === channel &&
    item.configuration === mode);
}

function configurationResponse(testCase) {
  return {
    data: {
      revision_id: 'fixture-' + testCase.id,
      checksum: crypto.createHash('sha256').update(testCase.id).digest('hex'),
      schema_version: 1,
      mode: testCase.configuration,
      status: testCase.configuration,
      channel: testCase.channel,
      store_id: testCase.storeId,
      read_only: true,
      payload: {
        schema_version: 1,
        channel: testCase.channel,
        store: { id: testCase.storeId, code: 'STORE-' + testCase.storeId, name: 'Fixture Store ' + testCase.storeId, is_active: true },
        settings: { theme_code: testCase.channel === 'b2b' ? 'wholesale_b2b' : 'retail_grocery', primary_color: '#111111', background_color: '#ffffff' },
        banners: [{ title: testCase.id, image_path: null, target_type: null, target_id: null, target_url: null, sort_order: 1, is_active: true }],
        sections: [],
      },
    },
  };
}

function json(res, status, body) {
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'no-store',
    'access-control-allow-origin': '*',
    'access-control-allow-headers': 'Accept, Content-Type, X-Foodex-Preview-Token, X-Requested-With',
  });
  res.end(JSON.stringify(body));
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', 'http://127.0.0.1:4173');
  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'access-control-allow-origin': '*',
      'access-control-allow-methods': 'GET, HEAD, OPTIONS',
      'access-control-allow-headers': 'Accept, Content-Type, X-Foodex-Preview-Token, X-Requested-With',
    });
    res.end();
    return;
  }

  if (url.pathname === '/host.html' || url.pathname === '/standalone.html') {
    const fixtureId = url.searchParams.get('fixture');
    const testCase = cases.find((item) => item.id === fixtureId);
    if (!testCase) {
      res.writeHead(400);
      res.end('bad fixture');
      return;
    }
    const bootstrap = JSON.stringify(bootstrapFor(testCase)).replaceAll('<', '\\u003c');
    const body = '<!doctype html><html><body style="margin:0;background:white;overflow:hidden">' +
      '<iframe id="runtime" width="' + testCase.width + '" height="' + testCase.height + '" style="display:block;border:0"></iframe>' +
      '<script>' +
      'window.__foodexMessages=[];window.__foodexHandshake=false;' +
      'window.__foodexBootstrap=' + bootstrap + ';' +
      'const runtime=document.getElementById("runtime");' +
      'function sendBootstrap(){if(runtime.contentWindow){runtime.contentWindow.postMessage(window.__foodexBootstrap,location.origin);}}' +
      'addEventListener("message",(event)=>{if(event.origin!==location.origin||event.source!==runtime.contentWindow)return;if(!event.data||typeof event.data!=="object")return;window.__foodexMessages.push(event.data);if(event.data.type==="foodex.preview.ready"&&event.data.version==="shared-flutter-v1"&&!window.__foodexHandshake){window.__foodexHandshake=true;sendBootstrap();}});' +
      'runtime.addEventListener("load",()=>{const fallback=setTimeout(()=>{if(!window.__foodexHandshake){sendBootstrap();}},5000);const terminal=setInterval(()=>{if(window.__foodexHandshake||window.__foodexMessages.some((m)=>m.type==="foodex.preview.status"&&["ready","error","expired","forbidden"].includes(m.state))){clearTimeout(fallback);clearInterval(terminal);}},250);setTimeout(()=>{clearTimeout(fallback);clearInterval(terminal);},12000);});' +
      'runtime.src="/' + testCase.app + '/index.html";' +
      '</script></body></html>';
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    res.end(body);
    return;
  }

  if (url.pathname === '/admin/app-preview/storefront-configuration' ||
      url.pathname === '/api/v1/app-preview/storefront-configuration') {
    const testCase = fixtureFromRequest(req, url);
    if (!testCase || testCase.app !== 'customer') {
      json(res, 403, { message: 'fixture_scope_rejected' });
      return;
    }
    if (testCase.storeId === 99) {
      json(res, 404, { message: 'fixture_missing_configuration' });
      return;
    }
    json(res, 200, configurationResponse(testCase));
    return;
  }

  if (url.pathname === '/api/v1/app-preview/events') {
    json(res, 200, { data: [], meta: { cursor: 0, retry_after_ms: 5000 } });
    return;
  }

  if (url.pathname.startsWith('/api/v1/app-preview/driver/assignments')) {
    const testCase = fixtureFromRequest(req, url);
    if (!testCase || testCase.app !== 'driver') {
      json(res, 403, { message: 'fixture_scope_rejected' });
      return;
    }
    json(res, 200, { data: [], meta: { scope: 'all', total: 0 } });
    return;
  }

  if (url.pathname.startsWith('/api/')) {
    json(res, 200, { data: [], meta: { fixture: true, total: 0 } });
    return;
  }

  const clean = decodeURIComponent(url.pathname).replace(/^\/+/, '');
  const target = path.resolve(root, clean || 'index.html');
  if (!target.startsWith(root + path.sep)) {
    res.writeHead(403); res.end('forbidden'); return;
  }
  try {
    const stat = await fs.stat(target);
    const file = stat.isDirectory() ? path.join(target, 'index.html') : target;
    const data = await fs.readFile(file);
    res.writeHead(200, {
      'content-type': mime[path.extname(file)] || 'application/octet-stream',
      'cache-control': 'no-store',
      'access-control-allow-origin': '*',
    });
    res.end(data);
  } catch {
    res.writeHead(404); res.end('not found');
  }
});

await new Promise((resolve) => server.listen(4173, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:4173';
const browser = await chromium.launch({ headless: true });
const diagnosticsByPage = new WeakMap();

function instrumentPage(page) {
  const diagnostics = {
    console: [],
    page_errors: [],
    request_failures: [],
    bad_responses: [],
    preview_requests: [],
  };
  diagnosticsByPage.set(page, diagnostics);
  page.on('console', (message) => {
    diagnostics.console.push({
      type: message.type(),
      text: message.text(),
    });
  });
  page.on('pageerror', (error) => {
    diagnostics.page_errors.push(String(error));
  });
  page.on('request', (request) => {
    const url = request.url();
    if (url.includes('/app-preview/') || url.includes('/admin/app-preview/')) {
      diagnostics.preview_requests.push({
        method: request.method(),
        url,
      });
    }
  });
  page.on('requestfailed', (request) => {
    diagnostics.request_failures.push({
      url: request.url(),
      failure: request.failure(),
    });
  });
  page.on('response', (response) => {
    if (response.status() >= 400) {
      diagnostics.bad_responses.push({
        status: response.status(),
        url: response.url(),
      });
    }
  });
}

async function stableScreenshot(target, page, { initialDelay = 600 } = {}) {
  await page.waitForTimeout(initialDelay);
  let previousHash = null;
  let latest = null;

  for (let attempt = 0; attempt < 8; attempt++) {
    await page.evaluate(() => new Promise((resolve) =>
      requestAnimationFrame(() => requestAnimationFrame(resolve))));
    latest = await target.screenshot();
    const hash = crypto.createHash('sha256').update(latest).digest('hex');
    if (hash === previousHash) return latest;
    previousHash = hash;
    await page.waitForTimeout(200);
  }

  throw new Error('preview_render_did_not_stabilize');
}

async function waitForState(page, testCase, surface) {
  try {
    await page.waitForFunction(({ state, code }) =>
      (window.__foodexMessages || []).some((m) =>
        m && m.type === 'foodex.preview.status' &&
        m.version === 'shared-flutter-v1' &&
        m.state === state &&
        (code == null || m.code === code)),
      { state: testCase.expectedState, code: testCase.expectedCode ?? null },
      { timeout: 20000 });
  } catch (error) {
    const browserDiagnostics = diagnosticsByPage.get(page) || {};
    const pageDiagnostics = await page.evaluate(() => ({
      messages: window.__foodexMessages || [],
      handshake: window.__foodexHandshake === true,
      text: document.body?.innerText || '',
      ready_state: document.readyState,
    }));
    const frames = await Promise.all(page.frames().map(async (frame) => {
      let readyState = null;
      let text = '';
      try {
        readyState = await frame.evaluate(() => document.readyState);
        text = await frame.evaluate(() => document.body?.innerText || '');
      } catch (_) {}
      return { url: frame.url(), ready_state: readyState, text };
    }));
    throw new Error(testCase.id + ': ' + surface + ' state timeout; diagnostics=' +
      JSON.stringify({ ...pageDiagnostics, frames, ...browserDiagnostics }) +
      '; cause=' + error.message);
  }

  const snapshot = await page.evaluate(({ state, code }) => {
    const status = [...(window.__foodexMessages || [])].reverse().find((m) =>
      m && m.type === 'foodex.preview.status' &&
      m.version === 'shared-flutter-v1' &&
      m.state === state &&
      (code == null || m.code === code));
    return { handshake: window.__foodexHandshake === true, status };
  }, { state: testCase.expectedState, code: testCase.expectedCode ?? null });

  if (!snapshot.handshake) {
    throw new Error(testCase.id + ': missing ' + surface + ' shared-flutter-v1 ready handshake');
  }

  if (testCase.expectedState === 'ready') {
    const metadata = snapshot.status?.metadata || {};
    if (metadata.target_type !== testCase.app ||
        metadata.channel !== testCase.channel ||
        Number(metadata.store_id) !== testCase.storeId ||
        metadata.locale !== testCase.locale ||
        metadata.configuration !== testCase.configuration ||
        metadata.device_profile !== testCase.profile ||
        Number(metadata.device_width) !== testCase.width) {
      throw new Error(testCase.id + ': runtime metadata does not match deterministic fixture');
    }
    if (testCase.app === 'customer' &&
        metadata.authenticated !== testCase.authenticated) {
      throw new Error(testCase.id + ': customer auth mode mismatch');
    }
    if (testCase.app === 'driver' && metadata.auth_mode !== 'preview-driver') {
      throw new Error(testCase.id + ': driver auth mode mismatch');
    }
  }

  return snapshot.status;
}

const report = [];
try {
  for (const testCase of cases) {
    const { app, width, height, profile, id, locale } = testCase;

    const browserLocale = testCase.locale === 'ar' ? 'ar-KW' : 'en-US';
    const standalone = await browser.newPage({
      viewport: { width, height },
      deviceScaleFactor: 1,
      locale: browserLocale,
    });
    instrumentPage(standalone);
    await standalone.goto(
      origin + '/standalone.html?fixture=' + encodeURIComponent(id),
      { waitUntil: 'networkidle' },
    );
    const standaloneStatus = await waitForState(standalone, testCase, 'standalone');
    const standaloneFrame = standalone.locator('#runtime');
    const standalonePng = await stableScreenshot(
      standaloneFrame,
      standalone,
      { initialDelay: 250 },
    );

    const embedded = await browser.newPage({
      viewport: { width: width + 40, height: height + 40 },
      deviceScaleFactor: 1,
      locale: browserLocale,
    });
    instrumentPage(embedded);
    await embedded.goto(origin + '/host.html?fixture=' + encodeURIComponent(id), { waitUntil: 'networkidle' });
    const embeddedStatus = await waitForState(embedded, testCase, 'embedded');
    const iframe = embedded.locator('#runtime');
    const embeddedPng = await stableScreenshot(iframe, embedded, { initialDelay: 250 });

    const a = PNG.sync.read(standalonePng);
    const b = PNG.sync.read(embeddedPng);
    if (a.width !== b.width || a.height !== b.height) {
      throw new Error(id + ': viewport mismatch ' + a.width + 'x' + a.height + ' vs ' + b.width + 'x' + b.height);
    }

    const diff = new PNG({ width: a.width, height: a.height });
    const changed = pixelmatch(a.data, b.data, diff.data, a.width, a.height, { threshold: 0.1 });
    const ratio = changed / (a.width * a.height);
    const prefix = id + '__' + profile + '__' + width + 'x' + height;

    await fs.writeFile(path.join(evidence, prefix + '__standalone.png'), standalonePng);
    await fs.writeFile(path.join(evidence, prefix + '__embedded.png'), embeddedPng);
    await fs.writeFile(path.join(evidence, prefix + '__diff.png'), PNG.sync.write(diff));

    const js = await fs.readFile(path.join(root, app, 'main.dart.js'));
    report.push({
      id,
      app,
      channel: testCase.channel,
      authenticated: testCase.authenticated,
      configuration: testCase.configuration,
      locale: testCase.locale,
      expected_state: testCase.expectedState,
      expected_code: testCase.expectedCode ?? null,
      profile,
      width,
      height,
      changed_pixels: changed,
      diff_ratio: ratio,
      runtime_sha256: crypto.createHash('sha256').update(js).digest('hex'),
      handshake: contractVersion,
      standalone_status: standaloneStatus.state,
      embedded_status: embeddedStatus.state,
    });

    await standalone.close();
    await embedded.close();

    if (ratio > 0.001) {
      throw new Error(id + ': embedded visual drift ' + (ratio * 100).toFixed(4) + '%');
    }
  }
} finally {
  await browser.close();
  await new Promise((resolve) => server.close(resolve));
}

await fs.writeFile(path.join(evidence, 'report.json'), JSON.stringify({
  schema: 'foodex.app-preview.visual-parity.v2',
  generated_at: new Date().toISOString(),
  cases: report,
}, null, 2) + '\n');
console.log(JSON.stringify(report, null, 2));
