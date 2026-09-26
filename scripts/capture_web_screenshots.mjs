import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';

const baseUrl = process.env.FOODEX_SCREENSHOT_BASE_URL ?? 'http://127.0.0.1:8000';
const root = path.resolve(process.cwd(), '..');
const screenshotRoot = path.join(root, 'ScreenShots');

const b2b = [
  ['02_لوحة_التحكم_الرئيسية', '/admin/b2b/dashboard'],
  ['03_إدارة_المتاجر_وفروع_الجملة', '/admin/b2b/stores'],
  ['04_إدارة_عملاء_الجملة_B2B', '/admin/b2b/clients'],
  ['05_إدارة_المنتجات_والمخزون', '/admin/b2b/products'],
  ['06_إدارة_الطلبات', '/admin/b2b/orders'],
  ['07_إدارة_السائقين_والتوصيل', '/admin/b2b/drivers'],
  ['08_التسعير_والموافقات', '/admin/b2b/pricing-approvals'],
  ['09_التقارير_والتحليلات', '/admin/b2b/reports'],
  ['10_إعدادات_المنصة_والصلاحيات', '/admin/b2b/settings-permissions'],
];

const b2c = [
  ['02_لوحة_التحكم_الرئيسية_B2C', '/admin/b2c/dashboard'],
  ['03_إدارة_المنتجات', '/admin/b2c/products'],
  ['04_إدارة_المخزون', '/admin/b2c/inventory'],
  ['05_إدارة_الطلبات', '/admin/b2c/orders'],
  ['06_إدارة_العملاء', '/admin/b2c/customers'],
  ['07_العروض_والخصومات', '/admin/b2c/promotions'],
  ['08_التوصيل_والسائقين', '/admin/b2c/drivers'],
  ['09_واجهة_المتجر_ومعاينة_المتجر', '/admin/b2c/storefront-preview'],
  ['10_المحتوى_والبانرات', '/admin/b2c/content'],
  ['11_التقارير_والتحليلات_وإعدادات_المتجر', '/admin/b2c/reports'],
];

async function ensureDir(dir) {
  await fs.mkdir(dir, { recursive: true });
}

async function snap(page, relativePath) {
  const target = path.join(screenshotRoot, relativePath);
  await ensureDir(path.dirname(target));
  await page.screenshot({ path: target, fullPage: true, animations: 'disabled' });
  const stat = await fs.stat(target);
  if (stat.size < 5000) throw new Error(`Screenshot unexpectedly small: ${relativePath}`);
  console.log(`captured ${relativePath} (${stat.size} bytes)`);
}

async function login(page, channel, locale, email) {
  await page.goto(`${baseUrl}/admin/${channel}/login?locale=${locale}`, { waitUntil: 'networkidle' });
  await page.fill('#email', email);
  await page.fill('#password', 'Evidence123!');
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click('button[type=submit]'),
  ]);
  if (page.url().includes('/login')) {
    throw new Error(`Login failed for ${channel}/${locale}: ${page.url()}`);
  }
}

async function assertNoPageOverflow(page, label) {
  const metrics = await page.evaluate(() => {
    const kpiViolations = [...document.querySelectorAll('.kpi')].flatMap((card, index) => {
      const value = card.querySelector('.kpi-value');
      if (!value) return [];
      const cardRect = card.getBoundingClientRect();
      const valueRect = value.getBoundingClientRect();
      const tolerance = 1;
      return valueRect.left < cardRect.left - tolerance || valueRect.right > cardRect.right + tolerance
        ? [{ index, cardLeft: cardRect.left, cardRight: cardRect.right, valueLeft: valueRect.left, valueRight: valueRect.right }]
        : [];
    });
    return {
      viewport: window.innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      kpiViolations,
    };
  });
  if (metrics.scrollWidth > metrics.viewport + 2) {
    throw new Error(`Unexpected horizontal page overflow for ${label}: ${metrics.scrollWidth}px > ${metrics.viewport}px`);
  }
  if (metrics.kpiViolations.length) {
    throw new Error(`KPI content escaped its card for ${label}: ${JSON.stringify(metrics.kpiViolations)}`);
  }
}

async function captureResponsiveRoute(page, locale, channel, name, route) {
  const viewports = [
    ['desktop-1280', 1280, 900],
    ['compact-1024', 1024, 900],
    ['tablet-768', 768, 900],
    ['mobile-390', 390, 844],
  ];
  for (const [state, width, height] of viewports) {
    await page.setViewportSize({ width, height });
    await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) throw new Error(`Unexpected auth redirect for responsive ${route}`);
    await assertNoPageOverflow(page, `${channel}/${route}/${locale}/${state}`);
    await snap(page, `02_Web/Responsive/${channel}/${name}__${state}__${locale}.png`);
  }
}

async function captureLocale(browser, locale) {
  const context = await browser.newContext({
    locale: locale === 'ar' ? 'ar-KW' : 'en-US',
    viewport: { width: 1440, height: 1080 },
    deviceScaleFactor: 1,
  });
  const page = await context.newPage();
  const email = locale === 'ar' ? 'screenshots@foodex.test' : 'screenshots.en@foodex.test';

  await page.goto(`${baseUrl}/admin/b2b/login?locale=${locale}`, { waitUntil: 'networkidle' });
  await snap(page, `02_Web/B2B_SuperAdmin/01_تسجيل_الدخول_B2B__default__${locale}.png`);

  await page.goto(`${baseUrl}/admin/b2c/login?locale=${locale}`, { waitUntil: 'networkidle' });
  await snap(page, `02_Web/B2C_Admin/01_تسجيل_الدخول_B2C__default__${locale}.png`);

  await login(page, 'b2c', locale, email);

  for (const [name, route] of b2c) {
    await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) throw new Error(`Unexpected auth redirect for ${route}`);
    await snap(page, `02_Web/B2C_Admin/${name}__populated__${locale}.png`);
  }

  await captureResponsiveRoute(page, locale, 'B2C_Admin', 'dashboard', '/admin/b2c/dashboard');
  await captureResponsiveRoute(page, locale, 'B2C_Admin', 'products', '/admin/b2c/products');

  // Capture B2B with an independent authenticated session.
  await context.clearCookies();
  await login(page, 'b2b', locale, email);

  for (const [name, route] of b2b) {
    await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) throw new Error(`Unexpected auth redirect for ${route}`);
    await snap(page, `02_Web/B2B_SuperAdmin/${name}__populated__${locale}.png`);
  }

  await captureResponsiveRoute(page, locale, 'B2B_SuperAdmin', 'dashboard', '/admin/b2b/dashboard');
  await captureResponsiveRoute(page, locale, 'B2B_SuperAdmin', 'orders', '/admin/b2b/orders');

  await context.close();
}

const browser = await chromium.launch({ headless: true });
try {
  await captureLocale(browser, 'ar');
  await captureLocale(browser, 'en');
} finally {
  await browser.close();
}
