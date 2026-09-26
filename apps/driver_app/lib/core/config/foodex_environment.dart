/// Public, non-secret FOODEX runtime configuration.
///
/// Production builds target the first FOODEX installation by default. CI/dev
/// may override this with --dart-define=FOODEX_API_BASE_URL=<url>.
abstract final class FoodexEnvironment {
  static const apiBaseUrl = String.fromEnvironment(
    'FOODEX_API_BASE_URL',
    defaultValue: 'https://foodex.50sols.com',
  );
}
