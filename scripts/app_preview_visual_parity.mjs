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

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', 'http://127.0.0.1:4173');
  if (url.pathname === '/host.html') {
    const app = url.searchParams.get('app');
    const width = Number(url.searchParams.get('width') || 390);
    const height = Number(url.searchParams.get('height') || 844);
    if (!['customer', 'driver'].includes(app)) {
      res.writeHead(400); res.end('bad app'); return;
    }
    const body = `<!doctype html><html><body style="margin:0;background:white;overflow:hidden">
<iframe id="runtime" src="/${app}/index.html" width="${width}" height="${height}" style="display:block;border:0"></iframe>
<script>
window.__foodexReady = false;
addEventListener('message', (event) => {
  if (event.origin !== location.origin || event.source !== document.getElementById('runtime').contentWindow) return;
  if (event.data && event.data.type === 'foodex.preview.ready' && event.data.version === 'shared-flutter-v1') {
    window.__foodexReady = true;
  }
});
</script></body></html>`;
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    res.end(body);
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
const browser = await chromium.launch({ headless: true });

const cases = [
  { app: 'customer', width: 360, height: 800, profile: 'small-android' },
  { app: 'customer', width: 390, height: 844, profile: 'iphone-common' },
  { app: 'customer', width: 430, height: 900, profile: 'large-android' },
  { app: 'driver', width: 360, height: 800, profile: 'small-android' },
  { app: 'driver', width: 390, height: 844, profile: 'iphone-common' },
];

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

const report = [];
try {
  for (const testCase of cases) {
    const { app, width, height, profile } = testCase;
    const standalone = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 1 });
    await standalone.goto(`http://127.0.0.1:4173/${app}/index.html`, { waitUntil: 'networkidle' });
    const standalonePng = await stableScreenshot(standalone, standalone);

    const embedded = await browser.newPage({ viewport: { width: width + 40, height: height + 40 }, deviceScaleFactor: 1 });
    await embedded.goto(`http://127.0.0.1:4173/host.html?app=${app}&width=${width}&height=${height}`, { waitUntil: 'networkidle' });
    await embedded.waitForFunction(() => window.__foodexReady === true, null, { timeout: 15000 });
    const iframe = embedded.locator('#runtime');
    const embeddedPng = await stableScreenshot(iframe, embedded, { initialDelay: 250 });

    const a = PNG.sync.read(standalonePng);
    const b = PNG.sync.read(embeddedPng);
    if (a.width !== b.width || a.height !== b.height) {
      throw new Error(`${app}/${profile}: viewport mismatch ${a.width}x${a.height} vs ${b.width}x${b.height}`);
    }
    const diff = new PNG({ width: a.width, height: a.height });
    const changed = pixelmatch(a.data, b.data, diff.data, a.width, a.height, { threshold: 0.1 });
    const ratio = changed / (a.width * a.height);

    const prefix = `${app}__${profile}__${width}x${height}`;
    await fs.writeFile(path.join(evidence, `${prefix}__standalone.png`), standalonePng);
    await fs.writeFile(path.join(evidence, `${prefix}__embedded.png`), embeddedPng);
    await fs.writeFile(path.join(evidence, `${prefix}__diff.png`), PNG.sync.write(diff));

    const js = await fs.readFile(path.join(root, app, 'main.dart.js'));
    report.push({
      app, profile, width, height, changed_pixels: changed, diff_ratio: ratio,
      runtime_sha256: crypto.createHash('sha256').update(js).digest('hex'),
      handshake: 'shared-flutter-v1',
    });
    await standalone.close();
    await embedded.close();

    if (ratio > 0.001) {
      throw new Error(`${app}/${profile}: embedded visual drift ${(ratio * 100).toFixed(4)}%`);
    }
  }
} finally {
  await browser.close();
  await new Promise((resolve) => server.close(resolve));
}

await fs.writeFile(path.join(evidence, 'report.json'), JSON.stringify({
  schema: 'foodex.app-preview.visual-parity.v1',
  generated_at: new Date().toISOString(),
  cases: report,
}, null, 2) + '\n');
console.log(JSON.stringify(report, null, 2));
