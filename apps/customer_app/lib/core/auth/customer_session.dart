enum CustomerChannel { b2c, b2b }

class CustomerSession {
  const CustomerSession.guest()
      : isAuthenticated = false,
        channel = null,
        accessToken = null,
        b2bRetailStoreId = null;

  const CustomerSession.authenticated(
    this.channel, {
    this.accessToken,
    this.b2bRetailStoreId,
  }) : isAuthenticated = true;

  final bool isAuthenticated;
  final CustomerChannel? channel;
  final String? accessToken;
  final int? b2bRetailStoreId;

  CustomerSession asB2bRetailContext(int? retailStoreId) =>
      CustomerSession.authenticated(
        CustomerChannel.b2b,
        accessToken: accessToken,
        b2bRetailStoreId: retailStoreId,
      );
}
