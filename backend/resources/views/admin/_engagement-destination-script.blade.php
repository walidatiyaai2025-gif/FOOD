<script>
(() => {
    const destinations = @json($engagementDestinations);
    const previewLabel = @json(__('engagement_destinations.preview'));
    const noActionPreview = @json(__('engagement_destinations.none_preview'));
    const storeSuffix = @json(__('engagement_destinations.store_suffix'));

    document.querySelectorAll('.js-engagement-destination-builder').forEach((builder) => {
        const form = builder.closest('form');
        const select = builder.querySelector('.js-engagement-destination');
        const preview = builder.querySelector('.js-engagement-destination-preview');
        if (!form || !select || !preview) return;

        let preferred = select.dataset.currentDestination || 'none';

        const valueOf = (name, fallback) =>
            form.querySelector(`select[name="${name}"], input[name="${name}"]`)?.value || fallback;

        const sync = () => {
            const app = valueOf('app', 'customer');
            const channel = valueOf('target_channel', valueOf('channel', 'all'));
            const store = form.querySelector('select[name="store_id"]');
            const storeId = store?.value || '';
            const available = destinations.filter((destination) =>
                destination.apps.includes(app) &&
                destination.channels.includes(channel) &&
                (!destination.requires_store || storeId !== '')
            );

            select.replaceChildren();
            available.forEach((destination) => {
                const option = document.createElement('option');
                option.value = destination.code;
                option.textContent = destination.label;
                select.append(option);
            });

            const matching = available.some((destination) => destination.code === preferred);
            select.value = matching ? preferred : 'none';
            if (!matching) preferred = select.value || 'none';

            const selected = destinations.find((destination) => destination.code === select.value);
            if (!selected || selected.code === 'none') {
                preview.textContent = `${previewLabel}: ${noActionPreview}`;
                return;
            }

            let text = selected.label;
            if (selected.requires_store && store?.selectedOptions?.[0]) {
                text += ` · ${storeSuffix.replace(':store', store.selectedOptions[0].textContent.trim())}`;
            }
            preview.textContent = `${previewLabel}: ${text}`;
        };

        select.addEventListener('change', () => {
            preferred = select.value;
            sync();
        });

        ['app', 'target_channel', 'channel', 'store_id'].forEach((name) => {
            form.querySelectorAll(`select[name="${name}"], input[name="${name}"]`).forEach((control) => {
                control.addEventListener('change', sync);
            });
        });

        sync();
    });
})();
</script>
