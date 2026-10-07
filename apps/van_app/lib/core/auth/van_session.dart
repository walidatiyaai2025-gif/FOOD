class VanSession {
  const VanSession({
    required this.token,
    required this.name,
    required this.email,
    required this.locale,
    required this.permissions,
  });

  final String token;
  final String name;
  final String email;
  final String locale;
  final Set<String> permissions;

  bool get canUseVan => permissions.contains('van.login');
}

abstract interface class VanAuthRepository {
  Future<VanSession> login({
    required String email,
    required String password,
  });

  Future<void> logout(String token);
}

class VanAuthenticationException implements Exception {
  const VanAuthenticationException();
}

class VanAccessDeniedException implements Exception {
  const VanAccessDeniedException();
}

class VanSessionExpiredException implements Exception {
  const VanSessionExpiredException();
}

class VanOfflineException implements Exception {
  const VanOfflineException();
}

class VanApiException implements Exception {
  const VanApiException([this.message = 'Van API request failed.']);

  final String message;

  @override
  String toString() => message;
}
