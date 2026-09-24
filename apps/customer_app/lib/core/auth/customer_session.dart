enum CustomerChannel { b2c, b2b }

class CustomerSession {
  const CustomerSession.guest()
      : isAuthenticated = false,
        channel = null;

  const CustomerSession.authenticated(this.channel) : isAuthenticated = true;

  final bool isAuthenticated;
  final CustomerChannel? channel;
}
