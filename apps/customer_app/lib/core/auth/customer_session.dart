enum CustomerChannel { b2c, b2b }

class CustomerSession {
  const CustomerSession.guest()
      : isAuthenticated = false,
        channel = null,
        accessToken = null,
        b2bRetailStoreId = null,
        platformWide = false;

  const CustomerSession.authenticated(
    this.channel, {
    this.accessToken,
    this.b2bRetailStoreId,
    this.platformWide = false,
  }) : isAuthenticated = true;

  const CustomerSession.platformCustomer({
    this.accessToken,
    this.b2bRetailStoreId,
  })  : isAuthenticated = true,
        channel = null,
        platformWide = true;

  const CustomerSession._({
    required this.isAuthenticated,
    required this.channel,
    required this.accessToken,
    required this.b2bRetailStoreId,
    required this.platformWide,
  });

  final bool isAuthenticated;

  /// Authentication origin/capability partition, not the active commerce context.
  ///
  /// Platform customers intentionally use a null channel and [platformWide]
  /// rather than pretending to be a B2B identity.
  final CustomerChannel? channel;
  final String? accessToken;
  final int? b2bRetailStoreId;
  final bool platformWide;

  bool allowsChannel(CustomerChannel requested) =>
      isAuthenticated && (platformWide || channel == requested);

  CustomerSession asB2bRetailContext(int? retailStoreId) => CustomerSession._(
        isAuthenticated: isAuthenticated,
        channel: channel,
        accessToken: accessToken,
        b2bRetailStoreId: retailStoreId,
        platformWide: platformWide,
      );
}
