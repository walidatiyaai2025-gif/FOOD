/// Public, non-secret FOODEX runtime configuration.
///
/// Production builds target the first FOODEX installation by default. CI/dev
/// may override this with `--dart-define=FOODEX_API_BASE_URL=https://example.test`.
abstract final class FoodexEnvironment {
  static const apiBaseUrl = String.fromEnvironment(
    'FOODEX_API_BASE_URL',
    defaultValue: 'https://foodex.50sols.com',
  );

  static const pushEnvironment = String.fromEnvironment(
    'FOODEX_PUSH_ENVIRONMENT',
    defaultValue: 'production',
  );
}
