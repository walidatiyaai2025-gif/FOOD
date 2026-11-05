<script>
(() => {
    if (typeof window.FoodexOrderPopupPrint === 'function') return;

    // Print only the authorized order fragment in its own document, never the dashboard.
    // Printing a hidden dashboard with visibility:hidden leaves its layout occupying pages.
    const printStyles = "\n@page { size: A4; margin: 13mm 14mm; }\n*, *::before, *::after { box-sizing: border-box; }\nhtml,body { width:100%; margin:0; padding:0; background:var(--foodex-surface, white); color:var(--foodex-ink, black); }\nbody { font-family: var(--foodex-font-family, \"Tajawal\", sans-serif); font-size:10pt; line-height:1.5; -webkit-print-color-adjust:exact; print-color-adjust:exact; }\n.order-popup-content { display:block; width:100%; max-width:100%; }\n.order-popup-toolbar,[data-no-print] { display:none!important; }\n.order-print-header { display:flex; direction:ltr; justify-content:space-between; align-items:center; gap:12px; padding:0 0 8px; border-bottom:2px solid var(--foodex-green-dark, darkgreen); margin-bottom:10px; }\n.order-print-type { display:grid; gap:2px; }\n.order-print-brand { display:flex; align-items:center; gap:6px; }\n.order-print-brand img { max-width:140px; max-height:60px; object-fit:contain; }\n.order-print-brand strong { font-size:13pt; }\n.foodex-invoice-company { display:flex; flex-direction:column; align-items:center; direction:rtl; text-align:center; width:100%; border-bottom:2px solid var(--foodex-green-dark, darkgreen); margin:0 0 12px; padding:0 0 10px; page-break-inside:avoid; break-inside:avoid; }\n.foodex-invoice-company-logo { display:block; width:150px; max-width:100%; max-height:65px; object-fit:contain; object-position:center center; margin:0 auto 4px; }\n.foodex-invoice-company-name { display:block; max-width:100%; text-align:center; font-size:12pt; font-weight:700; }\n.foodex-invoice-company-address { display:block; max-width:100%; text-align:center; margin-top:2px; font-size:9pt; overflow-wrap:anywhere; }\n.foodex-invoice-company-contacts { display:flex; flex-direction:column; align-items:center; text-align:center; width:100%; gap:2px; margin-top:2px; font-size:9pt; }\n.foodex-invoice-company-contacts span { text-align:center; max-width:100%; white-space:nowrap; unicode-bidi:isolate; }\n.order-popup-summary { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:6px; margin:0 0 10px; }\n.order-popup-summary>div,.order-popup-facts>div { display:grid; align-content:start; gap:3px; min-width:0; padding:7px 9px; border:1px solid var(--foodex-border, lightgray); border-radius:5px; break-inside:avoid; }\n.order-popup-summary small,.order-popup-facts span { color:var(--foodex-muted, gray); font-size:8pt; }\n.order-popup-summary strong,.order-popup-facts strong { display:block; font-size:9pt; font-weight:700; overflow-wrap:anywhere; }\n.is-paid { color:var(--foodex-green-dark, darkgreen); }\n.is-unpaid { color:var(--foodex-danger-text, firebrick); }\n.order-popup-section { margin:8px 0 0; padding:10px; border:1px solid var(--foodex-border, lightgray); border-radius:5px; break-inside:avoid; }\n.order-popup-section h3 { font-size:10pt; margin:0 0 7px; }\n.order-popup-section p { margin:0 0 5px; overflow-wrap:anywhere; }\n.order-popup-section small { color:var(--foodex-muted, gray); }\n.order-popup-facts { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:6px; }\n.order-popup-table-wrap { width:100%; overflow:visible!important; }\n.order-popup-table { width:100%; border-collapse:collapse; font-size:9pt; }\n.order-popup-table thead { display:table-header-group; }\n.order-popup-table th,.order-popup-table td { border-bottom:1px solid var(--foodex-border, lightgray); padding:6px 8px; text-align:start; overflow-wrap:anywhere; }\n.order-popup-table th { font-weight:700; background:var(--foodex-surface-soft, whitesmoke); }\n.order-popup-table tr { break-inside:avoid; }\n.order-popup-timeline { display:block; }\n.order-popup-timeline-item { display:block; padding:7px 0; border-bottom:1px solid var(--foodex-border, lightgray); break-inside:avoid; }\n.order-popup-timeline-item footer { display:flex; gap:10px; font-size:8pt; }\n.order-popup-timeline-dot { display:none; }\n.order-print-footer { display:flex; justify-content:flex-end; align-items:center; gap:7px; margin:12px 0 0; border-top:1px solid var(--foodex-border, lightgray); padding:6px 0 0; font-size:8pt; }\n";
    let activeFrame = null;

    window.FoodexOrderPopupPrint = (source) => {
        if (!(source instanceof HTMLElement) || activeFrame) return false;
        const fragment = source.matches('[data-order-popup-content]')
            ? source
            : source.querySelector('[data-order-popup-content]');
        if (!(fragment instanceof HTMLElement)) return false;

        const copy = fragment.cloneNode(true);
        copy.querySelectorAll('style, script, [data-no-print], .order-popup-toolbar').forEach((el) => el.remove());
        // Both order details and invoice views have full company branding beneath the logo.
        if (copy.querySelector('[data-invoice-company-identity]')) {
            copy.querySelector('.order-print-header')?.remove();
        }
        const locale = document.documentElement.lang || 'ar';
        const printedAt = new Intl.DateTimeFormat(locale, {
            dateStyle: 'full',
            timeStyle: 'medium',
            timeZone: 'Asia/Kuwait',
        }).format(new Date());
        copy.querySelectorAll('[data-order-print-time]').forEach((node) => {
            node.textContent = printedAt;
        });

        const frame = document.createElement('iframe');
        frame.setAttribute('title', 'FOODEX order print');
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('data-foodex-isolated-print-frame', '');
        frame.style.cssText = 'position:fixed;left:-12000px;top:0;width:820px;height:1160px;border:0;opacity:0;pointer-events:none;';
        document.body.appendChild(frame);
        activeFrame = frame;

        let cleanupTimer = null;
        const cleanup = () => {
            if (cleanupTimer !== null) clearTimeout(cleanupTimer);
            if (activeFrame === frame) activeFrame = null;
            frame.remove();
        };

        const printDocument = frame.contentDocument;
        const printWindow = frame.contentWindow;
        if (!printDocument || !printWindow) {
            cleanup();
            return false;
        }
        printDocument.open();
        printDocument.write('<!doctype html><html><head><meta charset="utf-8"><title>FOODEX</title></head><body></body></html>');
        printDocument.close();
        printDocument.documentElement.lang = locale;
        printDocument.documentElement.dir = document.documentElement.dir || 'rtl';
        const base = printDocument.createElement('base');
        base.href = document.baseURI;
        printDocument.head.appendChild(base);
        // Print frames own a document, so load the canonical font there too.
        const font = printDocument.createElement('link');
        font.rel = 'stylesheet';
        font.href = 'https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap';
        printDocument.head.appendChild(font);
        const style = printDocument.createElement('style');
        style.textContent = printStyles;
        // Print is isolated: inherit the authenticated dashboard's semantic theme tokens.
        const rootStyle = getComputedStyle(document.documentElement);
        const bodyStyle = getComputedStyle(document.body);
        for (const token of [
            '--foodex-surface', '--foodex-ink', '--foodex-green-dark',
            '--foodex-border', '--foodex-muted', '--foodex-danger-text',
            '--foodex-surface-soft',
        ]) {
            const inheritedValue = bodyStyle.getPropertyValue(token).trim()
                || rootStyle.getPropertyValue(token).trim();
            if (inheritedValue) printDocument.documentElement.style.setProperty(token, inheritedValue);
        }
        printDocument.head.appendChild(style);
        const page = printDocument.createElement('main');
        page.setAttribute('data-foodex-isolated-print-page', '');
        page.appendChild(copy);
        printDocument.body.appendChild(page);

        // Keep the iframe until printing ends, but clean it up if afterprint is unavailable.
        printWindow.addEventListener('afterprint', () => setTimeout(cleanup, 300), {once: true});
        cleanupTimer = setTimeout(cleanup, 120000);
        const images = Array.from(printDocument.images);
        Promise.all(images.map((img) => new Promise((resolve) => {
            if (img.complete) return resolve();
            const finish = () => resolve();
            img.addEventListener('load', finish, {once: true});
            img.addEventListener('error', finish, {once: true});
            setTimeout(finish, 2500);
        }))).then(async () => {
            if (!frame.isConnected) return;
            try {
                if (printDocument.fonts?.ready) await printDocument.fonts.ready;
                printWindow.focus();
                printWindow.print();
            } catch (_) {
                cleanup();
            }
        }).catch(cleanup);
        return true;
    };
})();
</script>