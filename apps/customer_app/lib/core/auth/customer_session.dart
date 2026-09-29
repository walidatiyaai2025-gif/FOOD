enum CustomerChannel { b2c, b2b }

class CustomerSession {
  const CustomerSession.guest()
      : isAuthenticated = false,
        isPlatformCustomer = false,
        channel = null,
        accessToken = null,
        b2bRetailStoreId = null;

  const CustomerSession.authenticated(
    this.channel, {
    this.accessToken,
    this.b2bRetailStoreId,
  })  : isAuthenticated = true,
        isPlatformCustomer = false;

  const CustomerSession.platformCustomer({
    required this.accessToken,
  })  : isAuthenticated = true,
        isPlatformCustomer = true,
        channel = null,
        b2bRetailStoreId = null;

  final bool isAuthenticated;
  final bool isPlatformCustomer;
  final CustomerChannel? channel;
  final String? accessToken;
  final int? b2bRetailStoreId;

  CustomerSession asB2bRetailContext(int? retailStoreId) {
    if (isPlatformCustomer) {
      return CustomerSession.platformCustomer(accessToken: accessToken);
    }

    return CustomerSession.authenticated(
      channel ?? CustomerChannel.b2c,
      accessToken: accessToken,
      b2bRetailStoreId: retailStoreId,
    );
  }
}
