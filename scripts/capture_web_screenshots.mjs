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
  ['11_التتبع_الحي_للسائقين_والفانات', '/admin/driver-live-tracking'],
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
  ['14_التحكم_التجاري_للمنتجات', '/admin/b2c/commercial/sales-control'],
  ['15_مركز_الإدارة', '/admin/administration'],
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
  const metrics = await page.evaluate(() => ({
    viewport: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  if (metrics.scrollWidth > metrics.viewport + 2) {
    throw new Error(`Unexpected horizontal page overflow for ${label}: ${metrics.scrollWidth}px > ${metrics.viewport}px`);
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



async function captureAdministrationRuntimeEvidence(page, locale) {
  const response = await page.goto(`${baseUrl}/admin/administration`, { waitUntil: 'networkidle' });
  if (!response || !response.ok()) {
    throw new Error(`Administration runtime evidence page failed: HTTP ${response?.status() ?? 'no-response'}`);
  }

  const proof = await page.evaluate(() => {
    const groups = [...document.querySelectorAll('[data-foodex-nav] [data-nav-group]')]
      .map((group) => group.getAttribute('data-nav-group'))
      .filter(Boolean);
    const administration = document.querySelector('[data-nav-group="administration"]');
    const adminLinks = administration
      ? [...administration.querySelectorAll('.foodex-nav-link')].map((link) => ({
          href: link.getAttribute('href') || '',
          label: (link.textContent || '').replace(/\s+/g, ' ').trim(),
        }))
      : [];
    const apps = [...document.querySelectorAll('[data-admin-app]')]
      .map((card) => ({
        app: card.getAttribute('data-admin-app'),
        actions: [...card.querySelectorAll('a.action')].map((action) => action.getAttribute('href') || ''),
      }));

    return { groups, adminLinks, apps, dir: document.documentElement.dir };
  });

  const expectedGroups = [
    'overview',
    'stores',
    'catalog',
    'accounts',
    'operations',
    'field_operations',
    'marketing',
    'advertising',
    'analytics',
    'applications',
    'administration',
  ];
  if (JSON.stringify(proof.groups) !== JSON.stringify(expectedGroups)) {
    throw new Error(`Sidebar business-group order mismatch: ${JSON.stringify(proof.groups)}`);
  }
  if (proof.adminLinks.length !== 1 || !proof.adminLinks[0].href.endsWith('/admin/administration')) {
    throw new Error(`Administration must expose one Admin Hub entry: ${JSON.stringify(proof.adminLinks)}`);
  }

  const appNames = proof.apps.map((item) => item.app);
  if (JSON.stringify(appNames) !== JSON.stringify(['customer', 'driver', 'van'])) {
    throw new Error(`Admin Hub app parity mismatch: ${JSON.stringify(appNames)}`);
  }
  for (const app of proof.apps) {
    const requiredFragments = [
      `/admin/app-preview?application=${app.app}`,
      `/admin/app-versions?app=${app.app}`,
      `/admin/settings/mobile?app=${app.app}&environment=production`,
    ];
    for (const fragment of requiredFragments) {
      if (!app.actions.some((href) => href.includes(fragment))) {
        throw new Error(`Missing ${app.app} Admin Hub action ${fragment}: ${JSON.stringify(app.actions)}`);
      }
    }
  }

  const expectedDir = locale === 'ar' ? 'rtl' : 'ltr';
  if (proof.dir !== expectedDir) {
    throw new Error(`Administration locale direction mismatch for ${locale}: ${proof.dir}`);
  }

  await assertNoPageOverflow(page, `administration/runtime/${locale}`);
  await snap(
    page,
    `02_Web/B2C_Admin/16_administration_runtime_contract__populated__${locale}.png`,
  );
}

async function captureMixedTrackingEvidence(page, locale) {
  const response = await page.goto(`${baseUrl}/admin/driver-live-tracking`, { waitUntil: 'networkidle' });
  if (!response || !response.ok()) {
    throw new Error(`Live Tracking evidence page failed: HTTP ${response?.status() ?? 'no-response'}`);
  }

  await page.waitForFunction(() => {
    const root = document.querySelector('[data-driver-live-map]');
    const rows = root?.foodexDriverLiveMap?.rows?.() ?? [];
    const kinds = new Set(rows.map((row) => row.actor_type || (row.actor_id != null ? 'van' : 'driver')));
    const statuses = new Set(rows.map((row) => row.status));
    return kinds.has('driver') && kinds.has('van') && statuses.has('stale') && statuses.has('online');
  }, null, { timeout: 15000 });

  const proof = await page.evaluate(() => {
    const root = document.querySelector('[data-driver-live-map]');
    const rows = root?.foodexDriverLiveMap?.rows?.() ?? [];
    return rows.map((row) => ({
      kind: row.actor_type || (row.actor_id != null ? 'van' : 'driver'),
      status: row.status,
      name: row.entity_name || row.driver_name || '',
    }));
  });

  const kinds = new Set(proof.map((row) => row.kind));
  const statuses = new Set(proof.map((row) => row.status));
  if (!kinds.has('driver') || !kinds.has('van') || !statuses.has('stale') || !statuses.has('online')) {
    throw new Error(`Mixed Live Tracking runtime evidence incomplete: ${JSON.stringify(proof)}`);
  }

  await assertNoPageOverflow(page, `live-tracking/runtime/${locale}`);
  await snap(
    page,
    `02_Web/B2B_SuperAdmin/11_live_tracking_mixed_runtime__populated__${locale}.png`,
  );
}

async function captureMobileSettingsParityEvidence(page, locale) {
  const response = await page.goto(
    `${baseUrl}/admin/settings/mobile?app=van&environment=production`,
    { waitUntil: 'networkidle' },
  );
  if (!response || !response.ok()) {
    throw new Error(
      `Mobile Settings evidence page failed: HTTP ${response?.status() ?? 'no-response'}`,
    );
  }

  const proof = await page.evaluate((expectedDir) => {
    const runtimeApp = document.querySelector('.runtime-picker select[name="app"]');
    const pushVan = document.querySelector(
      'form[action*="/admin/settings/mobile/push"] select[name="app"] option[value="van"]',
    );
    const submissionVan = document.querySelectorAll(
      '[data-store-submission-center] input[name="app"][value="van"]',
    );
    const reviewerVan = document.querySelector(
      '[data-reviewer-accounts] select[name="app"] option[value="van"]',
    );
    const forbiddenRoutineJson = [
      'deep_link_json',
      'store_readiness_json',
      'asset_checklist_json',
      'permission_declarations_json',
      'privacy_checklist_json',
      'manual_gaps_json',
      'context_json',
    ].filter((name) => document.querySelector(`[name="${name}"]`));

    return {
      dir: document.documentElement.dir,
      shell: Boolean(document.querySelector('.foodex-admin-layout')),
      main: Boolean(document.querySelector('main.foodex-admin-main')),
      header: Boolean(document.querySelector('header.foodex-page-header')),
      runtimeApp: runtimeApp?.value ?? '',
      pushVan: Boolean(pushVan),
      submissionVanCount: submissionVan.length,
      reviewerVan: Boolean(reviewerVan),
      forbiddenRoutineJson,
      dirMatches: document.documentElement.dir === expectedDir,
    };
  }, locale === 'ar' ? 'rtl' : 'ltr');

  if (
    !proof.shell
    || !proof.main
    || !proof.header
    || !proof.dirMatches
    || proof.runtimeApp !== 'van'
    || !proof.pushVan
    || proof.submissionVanCount !== 2
    || !proof.reviewerVan
    || proof.forbiddenRoutineJson.length
  ) {
    throw new Error(`Mobile Settings runtime parity evidence incomplete: ${JSON.stringify(proof)}`);
  }

  await assertNoPageOverflow(page, `mobile-settings/runtime/${locale}`);
  await snap(
    page,
    `02_Web/B2B_SuperAdmin/16_mobile_settings_van_parity__populated__${locale}.png`,
  );
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

  await login(page, 'b2b', locale, email);
  await captureMixedTrackingEvidence(page, locale);
  await captureMobileSettingsParityEvidence(page, locale);
  await context.clearCookies();

  await login(page, 'b2c', locale, email);

  for (const [name, route] of b2c) {
    await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) throw new Error(`Unexpected auth redirect for ${route}`);
    await snap(page, `02_Web/B2C_Admin/${name}__populated__${locale}.png`);
  }

  await captureAdministrationRuntimeEvidence(page, locale);

  await captureResponsiveRoute(page, locale, 'B2C_Admin', 'dashboard', '/admin/b2c/dashboard');
  await captureResponsiveRoute(page, locale, 'B2C_Admin', 'products', '/admin/b2c/products');
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'sales-control',
    '/admin/b2c/commercial/sales-control',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'administration-hub',
    '/admin/administration',
  );

  const customer360Response = await page.goto(
    `${baseUrl}/admin/customer-360?q=evidence.address%40foodex.test`,
    { waitUntil: 'networkidle' },
  );
  if (!customer360Response || !customer360Response.ok()) {
    throw new Error(
      `Customer 360 evidence page failed: HTTP ${customer360Response?.status() ?? 'no-response'}`,
    );
  }
  const customer360Link = page.locator(
    'a.foodex-action-primary[href^="/admin/customer-360/"], a.foodex-action-primary[href*="/admin/customer-360/"]',
  ).filter({ hasText: /Open 360|فتح 360/ }).first();
  if (await customer360Link.count() !== 1) {
    const bodyText = (await page.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 1200);
    throw new Error(
      `Deterministic Customer 360 evidence fixture was not found. Page: ${bodyText}`,
    );
  }
  const customer360Href = await customer360Link.getAttribute('href');
  if (!customer360Href) {
    throw new Error('Customer 360 evidence link did not expose a usable href.');
  }
  const customer360DetailResponse = await page.goto(
    new URL(customer360Href, baseUrl).toString(),
    { waitUntil: 'networkidle' },
  );
  if (!customer360DetailResponse || !customer360DetailResponse.ok()) {
    throw new Error(
      `Customer 360 detail evidence page failed: HTTP ${customer360DetailResponse?.status() ?? 'no-response'}`,
    );
  }
  const addressesTab = page.locator('[data-c360-tab="addresses"], #tab-addresses').first();
  if (await addressesTab.count() !== 1) {
    const bodyText = (await page.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 1200);
    throw new Error(`Customer 360 addresses tab was not rendered. Page: ${bodyText}`);
  }
  await addressesTab.click();
  const addressesPanel = page.locator('[data-c360-panel="addresses"]');
  await addressesPanel.waitFor({ state: 'visible' });
  await addressesPanel.scrollIntoViewIfNeeded();
  await snap(
    page,
    `02_Web/B2C_Admin/12_customer_360_addresses__populated__${locale}.png`,
  );

  await page.goto(
    `${baseUrl}/admin/operations/orders?channel=b2c&order_number=FOODEX-EVID-LOC-1`,
    { waitUntil: 'networkidle' },
  );
  const orderLocationLink = page.locator(
    'td a[href*="order="]',
    { hasText: 'FOODEX-EVID-LOC-1' },
  ).first();
  if (await orderLocationLink.count() !== 1) {
    throw new Error('Deterministic delivery-location order evidence was not found.');
  }
  await Promise.all([
    page.waitForLoadState('networkidle'),
    orderLocationLink.click(),
  ]);
  const mapAction = page.locator('a[href*="google.com/maps/search/"]').first();
  if (await mapAction.count() !== 1) {
    throw new Error('Order Operations map action was not rendered.');
  }
  await mapAction.scrollIntoViewIfNeeded();
  await snap(
    page,
    `02_Web/B2C_Admin/13_order_delivery_location__populated__${locale}.png`,
  );

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
  await captureResponsiveRoute(
    page,
    locale,
    'B2B_SuperAdmin',
    'live-tracking',
    '/admin/driver-live-tracking',
  );

  await context.close();
}

const browser = await chromium.launch({ headless: true });
try {
  await captureLocale(browser, 'ar');
  await captureLocale(browser, 'en');
} finally {
  await browser.close();
}
