enum CustomerChannel { b2c, b2b }

class CustomerSession {
  const CustomerSession.guest()
      : isAuthenticated = false,
        channel = null,
        accessToken = null,
        b2bRetailStoreId = null,
        platformWide = false;

  const CustomerSession.authenticated(
    CustomerChannel authenticatedChannel, {
    this.accessToken,
    this.b2bRetailStoreId,
    this.platformWide = false,
  })  : isAuthenticated = true,
        channel =
            platformWide ? CustomerChannel.b2c : authenticatedChannel;

  const CustomerSession.platformCustomer({
    this.accessToken,
    this.b2bRetailStoreId,
  })  : isAuthenticated = true,
        channel = CustomerChannel.b2c,
        platformWide = true;

  final bool isAuthenticated;

  /// Authentication identity compatibility channel.
  ///
  /// Platform Customers are normalized to B2C identity even when restored
  /// from older payloads that used B2B as a platform-wide workaround.
  /// Active Retail/Wholesale commerce scope is modeled separately.
  final CustomerChannel? channel;

  final String? accessToken;

  /// Legacy wholesale retail-receiver authorization context.
  ///
  /// New Customer Journey code should use the explicit commerce-context
  /// contract in core/routing rather than treating this as app navigation
  /// state. It remains here for backward-compatible backend authorization.
  final int? b2bRetailStoreId;

  /// Platform-wide customer capability independent from active commerce
  /// channel/context.
  final bool platformWide;

  CustomerSession asB2bRetailContext(int? retailStoreId) =>
      CustomerSession.authenticated(
        channel ?? CustomerChannel.b2c,
        accessToken: accessToken,
        b2bRetailStoreId: retailStoreId,
        platformWide: platformWide,
      );
}
