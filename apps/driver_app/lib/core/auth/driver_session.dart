enum DriverChannel { b2c, b2b }

class DriverSession {
  const DriverSession({
    required this.token,
    required this.name,
    required this.email,
    required this.locale,
    required this.channel,
  });

  final String token;
  final String name;
  final String email;
  final String locale;
  final DriverChannel channel;
}

abstract interface class DriverAuthRepository {
  Future<DriverSession> login({required String email, required String password});
  Future<void> logout(String token);
}

class DriverAuthenticationException implements Exception {
  const DriverAuthenticationException();
}

class DriverRoleDeniedException implements Exception {
  const DriverRoleDeniedException();
}

class DriverSessionExpiredException implements Exception {
  const DriverSessionExpiredException();
}

class DriverAccessDeniedException implements Exception {
  const DriverAccessDeniedException();
}

class DriverOfflineException implements Exception {
  const DriverOfflineException();
}

class DriverApiException implements Exception {
  const DriverApiException([this.message = 'Driver API request failed.']);
  final String message;

  @override
  String toString() => message;
}
