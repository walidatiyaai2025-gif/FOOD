/// Repository-owned Customer UI V3 asset contract.
///
/// Product and store imagery is deliberately excluded: those URLs remain
/// backend-authoritative and must never be copied into the application bundle.
abstract final class CustomerAssets {
  static const splash = 'assets/branding/splash_full.png';
  static const brandLockup =
      'assets/branding/foodex-economical-group.webp';
  static const storeSelectorRoot = 'assets/store_selector/';

  /// Machine-readable inventory for V3-owned visual assets.
  static const manifest = 'assets/customer_ui_v3/asset_contract.json';

  /// Reserved namespaces for repository-owned artwork added by later lanes.
  static const categoryRoot = 'assets/customer_ui_v3/categories/';
  static const heroRoot = 'assets/customer_ui_v3/heroes/';
  static const stateRoot = 'assets/customer_ui_v3/states/';
  static const fallbackRoot = 'assets/customer_ui_v3/fallbacks/';
  static const avatarRoot = 'assets/customer_ui_v3/avatars/';

  static const productImagesAreBackendDriven = true;
  static const storeImagesAreBackendDriven = true;
  static const proprietaryReferenceAssetsAllowed = false;
}
