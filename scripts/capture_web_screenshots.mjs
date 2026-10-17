import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const baseUrl = process.env.FOODEX_SCREENSHOT_BASE_URL ?? 'http://127.0.0.1:8000';
const root = path.resolve(process.cwd(), '..');
const screenshotRoot = path.join(root, 'ScreenShots');

function refreshTimeSensitiveEvidence() {
  execFileSync(
    'php',
    ['artisan', 'db:seed', '--class=Database\\Seeders\\RefreshTimeSensitiveVisualEvidenceSeeder', '--force'],
    {
      cwd: process.cwd(),
      stdio: 'inherit',
      env: {
        ...process.env,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: path.join(process.cwd(), 'database/database.sqlite'),
        CACHE_STORE: 'array',
        QUEUE_CONNECTION: 'sync',
        SESSION_DRIVER: 'file',
      },
    },
  );
}

const b2b = [
  ['12_whatsapp_foundation', '/admin/settings/whatsapp'],
  ['13_whatsapp_connections', '/admin/settings/whatsapp/connections'],
  ['13_whatsapp_agent_inbox', '/admin/whatsapp/inbox'],
  ['14_whatsapp_supervisor', '/admin/whatsapp/supervisor'],
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
  ['23_مركز_عمل_المشتريات_ERP', '/admin/erp/procurement'],
  ['24_مركز_عمل_الخزينة_ERP', '/admin/erp/treasury'],
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
  ['14_التحكم_التجاري_للمنتجات', '/admin/b2b/commercial/sales-control'],
  ['15_العروض_السريعة', '/admin/b2c/commercial/flash-offers'],
  ['15_العروض_السريعة', '/admin/b2c/commercial/flash-offers'],
  ['15_مركز_الإدارة', '/admin/administration'],
  ['15_مركز_الإجراءات', '/admin/action-center'],
  ['15_مركز_ERP', '/admin/erp'],
  ['15_مركز_عمل_المخزون_WMS_ERP', '/admin/erp/wms'],
  ['15_مساحات_عمل_المؤسسة', '/admin/workspaces'],
  ['15_مركز_ذكاء_الأعمال_ERP', '/admin/erp/bi'],
  ['15_مساحة_عمل_المحاسب_ERP', '/admin/erp/finance?store_code=FOODEX-DEMO-B2C'],
  ['15_مساحة_عمل_خدمة_العملاء', '/admin/erp/customer-service'],
  ['16_إدارة_الهيكل_المؤسسي', '/admin/administration/organizations'],
  ['16_صحة_الوحدات_والنظام', '/admin/administration/system-health'],
  ['16_إدارة_وحدات_المنصة', '/admin/administration/modules'],
  ['16_مركز_الإعدادات', '/admin/settings'],
  ['17_إعادة_تهيئة_بيانات_FOODEX', '/admin/settings/foodex-reset'],
  ['18_بوابة_إطلاق_الإنتاج', '/admin/settings/production-launch'],
  ['19_التبديل_بين_بيانات_الإنتاج_والقديمة', '/admin/settings/production-dataset'],
  ['21_منشئ_قائمة_الطلب_الذكي', '/admin/smart-ordering/menu?support_access=1'],
  ['22_ذكاء_توريد_FOODEX', '/admin/smart-ordering/supply?support_access=1'],
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
  await page.fill('#password', ['Evidence','123!'].join(''));
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

async function assertLocaleDirection(page, locale, label) {
  const proof = await page.evaluate(() => ({
    direction: getComputedStyle(document.documentElement).direction,
    dirAttribute: document.documentElement.getAttribute('dir'),
    lang: document.documentElement.getAttribute('lang'),
    title: document.title,
    body: (document.body?.innerText || '').replace(/\\s+/g, ' ').slice(0, 320),
  }));
  const expected = locale === 'ar' ? 'rtl' : 'ltr';
  if (proof.direction !== expected) {
    throw new Error(
      `Unexpected document direction for ${label}: ${proof.direction} (expected ${expected}); `
      + `runtime=${JSON.stringify(proof)} url=${page.url()}`,
    );
  }
}

async function assertVisibleActionsInViewport(page, label) {
  const clipped = await page.evaluate(() => {
    const root = document.querySelector('.foodex-admin-main, main') || document.body;
    const selectors = 'button, a.btn, a.foodex-btn, input[type="submit"], [role="button"]';
    const reachableHorizontalScroller = (element) => {
      let parent = element.parentElement;
      while (parent && parent !== root.parentElement) {
        const style = getComputedStyle(parent);
        const rect = parent.getBoundingClientRect();
        const scrollable = ['auto', 'scroll'].includes(style.overflowX)
          && parent.scrollWidth > parent.clientWidth + 2
          && rect.left >= -2 && rect.right <= window.innerWidth + 2;
        if (scrollable) return true;
        if (parent === root) break;
        parent = parent.parentElement;
      }
      return false;
    };
    return [...root.querySelectorAll(selectors)].filter((element) => {
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      const visible = !element.hidden && style.display !== 'none' && style.visibility !== 'hidden'
        && rect.width > 0 && rect.height > 0
        && rect.bottom > 0 && rect.top < window.innerHeight;
      return visible
        && !reachableHorizontalScroller(element)
        && (rect.left < -2 || rect.right > window.innerWidth + 2);
    }).slice(0, 8).map((element) => {
      const ancestry = [];
      let parent = element.parentElement;
      while (parent && ancestry.length < 4) {
        const rect = parent.getBoundingClientRect();
        const style = getComputedStyle(parent);
        ancestry.push({
          tag: parent.tagName.toLowerCase(),
          className: String(parent.className || '').slice(0, 120),
          rect: {left: rect.left, right: rect.right, width: rect.width},
          overflowX: style.overflowX,
          display: style.display,
          gridTemplateColumns: style.gridTemplateColumns,
          minWidth: style.minWidth,
          maxWidth: style.maxWidth,
        });
        if (parent === root) break;
        parent = parent.parentElement;
      }
      return {
        text: (element.getAttribute('aria-label') || element.textContent || '').trim().slice(0, 80),
        rect: element.getBoundingClientRect().toJSON(),
        viewport: {width: window.innerWidth, height: window.innerHeight},
        ancestry,
      };
    });
  });
  if (clipped.length) {
    throw new Error(`Visible action clipped outside viewport for ${label}: ${JSON.stringify(clipped)}`);
  }
}

async function captureResponsiveRoute(page, locale, channel, name, route, options = {}) {
  const representativeViewports = [
    ['desktop-1280', 1280, 900],
    ['compact-1024', 1024, 900],
    ['tablet-768', 768, 900],
    ['mobile-390', 390, 844],
    ['mobile-320', 320, 844],
  ];
  const fullViewports = [
    ['desktop-1280', 1280, 900],
    ['compact-1024', 1024, 900],
    ['tablet-768', 768, 900],
    ['mobile-430', 430, 932],
    ['mobile-412', 412, 915],
    ['mobile-390', 390, 844],
    ['mobile-360', 360, 800],
    ['mobile-320', 320, 844],
  ];
  const viewports = options.fullMatrix
    ? fullViewports
    : (process.env.FOODEX_RESPONSIVE_PROFILE === 'full' ? fullViewports : representativeViewports);
  for (const [state, width, height] of viewports) {
    await page.setViewportSize({ width, height });
    const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
    if (page.url().includes('/login')) throw new Error(`Unexpected auth redirect for responsive ${route}`);
    if (!response || !response.ok()) {
      const body = (await page.locator('body').innerText().catch(() => ''))
        .replace(/\\s+/g, ' ')
        .slice(0, 800);
      throw new Error(
        `Responsive runtime route failed for ${channel}/${route}/${locale}/${state}: `
        + `HTTP ${response?.status() ?? 'no-response'} ${page.url()}`
        + (body ? ` · ${body}` : ''),
      );
    }
    await assertNoPageOverflow(page, `${channel}/${route}/${locale}/${state}`);
    await assertLocaleDirection(page, locale, `${channel}/${route}/${locale}/${state}`);
    await assertVisibleActionsInViewport(page, `${channel}/${route}/${locale}/${state}`);
    if (['foodex-reset', 'production-launch'].includes(name) && width < 768) {
      const sidebar = page.locator('aside.sidebar');
      const closed = await sidebar.boundingBox();
      if (closed && closed.x < width - 1 && closed.x + closed.width > 1) {
        throw new Error(`Closed mobile drawer covers protected settings content: ${name}/${locale}/${width}`);
      }
      await page.locator('.foodex-mobile-nav-launcher').click();
      await page.waitForFunction(({ locale, width }) => {
        const rect = document.querySelector('aside.sidebar').getBoundingClientRect();
        return locale === 'ar' ? Math.abs(rect.right - width) < 2 : Math.abs(rect.left) < 2;
      }, { locale, width });
      const drawerOpenProof = await page.evaluate(() => {
        const drawer = document.querySelector('aside.sidebar');
        const main = document.querySelector('.foodex-admin-main, main');
        const launcher = document.querySelector('.foodex-mobile-nav-launcher');
        const closeControl = drawer?.querySelector('[data-foodex-sidebar-toggle]');
        return {
          activeInside: Boolean(drawer?.contains(document.activeElement)),
          ariaHidden: drawer?.getAttribute('aria-hidden'),
          ariaModal: drawer?.getAttribute('aria-modal'),
          mainInert: Boolean(main?.hasAttribute('inert')),
          launcherExpanded: launcher?.getAttribute('aria-expanded'),
          launcherLabel: launcher?.getAttribute('aria-label'),
          closeLabel: closeControl?.getAttribute('aria-label'),
        };
      });
      const expectedCloseLabel = locale === 'ar' ? 'إغلاق قائمة التنقل' : 'Close navigation';
      if (
        !drawerOpenProof.activeInside
        || drawerOpenProof.ariaHidden !== 'false'
        || drawerOpenProof.ariaModal !== 'true'
        || !drawerOpenProof.mainInert
        || drawerOpenProof.launcherExpanded !== 'true'
        || drawerOpenProof.launcherLabel !== expectedCloseLabel
        || drawerOpenProof.closeLabel !== expectedCloseLabel
      ) {
        throw new Error(`Mobile drawer accessibility open state failed: ${name}/${locale}/${width} ${JSON.stringify(drawerOpenProof)}`);
      }

      await page.evaluate(() => {
        const drawer = document.querySelector('aside.sidebar');
        const focusable = [...drawer.querySelectorAll(
          'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary,[tabindex]:not([tabindex="-1"])'
        )].filter((element) => {
          const style = getComputedStyle(element);
          return !element.hidden && !element.closest('[inert]') && style.display !== 'none'
            && style.visibility !== 'hidden' && element.getClientRects().length > 0;
        });
        focusable.at(-1)?.focus();
      });
      await page.keyboard.press('Tab');
      if (!(await page.evaluate(() => document.querySelector('aside.sidebar')?.contains(document.activeElement)))) {
        throw new Error(`Mobile drawer Tab escaped the active drawer: ${name}/${locale}/${width}`);
      }

      await page.evaluate(() => {
        const drawer = document.querySelector('aside.sidebar');
        const focusable = [...drawer.querySelectorAll(
          'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary,[tabindex]:not([tabindex="-1"])'
        )].filter((element) => {
          const style = getComputedStyle(element);
          return !element.hidden && !element.closest('[inert]') && style.display !== 'none'
            && style.visibility !== 'hidden' && element.getClientRects().length > 0;
        });
        focusable[0]?.focus();
      });
      await page.keyboard.press('Shift+Tab');
      if (!(await page.evaluate(() => document.querySelector('aside.sidebar')?.contains(document.activeElement)))) {
        throw new Error(`Mobile drawer Shift+Tab escaped the active drawer: ${name}/${locale}/${width}`);
      }

      await page.keyboard.press('Escape');
      await page.waitForFunction(() => {
        const rect = document.querySelector('aside.sidebar').getBoundingClientRect();
        return rect.left >= innerWidth - 1 || rect.right <= 1;
      });
      const drawerClosedProof = await page.evaluate(() => {
        const drawer = document.querySelector('aside.sidebar');
        const main = document.querySelector('.foodex-admin-main, main');
        const launcher = document.querySelector('.foodex-mobile-nav-launcher');
        return {
          drawerInert: Boolean(drawer?.hasAttribute('inert')),
          ariaHidden: drawer?.getAttribute('aria-hidden'),
          mainInert: Boolean(main?.hasAttribute('inert')),
          launcherFocused: launcher === document.activeElement,
          launcherExpanded: launcher?.getAttribute('aria-expanded'),
          launcherLabel: launcher?.getAttribute('aria-label'),
        };
      });
      const expectedOpenLabel = locale === 'ar' ? 'فتح قائمة التنقل' : 'Open navigation';
      if (
        !drawerClosedProof.drawerInert
        || drawerClosedProof.ariaHidden !== 'true'
        || drawerClosedProof.mainInert
        || !drawerClosedProof.launcherFocused
        || drawerClosedProof.launcherExpanded !== 'false'
        || drawerClosedProof.launcherLabel !== expectedOpenLabel
      ) {
        throw new Error(`Mobile drawer accessibility closed state failed: ${name}/${locale}/${width} ${JSON.stringify(drawerClosedProof)}`);
      }

      if (width === 430) {
        const viewport = page.viewportSize();
        await page.setViewportSize({ width: 768, height: viewport.height });
        await page.waitForFunction(() => {
          const drawer = document.querySelector('aside.sidebar');
          return drawer
            && !drawer.hasAttribute('inert')
            && !drawer.hasAttribute('aria-hidden')
            && !drawer.hasAttribute('aria-modal');
        });
        await page.setViewportSize({ width, height: viewport.height });
        await page.waitForFunction(() => {
          const drawer = document.querySelector('aside.sidebar');
          return drawer?.hasAttribute('inert') && drawer.getAttribute('aria-hidden') === 'true';
        });
      }
      for (const field of ['confirmation', 'current_password']) {
        const placeholder = await page.locator(`input[name="${field}"]`).getAttribute('placeholder');
        if (!placeholder || placeholder.includes(field)) {
          throw new Error(`Unlocalized protected-operation placeholder: ${name}/${locale}/${field}`);
        }
      }
    }
    await snap(page, `02_Web/Responsive/${channel}/${name}__${state}__${locale}.png`);
  }
}





async function assertSharedAdminRuntimeShell(page, label) {
  const proof = await page.evaluate(() => ({
    layout: Boolean(document.querySelector('.foodex-admin-layout')),
    main: Boolean(document.querySelector('.foodex-admin-main')),
    header: Boolean(document.querySelector('.foodex-page-header')),
    nav: Boolean(document.querySelector('[data-foodex-nav]')),
    routineJsonEditors: [...document.querySelectorAll('textarea[name$="_json"],input[name$="_json"]')]
      .map((field) => field.getAttribute('name'))
      .filter((name) => name && name !== 'credentials_json'),
    numericIdInputs: [...document.querySelectorAll('input[type="number"][name$="_id"]')]
      .map((field) => field.getAttribute('name'))
      .filter(Boolean),
  }));

  if (!proof.layout || !proof.main || !proof.header || !proof.nav) {
    throw new Error(`Shared admin shell contract failed for ${label}: ${JSON.stringify(proof)}`);
  }
  if (proof.routineJsonEditors.length > 0) {
    throw new Error(`Routine JSON editor returned for ${label}: ${JSON.stringify(proof.routineJsonEditors)}`);
  }
  if (proof.numericIdInputs.length > 0) {
    throw new Error(`Routine numeric ID input returned for ${label}: ${JSON.stringify(proof.numericIdInputs)}`);
  }

  await assertNoPageOverflow(page, label);
}

async function openFirstRecordActionMenu(page, selector, label) {
  const details = page.locator(selector).first();
  if (await details.count() !== 1) {
    throw new Error(`Missing runtime record-action menu for ${label}`);
  }
  const summary = details.locator('summary').first();
  await summary.click();
  if (!(await details.evaluate((node) => node.hasAttribute('open')))) {
    throw new Error(`Record-action menu did not open for ${label}`);
  }
  const menu = page.locator('.row-action-menu[data-foodex-action-portal="1"], .row-action-menu').filter({ visible: true }).first();
  await menu.waitFor({ state: 'visible' });
}

async function exerciseOrderWizardDraftSafety(page, locale) {
  const openWizard = async () => {
    const openButton = page.locator('[data-new-order-open]').first();
    if (await openButton.count() !== 1) {
      throw new Error(`Order wizard launch action missing (${locale})`);
    }
    await openButton.click();
    const modal = page.locator('[data-new-order-modal]');
    if ((await modal.getAttribute('data-open')) !== '1') {
      throw new Error(`Order wizard did not open (${locale})`);
    }
    return modal;
  };

  const setDraftCoupon = async (modal, value) => {
    const coupon = modal.locator('input[name="coupon_code"]');
    if (await coupon.count() !== 1) {
      throw new Error(`Order wizard coupon field missing (${locale})`);
    }
    await coupon.evaluate((node, draftValue) => {
      node.value = draftValue;
      node.dispatchEvent(new Event('input', { bubbles: true }));
    }, value);
  };

  await page.setViewportSize({ width: 1280, height: 900 });
  let response = await page.goto(`${baseUrl}/admin/operations/orders`, { waitUntil: 'networkidle' });
  if (!response || !response.ok() || page.url().includes('/login')) {
    throw new Error(`Order wizard evidence route failed (${locale})`);
  }

  let modal = await openWizard();

  const headerClose = modal.locator('[data-new-order-cancel]').first();
  await headerClose.click();
  if ((await modal.getAttribute('data-open')) !== '0') {
    throw new Error(`Pristine order wizard did not close directly (${locale})`);
  }

  modal = await openWizard();
  await modal.evaluate((root) => {
    root.querySelectorAll('[data-wizard-step]').forEach((node) => {
      node.dataset.active = node.dataset.wizardStep === '2' ? '1' : '0';
    });
    root.querySelectorAll('[data-wizard-progress]').forEach((node) => {
      const index = Number(node.dataset.wizardProgress);
      node.dataset.active = index === 2 ? '1' : '0';
      node.dataset.complete = index < 2 ? '1' : '0';
    });
  });

  const validationMessages = locale === 'ar'
    ? {
        'items.0.quantity': ['أدخل كمية صحيحة.'],
        coupon_code: ['راجع رمز الخصم.'],
      }
    : {
        'items.0.quantity': ['Enter a valid quantity.'],
        coupon_code: ['Review the coupon code.'],
      };
  await page.evaluate((messages) => {
    if (!window.FoodexValidationA11y?.showFeedback) {
      throw new Error('Shared server-validation accessibility runtime is unavailable.');
    }
    window.FoodexValidationA11y.showFeedback('error', messages);
  }, validationMessages);

  const validationModal = page.locator('.foodex-feedback-modal');
  await validationModal.waitFor({ state: 'visible' });
  const quantityField = modal.locator('input[name="items[0][quantity]"]');
  const couponField = modal.locator('input[name="coupon_code"]');
  if (!(await quantityField.isVisible())) {
    throw new Error(`Nested quantity evidence field is not visible in the active wizard step (${locale})`);
  }
  for (const [field, key] of [[quantityField, 'items.0.quantity'], [couponField, 'coupon_code']]) {
    if ((await field.getAttribute('aria-invalid')) !== 'true') {
      throw new Error(`Server validation did not mark ${key} invalid (${locale})`);
    }
    const describedBy = (await field.getAttribute('aria-describedby')) || '';
    const errorMessage = (await field.getAttribute('aria-errormessage')) || '';
    if (!describedBy.split(/\s+/).includes(errorMessage) || !errorMessage) {
      throw new Error(`Server validation did not associate ${key} with a stable inline error (${locale})`);
    }
    if (await page.locator('#'+errorMessage).count() !== 1) {
      throw new Error(`Server validation inline error is missing for ${key} (${locale})`);
    }
  }

  const quantityLink = validationModal.locator('[data-foodex-validation-link="items.0.quantity"]');
  const couponLink = validationModal.locator('[data-foodex-validation-link="coupon_code"]');
  if (await quantityLink.count() !== 1) {
    throw new Error(`Visible nested server validation error was not linked (${locale})`);
  }
  if (await couponLink.count() !== 0) {
    throw new Error(`Hidden wizard field was linked instead of remaining a plain summary message (${locale})`);
  }
  if (await validationModal.getByText(validationMessages.coupon_code[0], { exact: true }).count() !== 1) {
    throw new Error(`Hidden coupon validation message was lost from the summary (${locale})`);
  }

  await quantityLink.click();
  await validationModal.waitFor({ state: 'detached' });
  if (!(await quantityField.evaluate((node) => node === document.activeElement))) {
    throw new Error(`Server validation summary link did not focus the visible nested business field (${locale})`);
  }

  await quantityField.fill('2');
  if (await quantityField.getAttribute('aria-invalid')) {
    throw new Error(`Correcting nested quantity left stale invalid state (${locale})`);
  }
  if ((await couponField.getAttribute('aria-invalid')) !== 'true') {
    throw new Error(`Correcting one field cleared an unrelated server validation error (${locale})`);
  }
  await page.evaluate(() => window.FoodexValidationA11y.clear());
  if (await couponField.getAttribute('aria-invalid')) {
    throw new Error(`Explicit server validation reset left stale coupon state (${locale})`);
  }

  await modal.locator('[data-new-order-cancel]').first().click();
  const validationDiscard = modal.locator('[data-new-order-discard]');
  if ((await validationDiscard.getAttribute('data-visible')) !== '1') {
    throw new Error(`Validation evidence edit did not preserve the wizard dirty-state contract (${locale})`);
  }
  await validationDiscard.locator('[data-new-order-discard-confirm]').click();
  if ((await modal.getAttribute('data-open')) !== '0') {
    throw new Error(`Validation evidence draft was not discarded cleanly (${locale})`);
  }

  modal = await openWizard();
  await setDraftCoupon(modal, 'DRAFT-KEEP');
  await modal.locator('[data-new-order-cancel]').first().click();

  let discardPanel = modal.locator('[data-new-order-discard]');
  if (
    (await discardPanel.getAttribute('data-visible')) !== '1'
    || (await discardPanel.getAttribute('aria-hidden')) !== 'false'
  ) {
    throw new Error(`Dirty order wizard did not require discard confirmation (${locale})`);
  }
  if (!(await page.locator('[data-new-order-continue]').evaluate((node) => node === document.activeElement))) {
    throw new Error(`Dirty order wizard did not move focus into discard confirmation (${locale})`);
  }
  await snap(page, `02_Web/B2C_Admin/23_order_wizard_dirty__desktop__${locale}.png`);

  await page.keyboard.press('Escape');
  if ((await discardPanel.getAttribute('data-visible')) !== '0' || (await modal.getAttribute('data-open')) !== '1') {
    throw new Error(`Escape did not safely return to the dirty order draft (${locale})`);
  }
  if ((await modal.locator('input[name="coupon_code"]').inputValue()) !== 'DRAFT-KEEP') {
    throw new Error(`Order wizard draft was lost after cancelling discard (${locale})`);
  }

  await page.keyboard.press('Escape');
  if ((await discardPanel.getAttribute('data-visible')) !== '1') {
    throw new Error(`Dirty order wizard Escape did not reopen discard confirmation (${locale})`);
  }
  await discardPanel.locator('[data-new-order-discard-confirm]').click();
  if ((await modal.getAttribute('data-open')) !== '0') {
    throw new Error(`Order wizard discard did not close the modal (${locale})`);
  }

  modal = await openWizard();
  if ((await modal.locator('input[name="coupon_code"]').inputValue()) !== '') {
    throw new Error(`Discarded order draft was restored instead of starting fresh (${locale})`);
  }
  await modal.locator('[data-new-order-cancel]').first().click();

  await page.setViewportSize({ width: 390, height: 844 });
  response = await page.goto(`${baseUrl}/admin/operations/orders`, { waitUntil: 'networkidle' });
  if (!response || !response.ok() || page.url().includes('/login')) {
    throw new Error(`Order wizard mobile evidence route failed (${locale})`);
  }
  modal = await openWizard();
  await setDraftCoupon(modal, 'MOBILE-DRAFT');
  await modal.locator('[data-new-order-cancel]').first().click();
  discardPanel = modal.locator('[data-new-order-discard]');
  await discardPanel.scrollIntoViewIfNeeded();

  const panelBox = await discardPanel.boundingBox();
  if (!panelBox || panelBox.x < -1 || panelBox.x + panelBox.width > 391) {
    throw new Error(`Order wizard discard confirmation overflows mobile viewport (${locale}): ${JSON.stringify(panelBox)}`);
  }
  for (const selector of ['[data-new-order-continue]', '[data-new-order-discard-confirm]']) {
    const box = await discardPanel.locator(selector).boundingBox();
    if (!box || box.height < 44 || box.x < -1 || box.x + box.width > 391) {
      throw new Error(`Order wizard discard action is not mobile-safe (${locale}/${selector}): ${JSON.stringify(box)}`);
    }
  }

  await page.keyboard.press('Tab');
  if (!(await discardPanel.locator('[data-new-order-discard-confirm]').evaluate((node) => node === document.activeElement))) {
    throw new Error(`Order wizard discard focus trap did not advance (${locale})`);
  }
  await page.keyboard.press('Tab');
  if (!(await discardPanel.locator('[data-new-order-continue]').evaluate((node) => node === document.activeElement))) {
    throw new Error(`Order wizard discard focus trap did not wrap (${locale})`);
  }

  await snap(page, `02_Web/B2C_Admin/23_order_wizard_dirty__mobile-390__${locale}.png`);

  await discardPanel.locator('[data-new-order-continue]').click();
  if ((await modal.locator('input[name="coupon_code"]').inputValue()) !== 'MOBILE-DRAFT') {
    throw new Error(`Mobile order draft was lost after Continue editing (${locale})`);
  }
  await page.keyboard.press('Escape');
  await discardPanel.locator('[data-new-order-discard-confirm]').click();

  await page.setViewportSize({ width: 1440, height: 1080 });
  console.log(`verified recoverable Order wizard draft behavior (${locale})`);
}

async function captureSecurityPermissionEvidence(page, locale) {
  await page.setViewportSize({ width: 1280, height: 900 });
  const response = await page.goto(`${baseUrl}/admin/security`, { waitUntil: 'networkidle' });
  if (!response || !response.ok() || page.url().includes('/login')) {
    throw new Error(`Security permission runtime route failed: HTTP ${response?.status() ?? 'no-response'} ${page.url()}`);
  }

  const createForm = page.locator('form[action$="/admin/security/roles"]').first();
  if (await createForm.count() !== 1) {
    throw new Error('Security create-role form was not rendered.');
  }

  await createForm.evaluate((form) => {
    const host = form.closest('.foodex-operational-dialog-host');
    const trigger = host?.querySelector('[data-foodex-dialog-open]');
    if (trigger instanceof HTMLElement) {
      trigger.click();
      return;
    }

    const details = form.closest('details');
    if (details instanceof HTMLDetailsElement) details.open = true;
  });

  const search = createForm.locator('[data-list-search]');
  await search.waitFor({ state: 'visible' });
  await search.fill('roles.manage');

  const visiblePermissions = createForm.locator('[data-search-item]:visible input[name="permission_ids[]"]');
  if (await visiblePermissions.count() < 1) {
    throw new Error('Security permission search did not expose the expected roles.manage result.');
  }

  const firstPermission = visiblePermissions.first();
  await firstPermission.check();

  const proof = await createForm.evaluate((form) => {
    const summary = form.querySelector('[data-impact-summary] span');
    const checked = [...form.querySelectorAll('input[name="permission_ids[]"]:checked')];
    const visible = [...form.querySelectorAll('[data-search-item]')].filter((item) => !item.hidden);
    return {
      checked: checked.length,
      visible: visible.length,
      impactText: summary?.textContent?.trim() ?? '',
      addedLabel: form.querySelector('[data-permission-picker]')?.dataset.impactAdded ?? '',
      hasTechnicalCode: visible.some((item) => item.querySelector('code')?.textContent?.includes('roles.manage')),
    };
  });

  if (proof.checked < 1 || proof.visible < 1 || !proof.hasTechnicalCode || !proof.impactText.includes(proof.addedLabel)) {
    throw new Error(`Security permission search/impact evidence failed: ${JSON.stringify(proof)}`);
  }

  await assertNoPageOverflow(page, `security-permissions/${locale}/desktop-1280`);
  await snap(page, `02_Web/B2C_Admin/21_security_permissions_review__desktop__${locale}.png`);

  await page.setViewportSize({ width: 390, height: 844 });
  await assertNoPageOverflow(page, `security-permissions/${locale}/mobile-390`);
  await snap(page, `02_Web/B2C_Admin/21_security_permissions_review__mobile-390__${locale}.png`);
}

async function captureEnterpriseSecurityEvidence(page, locale) {
  await page.setViewportSize({ width: 1280, height: 960 });
  const response = await page.goto(`${baseUrl}/admin/security/enterprise`, { waitUntil: 'networkidle' });
  if (!response || !response.ok() || page.url().includes('/login')) {
    throw new Error(`Enterprise Security Center runtime failed: HTTP ${response?.status() ?? 'no-response'} ${page.url()}`);
  }
  await assertSharedAdminRuntimeShell(page, `enterprise-security/${locale}/desktop`);
  if (await page.locator('[data-enterprise-security-center]').count() !== 1) {
    throw new Error('Enterprise Security Center marker is missing.');
  }
  for (const selector of [
    '[data-enterprise-security-scope-form]',
    '[data-enterprise-security-limit-form]',
    '[data-enterprise-security-delegation-form]',
    '[data-enterprise-security-change-queue]',
  ]) {
    if (await page.locator(selector).count() !== 1) {
      throw new Error(`Enterprise Security Center evidence missing ${selector}`);
    }
  }
  await assertNoPageOverflow(page, `enterprise-security/${locale}/desktop-1280`);
  await snap(page, `02_Web/B2C_Admin/22_enterprise_security__desktop__${locale}.png`);

  await page.setViewportSize({ width: 390, height: 844 });
  await assertNoPageOverflow(page, `enterprise-security/${locale}/mobile-390`);
  await snap(page, `02_Web/B2C_Admin/22_enterprise_security__mobile-390__${locale}.png`);
}

async function captureOwnedDashboardRuntimeEvidence(page, locale) {
  const shellRoutes = [
    ['administration', '/admin/administration'],
    ['enterprise-action-center', '/admin/action-center'],
    ['erp-center', '/admin/erp'],
    ['erp-wms', '/admin/erp/wms'],
    ['erp-procurement', '/admin/erp/procurement'],
    ['erp-finance', '/admin/erp/finance?store_code=FOODEX-DEMO-B2C'],
    ['erp-treasury', '/admin/erp/treasury'],
    ['erp-customer-service', '/admin/erp/customer-service'],
    ['erp-bi', '/admin/erp/bi'],
    ['enterprise-organizations', '/admin/administration/organizations'],
    ['enterprise-modules', '/admin/administration/modules'],
    ['security', '/admin/security'],
    ['enterprise-security', '/admin/security/enterprise'],
    ['live-tracking', '/admin/driver-live-tracking'],
    ['mobile-settings', '/admin/settings/mobile?app=van&environment=production'],
    ['notifications', '/admin/notifications'],
    ['notification-campaigns', '/admin/notification-campaigns'],
    ['order-operations', '/admin/operations/orders'],
    ['reports', '/admin/reports'],
    ['van-finance-support', '/admin/van-finance-support'],
    ['smart-ordering', '/admin/smart-ordering'],
    ['smart-ordering-analytics', '/admin/smart-ordering/analytics'],
    ['smart-ordering-settings', '/admin/smart-ordering/settings'],
    ['smart-ordering-menu', '/admin/smart-ordering/menu?support_access=1'],
    ['smart-ordering-appearance', '/admin/smart-ordering/appearance'],
    ['smart-ordering-qr', '/admin/smart-ordering/qr'],
    ['whatsapp-chatbot-control', '/admin/whatsapp/chatbot/control'],
    ['whatsapp-chatbot-preview', '/admin/whatsapp/chatbot/preview/home'],
  ];

  const smartOrderingSettings = await page.goto(`${baseUrl}/admin/smart-ordering/settings`, { waitUntil: 'networkidle' });
  if (smartOrderingSettings?.ok() && !page.url().includes('/login')) {
    const supplyHref = await page
      .locator('a[href*="/admin/smart-ordering/sites/"][href*="/supply"]')
      .first()
      .getAttribute('href')
      .catch(() => null);
    if (supplyHref) {
      const supplyUrl = new URL(supplyHref, baseUrl);
      shellRoutes.push(['smart-ordering-supply', `${supplyUrl.pathname}${supplyUrl.search}`]);
    }
    const integrationsHref = await page
      .locator('a[href*="/admin/smart-ordering/sites/"][href*="/integrations"]')
      .first()
      .getAttribute('href')
      .catch(() => null);
    if (integrationsHref) {
      const integrationsUrl = new URL(integrationsHref, baseUrl);
      shellRoutes.push(['restaurant-integrations', `${integrationsUrl.pathname}${integrationsUrl.search}`]);
    }
    const growthHref = await page
      .locator('a[href*="/admin/smart-ordering/sites/"][href*="/growth"]')
      .first()
      .getAttribute('href')
      .catch(() => null);
    if (growthHref) {
      const growthUrl = new URL(growthHref, baseUrl);
      shellRoutes.push(['restaurant-growth-scale', `${growthUrl.pathname}${growthUrl.search}`]);
    }
  }

  await page.setViewportSize({ width: 1440, height: 1080 });
  for (const [name, route] of shellRoutes) {
    const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
    if (!response || !response.ok() || page.url().includes('/login')) {
      const failureBody = (await page.locator('body').innerText().catch(() => ''))
        .replace(/\s+/g, ' ')
        .slice(0, 2400);
      throw new Error(
        `Owned Dashboard runtime route failed for ${name}: HTTP ${response?.status() ?? 'no-response'} ${page.url()}`
        + (failureBody ? ` · ${failureBody}` : ''),
      );
    }
    await assertSharedAdminRuntimeShell(page, `owned/${name}/${locale}`);
  }

  const moduleGridRoute = '/admin/administration/modules?state=NOT_INSTALLED&sort=name&direction=asc&columns%5B0%5D=state&columns%5B1%5D=capabilities&per_page=25';
  const moduleGridResponse = await page.goto(`${baseUrl}${moduleGridRoute}`, { waitUntil: 'networkidle' });
  if (!moduleGridResponse?.ok()
      || await page.locator('[data-enterprise-saved-views="enterprise.modules"]').count() !== 1
      || await page.locator('[data-enterprise-grid-filters]').count() !== 1) {
    const body = (await page.locator('body').innerText().catch(() => ''))
      .replace(/\s+/g, ' ')
      .slice(0, 1800);
    throw new Error(
      `Enterprise module saved-view/grid primitives were not rendered: HTTP ${moduleGridResponse?.status() ?? 'no-response'}`
      + (body ? ` · ${body}` : ''),
    );
  }
  await assertNoPageOverflow(page, `enterprise-modules/${locale}/desktop-1440`);
  await snap(page, `02_Web/B2C_Admin/22_enterprise_module_saved_views__desktop__${locale}.png`);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}${moduleGridRoute}`, { waitUntil: 'networkidle' });
  await assertNoPageOverflow(page, `enterprise-modules/${locale}/mobile-390`);
  await snap(page, `02_Web/B2C_Admin/22_enterprise_module_saved_views__mobile-390__${locale}.png`);
  await page.setViewportSize({ width: 1440, height: 1080 });

  await exerciseOrderWizardDraftSafety(page, locale);
  await captureSecurityPermissionEvidence(page, locale);
  await captureEnterpriseSecurityEvidence(page, locale);

  await page.goto(`${baseUrl}/admin/settings/mobile?app=van&environment=production`, { waitUntil: 'networkidle' });
  const mobileSettingsProof = await page.evaluate(() => {
    const has = (name) => Boolean(document.querySelector(`[name="${name}"]`));
    return {
      vanOption: Boolean(document.querySelector('select[name="app"] option[value="van"]')),
      deepLinkScheme: has('deep_link_scheme'),
      deepLinkHost: has('deep_link_host'),
      readinessAndroid: has('readiness_android'),
      readinessIos: has('readiness_ios'),
      readinessPrivacy: has('readiness_privacy'),
      reviewerChannel: has('reviewer_channel'),
      reviewerStore: has('reviewer_store_id'),
      assetIcon: has('asset_icon_master'),
      permissionList: has('permission_declarations_text'),
      privacyList: has('privacy_checklist_text'),
      manualGaps: has('manual_gaps_text'),
    };
  });
  if (Object.values(mobileSettingsProof).some((value) => value !== true)) {
    throw new Error(`Van Mobile Settings structured runtime evidence incomplete: ${JSON.stringify(mobileSettingsProof)}`);
  }
  await snap(page, `02_Web/B2C_Admin/19_mobile_settings_van_structured__desktop__${locale}.png`);

  await page.goto(`${baseUrl}/admin/van-finance-support`, { waitUntil: 'networkidle' });
  await assertSharedAdminRuntimeShell(page, `van-finance-support/wallets/${locale}`);
  if (await page.locator('[data-van-finance-support]').count() !== 1) {
    throw new Error('Van Finance Support runtime shell was not rendered.');
  }
  const financeWalletText = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  for (const required of ['VAN-EVID-01', 'FOODEX-801', 'FOODEX Wholesale Evidence Branch', '87.50', '25.00', '62.50']) {
    if (!financeWalletText.includes(required)) {
      throw new Error(`Van Finance Support wallet evidence missing ${required}: ${financeWalletText.slice(0, 1800)}`);
    }
  }
  if (financeWalletText.includes('van #')) {
    throw new Error('Van Finance Support exposed a raw Van internal ID.');
  }
  await snap(page, `02_Web/B2C_Admin/20_van_finance_support__desktop__${locale}.png`);

  await page.goto(`${baseUrl}/admin/van-finance-support?ops_tab=collections`, { waitUntil: 'networkidle' });
  await assertSharedAdminRuntimeShell(page, `van-finance-support/collections/${locale}`);
  const collectionText = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  if (!collectionText.includes('VAN-EVID-01') || collectionText.includes('EVIDENCE-VAN-COLLECTION-001')) {
    throw new Error(`Van collection/receipt support evidence failed: ${collectionText.slice(0, 1800)}`);
  }

  await page.goto(`${baseUrl}/admin/van-finance-support?ops_tab=remittances`, { waitUntil: 'networkidle' });
  await assertSharedAdminRuntimeShell(page, `van-finance-support/remittances/${locale}`);
  const remittanceText = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  if (!remittanceText.includes('VAN-REMIT-EVID-001') || remittanceText.includes('EVIDENCE-VAN-REMIT-001')) {
    throw new Error(`Van remittance support evidence failed: ${remittanceText.slice(0, 1800)}`);
  }

  await page.goto(`${baseUrl}/admin/notifications`, { waitUntil: 'networkidle' });
  await assertSharedAdminRuntimeShell(page, `notifications/actions/desktop/${locale}`);
  if (await page.locator('[data-notification-edit]').count() < 1) {
    throw new Error('Notification runtime evidence did not render an explicit Edit record affordance.');
  }
  await openFirstRecordActionMenu(page, '[data-notification-row-actions]', `notifications/${locale}`);
  await snap(page, `02_Web/B2C_Admin/17_notification_actions__desktop__${locale}.png`);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}/admin/notifications`, { waitUntil: 'networkidle' });
  await openFirstRecordActionMenu(page, '[data-notification-row-actions]', `notifications/mobile/${locale}`);
  await assertNoPageOverflow(page, `notifications/actions/mobile/${locale}`);
  await snap(page, `02_Web/B2C_Admin/17_notification_actions__mobile-390__${locale}.png`);

  await page.setViewportSize({ width: 1440, height: 1080 });
  await page.goto(`${baseUrl}/admin/notification-campaigns`, { waitUntil: 'networkidle' });
  await assertSharedAdminRuntimeShell(page, `campaigns/actions/desktop/${locale}`);
  const editCampaignLabel = locale === 'ar' ? 'تعديل الحملة' : 'Edit campaign';
  if (await page.getByRole('button', { name: editCampaignLabel, exact: true }).count() < 1) {
    throw new Error(`Campaign runtime evidence did not render explicit modal Edit button: ${editCampaignLabel}`);
  }
  await openFirstRecordActionMenu(page, '[data-notification-campaign-actions]', `campaigns/${locale}`);
  await snap(page, `02_Web/B2C_Admin/18_campaign_actions__desktop__${locale}.png`);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}/admin/notification-campaigns`, { waitUntil: 'networkidle' });
  await openFirstRecordActionMenu(page, '[data-notification-campaign-actions]', `campaigns/mobile/${locale}`);
  await assertNoPageOverflow(page, `campaigns/actions/mobile/${locale}`);
  await snap(page, `02_Web/B2C_Admin/18_campaign_actions__mobile-390__${locale}.png`);

  await page.setViewportSize({ width: 1440, height: 1080 });
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
    'erp',
    'stores',
    'catalog',
    'accounts',
    'operations',
    'field_operations',
    'marketing',
    'advertising',
    'finance',
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
      `/admin/settings/app-versions?app=${app.app}`,
      `/admin/settings#setting-mobile`,
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

  for (const [surface, route, requiredSelector] of [
    ['system-inspector', '/admin/inspector', '[data-system-runtime-health]'],
    ['system-update', '/admin/settings/system-update', '[data-system-backup-center]'],
  ]) {
    const runtimeResponse = await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' });
    if (!runtimeResponse || !runtimeResponse.ok() || page.url().includes('/login')) {
      const status = runtimeResponse?.status() ?? 'no-response';
      const body = (await page.locator('body').innerText().catch(() => ''))
        .replace(/\\s+/g, ' ')
        .slice(0, 600);
      throw new Error(
        `System administration evidence route failed: ${surface}/${locale}; HTTP ${status}; url=${page.url()}; body=${body}`,
      );
    }
    if (await page.locator(requiredSelector).count() !== 1) {
      throw new Error(`System administration runtime contract missing: ${surface}/${locale}`);
    }
    await assertNoPageOverflow(page, `${surface}/runtime/${locale}`);
    await assertLocaleDirection(page, locale, `${surface}/runtime/${locale}`);
    await snap(
      page,
      `02_Web/B2C_Admin/16_${surface}__populated__${locale}.png`,
    );
  }

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
  }, null, { timeout: 30000 });

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

  const entityLookup = page.locator('[data-live-lookup-search="entity"]');
  await entityLookup.focus();
  const entityResults = page.locator('[data-live-lookup-results="entity"]');
  await entityResults.waitFor({ state: 'visible', timeout: 10000 });
  const lookupProof = await page.evaluate(() => {
    const input = document.querySelector('[data-live-lookup-search="entity"]');
    const listbox = document.querySelector('[data-live-lookup-results="entity"]');
    const options = Array.from(listbox?.querySelectorAll('[role="option"]') ?? []);
    return {
      expanded: input?.getAttribute('aria-expanded'),
      optionCount: options.length,
      labels: options.map((option) => option.querySelector('strong')?.textContent?.trim() ?? ''),
      contexts: options.map((option) => option.querySelector('small')?.textContent?.trim() ?? ''),
    };
  });
  if (lookupProof.expanded !== 'true' || lookupProof.optionCount < 2) {
    throw new Error(`Live Tracking entity lookup evidence incomplete: ${JSON.stringify(lookupProof)}`);
  }
  if (lookupProof.labels.some((label) => label === '' || /^#?\d+$/.test(label))) {
    throw new Error(`Live Tracking entity lookup exposed raw identity labels: ${JSON.stringify(lookupProof)}`);
  }
  await assertNoPageOverflow(page, `live-tracking/entity-lookup/${locale}`);
  await snap(
    page,
    `02_Web/B2B_SuperAdmin/11_live_tracking_entity_lookup_open__${locale}.png`,
  );
}

async function captureMobileSettingsParityEvidence(page, locale) {
  const response = await page.goto(
    `${baseUrl}/admin/settings/mobile?app=van&environment=production`,
    { waitUntil: 'networkidle' },
  );
  if (!response || !response.ok()) {
    const failureBody = (await page.locator('body').innerText().catch(() => ''))
      .replace(/\s+/g, ' ')
      .slice(0, 2400);
    throw new Error(
      `Mobile Settings evidence page failed: HTTP ${response?.status() ?? 'no-response'}`
      + (failureBody ? ` · ${failureBody}` : ''),
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


async function exerciseCommercialRuntimeInteractions(page, locale, pageErrors = []) {
  await page.setViewportSize({ width: 1280, height: 900 });

  let response = await page.goto(`${baseUrl}/admin/b2b/commercial/sales-control`, {
    waitUntil: 'networkidle',
  });
  if (!response || !response.ok() || page.url().includes('/login')) {
    throw new Error(`Sales Control interaction evidence route failed (${locale})`);
  }

  const selector = page.locator('[data-sales-product-selector]');
  const searchInput = page.locator('[data-sales-product-search]');
  if (await selector.count() !== 1 || await searchInput.count() !== 1) {
    throw new Error(`Sales Control searchable product selector missing (${locale})`);
  }
  if (await page.locator('.commercial-policy-form').count() !== 0) {
    throw new Error(`Sales Control rendered a product editor before selection (${locale})`);
  }

  await searchInput.focus();
  const firstProduct = page.locator('[data-sales-product-results] .sales-product-option').first();
  await firstProduct.waitFor({ state: 'visible' });
  await Promise.all([
    page.waitForURL((url) => url.searchParams.has('product'), { waitUntil: 'networkidle' }),
    firstProduct.click(),
  ]);

  const policyForm = page.locator('.commercial-policy-form').first();
  if (await policyForm.count() !== 1) {
    throw new Error(`Sales Control did not render exactly one selected product policy form (${locale})`);
  }

  const unitRows = policyForm.locator('[data-selling-unit-row]');
  const availabilityRows = policyForm.locator('[data-availability-row]');
  const ruleRows = policyForm.locator('[data-rule-row]');
  const unitBefore = await unitRows.count();
  const availabilityBefore = await availabilityRows.count();
  const ruleBefore = await ruleRows.count();

  await policyForm.locator('[data-selling-unit-add]').click();
  await policyForm.locator('[data-availability-add]').click();
  await policyForm.locator('[data-rule-add]').click();

  if (await unitRows.count() !== unitBefore + 1
      || await availabilityRows.count() !== availabilityBefore + 1
      || await ruleRows.count() !== ruleBefore + 1) {
    throw new Error(`Structured Sales Control builders did not add rows (${locale})`);
  }

  const addedUnit = unitRows.last();
  const runtimeCode = `RUNTIME-${locale.toUpperCase()}`;
  await addedUnit.locator('[data-unit-field="code"]').fill(runtimeCode);
  await addedUnit.locator('[data-unit-field="name"]').fill(
    locale === 'ar' ? 'وحدة تحقق وقت التشغيل' : 'Runtime Evidence Unit',
  );
  await addedUnit.locator('[data-unit-field="conversion_factor"]').fill('2');

  const unitPayload = JSON.parse(
    await policyForm.locator('[data-selling-units-json]').inputValue(),
  );
  if (!unitPayload.some((row) => row.code === runtimeCode && Number(row.conversion_factor) === 2)) {
    throw new Error(`Selling Unit builder did not serialize business input (${locale})`);
  }

  const breakPack = policyForm.locator('[data-break-pack-selling-unit]');
  if (await breakPack.locator(`option[value="${runtimeCode}"]`).count() !== 1) {
    throw new Error(`Break-pack Selling Unit lookup did not synchronize (${locale})`);
  }

  const addedWindow = availabilityRows.last();
  await addedWindow.locator('[data-window-field="start_month"]').fill('1');
  await addedWindow.locator('[data-window-field="start_day"]').fill('5');
  await addedWindow.locator('[data-window-field="end_month"]').fill('1');
  await addedWindow.locator('[data-window-field="end_day"]').fill('10');
  const availabilityPayload = JSON.parse(
    await policyForm.locator('[data-availability-json]').inputValue(),
  );
  if (!availabilityPayload.some((row) => Number(row.start_day) === 5 && Number(row.end_day) === 10)) {
    throw new Error(`Availability builder did not serialize structured input (${locale})`);
  }

  const addedRule = ruleRows.last();
  await addedRule.locator('[data-rule-field="channel"]').selectOption('van');
  await addedRule.locator('[data-rule-field="is_allowed"]').selectOption('1');
  const rulePayload = JSON.parse(await policyForm.locator('[data-rules-json]').inputValue());
  if (!rulePayload.some((row) => row.channel === 'van' && row.is_allowed === true)) {
    throw new Error(`Targeting rule builder did not serialize Van access rule (${locale})`);
  }

  await addedUnit.locator('[data-selling-unit-remove]').click();
  await addedWindow.locator('[data-availability-remove]').click();
  await addedRule.locator('[data-rule-remove]').click();

  response = await page.goto(`${baseUrl}/admin/b2c/commercial/flash-offers`, {
    waitUntil: 'networkidle',
  });
  if (!response || !response.ok() || page.url().includes('/login')) {
    throw new Error(`Flash Offers interaction evidence route failed (${locale})`);
  }

  const flashForm = page.locator('[data-flash-offer-form]');
  if (await flashForm.count() !== 1) {
    throw new Error(`Flash Offers structured form is missing (${locale})`);
  }

  const audienceLookups = ['customers', 'customer-groups', 'regions', 'routes'];
  for (const kind of audienceLookups) {
    const lookup = flashForm.locator(`[data-flash-lookup-kind="${kind}"]`);
    if (await lookup.count() !== 1
        || await lookup.locator('[data-flash-lookup-toggle]').count() !== 1
        || await lookup.locator('[data-flash-lookup-search]').count() !== 1) {
      throw new Error(`Flash audience searchable dropdown missing: ${kind} (${locale})`);
    }
  }
  if (await flashForm.locator('input[name="channels[]"][value="van"]').count() !== 1) {
    throw new Error(`Structured Van channel control missing from Flash Offers (${locale})`);
  }

  const productsWizardButton = flashForm.locator('[data-flash-wizard-go="3"]');
  if (await productsWizardButton.count() !== 1) {
    throw new Error(`Flash Offers Products wizard step is missing (${locale})`);
  }
  await productsWizardButton.click();
  const productsWizardStep = flashForm.locator('[data-flash-wizard-step="3"]');
  if (await productsWizardStep.count() !== 1 || await productsWizardStep.getAttribute('hidden') !== null) {
    throw new Error(`Flash Offers Products wizard step did not become active (${locale})`);
  }

  const productRows = flashForm.locator('[data-flash-product-row]');
  const productBefore = await productRows.count();
  await flashForm.locator('[data-flash-product-add]').click();
  if (await productRows.count() !== productBefore + 1) {
    // Chromium can occasionally complete the actionability click without delivering
    // the DOM click after the page's shared capture-phase UI hooks settle. Dispatch
    // the same browser click event directly and still require the real button wiring.
    await flashForm.locator('[data-flash-product-add]').dispatchEvent('click');
  }
  if (await productRows.count() !== productBefore + 1) {
    const initState = await page.evaluate(() => {
      const template = document.querySelector('[data-flash-product-template]');
      const builder = document.querySelector('[data-flash-product-builder]');
      const before = builder?.querySelectorAll('[data-flash-product-row]').length ?? -1;
      const directResult = typeof window.foodexFlashAddProductRow === 'function'
        ? window.foodexFlashAddProductRow()
        : 'missing';
      const after = builder?.querySelectorAll('[data-flash-product-row]').length ?? -1;
      return {
        script: document.documentElement.dataset.foodexCommercialScriptInit || '0',
        builder: builder?.dataset.foodexProductBuilderInit || '0',
        addFn: typeof window.foodexFlashAddProductRow,
        buttonCount: document.querySelectorAll('[data-flash-product-add]').length,
        templateCount: document.querySelectorAll('[data-flash-product-template]').length,
        templateHtmlLength: template?.innerHTML?.length ?? -1,
        templateContentChildren: template instanceof HTMLTemplateElement ? template.content.children.length : -1,
        directResult,
        beforeDirect: before,
        afterDirect: after,
      };
    });
    throw new Error(`Flash Product Builder did not add a product row (${locale}); init=${JSON.stringify(initState)}; page errors: ${pageErrors.join(' | ') || 'none'}`);
  }

  const addedProduct = productRows.last();
  const productSelect = addedProduct.locator('[data-flash-product]');
  const productValues = await productSelect.locator('option').evaluateAll((options) =>
    options.map((option) => option.value).filter((value) => value !== ''),
  );
  if (productValues.length < 1) {
    throw new Error(`Flash Product Builder has no authoritative Product options (${locale})`);
  }
  await productSelect.selectOption(productValues[0]);

  const unitSelect = addedProduct.locator('[data-flash-unit]');
  if (await unitSelect.count() !== 1 || await unitSelect.locator('option').count() < 1) {
    throw new Error(`Flash Product Builder did not render the structured Selling Unit control (${locale})`);
  }
  await addedProduct.locator('[data-flash-product-remove]').click();

  console.log(`verified Commercial structured builders/lookups interaction contract (${locale})`);
}

async function captureLocale(browser, locale) {
  const context = await browser.newContext({
    locale: locale === 'ar' ? 'ar-KW' : 'en-US',
    viewport: { width: 1440, height: 1080 },
    deviceScaleFactor: 1,
  });
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  const email = locale === 'ar' ? 'screenshots@foodex.test' : 'screenshots.en@foodex.test';

  await page.goto(`${baseUrl}/admin/b2b/login?locale=${locale}`, { waitUntil: 'networkidle' });
  await snap(page, `02_Web/B2B_SuperAdmin/01_تسجيل_الدخول_B2B__default__${locale}.png`);

  await page.goto(`${baseUrl}/admin/b2c/login?locale=${locale}`, { waitUntil: 'networkidle' });
  await snap(page, `02_Web/B2C_Admin/01_تسجيل_الدخول_B2C__default__${locale}.png`);

  await login(page, 'b2b', locale, email);
  await captureResponsiveRoute(
    page,
    locale,
    'B2B_SuperAdmin',
    'executive-command-center',
    '/admin',
    { fullMatrix: true },
  );
  if (await page.locator('[data-foodex-executive-overview]').count() !== 1) {
    throw new Error(`Executive Command Center marker missing after /admin runtime evidence (${locale})`);
  }
  await page.setViewportSize({ width: 1440, height: 1080 });
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
  await captureOwnedDashboardRuntimeEvidence(page, locale);

  // Long-running evidence navigation can rotate or invalidate the admin session.
  // Re-establish the B2C actor before exercising mutation-adjacent commercial UI.
  await context.clearCookies();
  await login(page, 'b2c', locale, email);
  const b2cContext = await page.goto(`${baseUrl}/admin/b2c/dashboard`, { waitUntil: 'networkidle' });
  if (!b2cContext || !b2cContext.ok() || page.url().includes('/login')) {
    throw new Error(`Unable to restore B2C runtime context before commercial evidence (${locale})`);
  }
  await exerciseCommercialRuntimeInteractions(page, locale, pageErrors);

  await captureResponsiveRoute(page, locale, 'B2C_Admin', 'dashboard', '/admin/b2c/dashboard');
  await captureResponsiveRoute(page, locale, 'B2C_Admin', 'products', '/admin/b2c/products');
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'sales-control',
    '/admin/b2b/commercial/sales-control',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'flash-offers',
    '/admin/b2c/commercial/flash-offers',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'flash-offers',
    '/admin/b2c/commercial/flash-offers',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'administration-hub',
    '/admin/administration',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'system-inspector',
    '/admin/inspector',
    { fullMatrix: true },
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'system-update',
    '/admin/settings/system-update',
    { fullMatrix: true },
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'erp-center',
    '/admin/erp',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'erp-wms-responsive',
    '/admin/erp/wms',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'erp-procurement-responsive',
    '/admin/erp/procurement',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'erp-finance-responsive',
    '/admin/erp/finance?store_code=FOODEX-DEMO-B2C',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'erp-treasury-responsive',
    '/admin/erp/treasury',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'erp-customer-service-responsive',
    '/admin/erp/customer-service',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'erp-bi-responsive',
    '/admin/erp/bi',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'erp-my-work-responsive',
    '/admin/action-center',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'treasury-master',
    '/admin/treasury/master-data',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'settings-center',
    '/admin/settings',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering',
    '/admin/smart-ordering',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-payments',
    '/admin/smart-ordering?tab=payments',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-tables',
    '/admin/smart-ordering?tab=tables',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-workforce',
    '/admin/smart-ordering?tab=workforce',
    { fullMatrix: true },
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-reservations',
    '/admin/smart-ordering?tab=reservations',
    { fullMatrix: true },
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-waitlist',
    '/admin/smart-ordering?tab=waitlist',
    { fullMatrix: true },
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-guest-book',
    '/admin/smart-ordering?tab=guest_book',
    { fullMatrix: true },
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-analytics',
    '/admin/smart-ordering/analytics',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-menu',
    '/admin/smart-ordering/menu?support_access=1',
    { fullMatrix: true },
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-appearance',
    '/admin/smart-ordering/appearance',
    { fullMatrix: true },
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-supply',
    '/admin/smart-ordering/supply?support_access=1',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'smart-ordering-qr',
    '/admin/smart-ordering/qr',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'whatsapp-chatbot-control',
    '/admin/whatsapp/chatbot/control',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'whatsapp-chatbot-preview',
    '/admin/whatsapp/chatbot/preview/home',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'foodex-reset',
    '/admin/settings/foodex-reset',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'production-launch',
    '/admin/settings/production-launch',
  );
  await captureResponsiveRoute(
    page,
    locale,
    'B2C_Admin',
    'production-dataset',
    '/admin/settings/production-dataset',
  );

  const customer360Response = await page.goto(
    `${baseUrl}/admin/customer-360?q=evidence.address%40foodex.test`,
    { waitUntil: 'networkidle' },
  );
  if (!customer360Response || !customer360Response.ok()) {
    const failureBody = (await page.locator('body').innerText().catch(() => ''))
      .replace(/\s+/g, ' ')
      .slice(0, 2400);
    throw new Error(
      `Customer 360 evidence page failed: HTTP ${customer360Response?.status() ?? 'no-response'}`
      + (failureBody ? ` · ${failureBody}` : ''),
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
    const failureBody = (await page.locator('body').innerText().catch(() => ''))
      .replace(/\s+/g, ' ')
      .slice(0, 2400);
    throw new Error(
      `Customer 360 detail evidence page failed: HTTP ${customer360DetailResponse?.status() ?? 'no-response'}`
      + (failureBody ? ` · ${failureBody}` : ''),
    );
  }
  await assertSharedAdminRuntimeShell(page, `customer-360/detail/${locale}`);
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

  const customerReportHref = customer360Href.replace(/\/$/, '')
    + '/reports/account-statement?from=2026-10-01&to=2026-10-11';
  const customerReportResponse = await page.goto(
    new URL(customerReportHref, baseUrl).toString(),
    { waitUntil: 'networkidle' },
  );
  if (!customerReportResponse || !customerReportResponse.ok()) {
    throw new Error(
      `Customer report replica evidence page failed: HTTP ${customerReportResponse?.status() ?? 'no-response'}`,
    );
  }
  await assertSharedAdminRuntimeShell(page, `customer-360/report-replica/${locale}`);
  if (await page.locator('[data-report-frame]').count() !== 1) {
    throw new Error(`Customer report replica PDF frame was not rendered (${locale}).`);
  }
  await snap(
    page,
    `02_Web/B2C_Admin/12_customer_report_replica__populated__${locale}.png`,
  );

  await page.goto(
    `${baseUrl}/admin/operations/orders?channel=b2c&order_number=FOODEX-EVID-LOC-1`,
    { waitUntil: 'networkidle' },
  );
  // The filter uses the immutable internal reference, while the UI intentionally
  // renders the short customer-facing display number (#ID).
  const orderLocationLink = page.locator(
    'td a[href*="order="]',
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
  refreshTimeSensitiveEvidence();
  await captureLocale(browser, 'ar');
  refreshTimeSensitiveEvidence();
  await captureLocale(browser, 'en');
} finally {
  await browser.close();
}
