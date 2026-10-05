/// Public, non-secret runtime configuration for the standalone Van App.
abstract final class FoodexEnvironment {
  static const apiBaseUrl = String.fromEnvironment(
    'FOODEX_API_BASE_URL',
    defaultValue: 'https://foodex.50sols.com',
  );

  static const previewContractVersion = String.fromEnvironment(
    'FOODEX_PREVIEW_CONTRACT_VERSION',
    defaultValue: 'shared-flutter-v1',
  );
}
