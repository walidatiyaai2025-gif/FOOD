import importlib.util
import tempfile
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SCRIPT_PATH = REPO_ROOT / 'scripts' / 'configure-mobile-native.py'

spec = importlib.util.spec_from_file_location('foodex_mobile_native', SCRIPT_PATH)
mobile_native = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(mobile_native)


class MobileLauncherBrandingTest(unittest.TestCase):
    def test_launcher_sources_are_complete_distinct_1024_webp(self) -> None:
        decoded = {}
        with tempfile.TemporaryDirectory() as temp_dir:
            app_dir = Path(temp_dir)
            for app_name in ('customer', 'driver', 'van'):
                source = mobile_native._decode_chunked_launcher(app_name, app_dir)
                payload = source.read_bytes()
                self.assertEqual(
                    mobile_native._webp_dimensions(payload),
                    (1024, 1024),
                    app_name,
                )
                decoded[app_name] = payload

        self.assertEqual(len({payload for payload in decoded.values()}), 3)

    def test_brand_selection_preserves_existing_splash_contract(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            app_dir = Path(temp_dir)

            mobile_native._select_brand_assets('customer', app_dir)
            self.assertEqual(mobile_native.SPLASH_IMAGE.name, 'customer_splash.png')
            self.assertEqual(
                mobile_native.ANDROID_SPLASH_ICON.parts[-4:],
                ('customer_app', 'assets', 'branding', 'foodex-economical-group.webp'),
            )

            mobile_native._select_brand_assets('driver', app_dir)
            self.assertEqual(mobile_native.SPLASH_IMAGE.name, 'splash_master.png')
            self.assertEqual(
                mobile_native.ANDROID_SPLASH_ICON.parts[-4:],
                ('driver_app', 'assets', 'branding', 'foodex-economical-group.webp'),
            )

            mobile_native._select_brand_assets('van', app_dir)
            self.assertEqual(mobile_native.SPLASH_IMAGE.name, 'splash_master.png')
            self.assertEqual(
                mobile_native.ANDROID_SPLASH_ICON.parts[-4:],
                ('customer_app', 'assets', 'branding', 'foodex-economical-group.webp'),
            )


if __name__ == '__main__':
    unittest.main()
