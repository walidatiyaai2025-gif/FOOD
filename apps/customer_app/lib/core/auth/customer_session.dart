enum CustomerChannel { b2c, b2b }

class CustomerSession {
  const CustomerSession.guest()
      : isAuthenticated = false,
        channel = null,
        accessToken = null;

  const CustomerSession.authenticated(this.channel, {this.accessToken}) : isAuthenticated = true;

  final bool isAuthenticated;
  final CustomerChannel? channel;
  final String? accessToken;
}
