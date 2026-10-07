import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';

const baseUrl =
  process.env.FOODEX_SCREENSHOT_BASE_URL ?? 'http://127.0.0.1:8000';
const root = path.resolve(process.cwd(), '..');
const screenshotRoot = path.join(root, 'ScreenShots', '02_Web', 'FieldOperations');

const cases = [
  {
    name: 'vans',
    route: '/admin/field-operations/vans',
    required: ['[data-van-transfer-lookup]', 'details.foodex-ops-actions summary'],
  },
  {
    name: 'assignments',
    route: '/admin/field-operations/assignments',
    required: ['[data-representative-lookup]', '[data-warehouse-lookup]'],
  },
  {
    name: 'visits',
    route: '/admin/field-operations/visits',
    required: [
      '[data-visit-customer]',
      '[data-store-lookup]',
      '[data-route-lookup]',
      '[data-order-lookup]',
    ],
  },
  {
    name: 'territories-map',
    route: '/admin/field-operations/territories',
    required: [
      '#fieldops-coverage-map',
      '#fieldops-coverage-undo',
      '#fieldops-coverage-clear',
      '[data-advanced-geojson]',
    ],
  },
  {
    name: 'address-quality',
    route: '/admin/field-operations/address-quality',
    required: ['[data-territory-lookup]', 'details.foodex-ops-actions summary'],
  },
];

const viewports = [
  ['desktop-1280', 1280, 900],
  ['tablet-768', 768, 900],
  ['mobile-390', 390, 844],
];

async function ensureDir(dir) {
  await fs.mkdir(dir, { recursive: true });
}

async function login(page, locale) {
  const email =
    locale === 'ar' ? 'screenshots@foodex.test' : 'screenshots.en@foodex.test';
  await page.goto(`${baseUrl}/admin/b2c/login?locale=${locale}`, {
    waitUntil: 'networkidle',
  });
  await page.fill('#email', email);
  await page.fill('#password', 'Evidence123!');
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click('button[type=submit]'),
  ]);
  if (page.url().includes('/login')) {
    throw new Error(`FieldOps login failed for ${locale}: ${page.url()}`);
  }
}

async function assertNoOverflow(page, label) {
  const metrics = await page.evaluate(() => ({
    viewport: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  if (metrics.scrollWidth > metrics.viewport + 2) {
    throw new Error(
      `Unexpected FieldOps overflow for ${label}: ${metrics.scrollWidth}px > ${metrics.viewport}px`,
    );
  }
}

async function captureCase(page, locale, entry, state, width, height) {
  await page.setViewportSize({ width, height });
  const response = await page.goto(`${baseUrl}${entry.route}`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
  if (!response || !response.ok()) {
    const failureBody = (await page.locator('body').innerText().catch(() => ''))
      .replace(/\\s+/g, ' ')
      .slice(0, 2400);
    throw new Error(
      `FieldOps evidence page failed: HTTP ${response?.status() ?? 'no-response'} on ${entry.route}`
      + (failureBody ? ` · ${failureBody}` : ''),
    );
  }
  if (page.url().includes('/login')) {
    throw new Error(`Unexpected auth redirect for ${entry.route}`);
  }

  for (const selector of entry.required) {
    const locator = page.locator(selector);
    if ((await locator.count()) < 1) {
      throw new Error(
        `Missing FieldOps runtime selector ${selector} on ${entry.route} (${locale}/${state})`,
      );
    }
  }

  if (entry.name === 'territories-map') {
    const advanced = page.locator('[data-advanced-geojson]');
    const isOpen = await advanced.evaluate((node) => node.open);
    if (isOpen) {
      throw new Error('Advanced GeoJSON must remain collapsed in the default map-first workflow.');
    }
  }

  await assertNoOverflow(page, `${entry.name}/${locale}/${state}`);

  const target = path.join(
    screenshotRoot,
    `${entry.name}__${state}__${locale}.png`,
  );
  await ensureDir(path.dirname(target));
  await page.screenshot({
    path: target,
    fullPage: true,
    animations: 'disabled',
  });
  const stat = await fs.stat(target);
  if (stat.size < 5000) {
    throw new Error(`FieldOps screenshot unexpectedly small: ${target}`);
  }
}

const browser = await chromium.launch({ headless: true });
try {
  for (const locale of ['ar', 'en']) {
    const context = await browser.newContext({
      locale: locale === 'ar' ? 'ar-KW' : 'en-US',
      viewport: { width: 1280, height: 900 },
      deviceScaleFactor: 1,
    });
    const page = await context.newPage();
    await login(page, locale);

    for (const entry of cases) {
      for (const [state, width, height] of viewports) {
        await captureCase(page, locale, entry, state, width, height);
      }
    }

    await context.close();
  }
} finally {
  await browser.close();
}
