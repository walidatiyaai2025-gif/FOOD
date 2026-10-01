<script>
// Run before the external scripts so a missing or stalled renderer cannot leave
// either dashboard surface claiming to load indefinitely.
(() => {
    const reportMissingRuntime = () => {
        document.querySelectorAll('[data-driver-live-map]').forEach(root => {
            if (root.dataset.driverLiveMapReady === '1' || root.dataset.liveMapError) return;
            root.dataset.liveMapError = 'map-assets';
            const message = root.dataset.assetsFailed;
            ['state', 'error-message', 'list'].forEach(role => {
                const node = root.querySelector('[data-live-map="' + role + '"]');
                if (node) node.textContent = message;
            });
            const error = root.querySelector('[data-live-map="error"]');
            if (error) error.hidden = false;
        });
    };
    window.setTimeout(reportMissingRuntime, 15000);
    window.addEventListener('load', reportMissingRuntime, {once:true});
})();
</script>
<script src="{{ asset('assets/leaflet/1.9.4/leaflet.js') }}"></script>
<script src="{{ asset('assets/admin/driver-live-map.js') }}"></script>
