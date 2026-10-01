import '../auth/customer_session.dart';
import 'customer_commerce_context.dart';
import 'customer_routes.dart';

class CustomerRouteTarget {
  const CustomerRouteTarget({
    required this.definition,
    required this.location,
    required this.pathParameters,
    this.commerceContext,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final Map<String, String> pathParameters;
  final CustomerCommerceContext? commerceContext;
}

abstract final class CustomerRouteLocation {
  static String retailHome(int storeId) =>
      _build(CustomerRoutePaths.retailHome, {'store': storeId});

  static String retailProductDetails(int storeId, int productId) => _build(
        CustomerRoutePaths.retailProductDetails,
        {'store': storeId, 'product': productId},
      );

  static String retailCart(int storeId) =>
      _build(CustomerRoutePaths.retailCart, {'store': storeId});

  static String retailCheckout(int storeId) =>
      _build(CustomerRoutePaths.retailCheckout, {'store': storeId});

  static String retailProfile(int storeId) =>
      _build(CustomerRoutePaths.retailProfile, {'store': storeId});

  static String retailAddresses(int storeId) =>
      _build(CustomerRoutePaths.retailAddresses, {'store': storeId});

  static String retailFavorites(int storeId) =>
      _build(CustomerRoutePaths.retailFavorites, {'store': storeId});

  static String retailNotifications(int storeId) =>
      _build(CustomerRoutePaths.retailNotifications, {'store': storeId});

  static String retailOrders(int storeId) =>
      _build(CustomerRoutePaths.retailOrders, {'store': storeId});

  static String retailOrderDetails(int storeId, int orderId) => _build(
        CustomerRoutePaths.retailOrderDetails,
        {'store': storeId, 'order': orderId},
      );

  static String retailOrderTracking(int storeId, int orderId) => _build(
        CustomerRoutePaths.retailOrderTracking,
        {'store': storeId, 'order': orderId},
      );

  static String authLogin({
    required CustomerCommerceContext context,
    required String next,
  }) =>
      _authHandoff(
        CustomerRoutePaths.authLogin,
        context: context,
        next: next,
      );

  static String authRegister({
    required CustomerCommerceContext context,
    required String next,
  }) =>
      _authHandoff(
        CustomerRoutePaths.authRegister,
        context: context,
        next: next,
      );

  static CustomerRouteTarget? tryParse(String location) {
    final uri = Uri.tryParse(location);
    if (uri == null ||
        uri.hasScheme ||
        uri.hasAuthority ||
        uri.fragment.isNotEmpty ||
        !uri.path.startsWith('/') ||
        uri.path.startsWith('//') ||
        uri.path.contains(r'\')) {
      return null;
    }

    for (final definition in customerRouteDefinitions) {
      if (!definition.matches(uri.path)) {
        continue;
      }

      final parameters = _extractPathParameters(
        definition.pattern,
        uri.path,
      );

      CustomerCommerceContext? context;
      final storeId = int.tryParse(parameters['store'] ?? '');
      if (storeId != null && storeId > 0) {
        if (definition.channel == CustomerChannel.b2c) {
          context = CustomerCommerceContext.retail(storeId: storeId);
        } else if (definition.channel == CustomerChannel.b2b) {
          final receiverId =
              int.tryParse(uri.queryParameters['receiver'] ?? '');
          context = CustomerCommerceContext.wholesale(
            storeId: storeId,
            retailReceiverId:
                receiverId != null && receiverId > 0 ? receiverId : null,
          );
        }
      } else if (definition.pattern == CustomerRoutePaths.authLogin ||
          definition.pattern == CustomerRoutePaths.authRegister) {
        context = CustomerCommerceContext.tryParseQuery(uri.queryParameters);
      }

      return CustomerRouteTarget(
        definition: definition,
        location: location,
        pathParameters: parameters,
        commerceContext: context,
      );
    }

    return null;
  }

  static String? safeReturnLocation(
    String? value, {
    required CustomerCommerceContext expectedContext,
  }) {
    if (value == null || value.isEmpty || value != value.trim()) {
      return null;
    }

    final target = tryParse(value);
    if (target == null ||
        target.definition.pattern == CustomerRoutePaths.authLogin ||
        target.definition.pattern == CustomerRoutePaths.authRegister ||
        target.definition.pattern == CustomerRoutePaths.checkoutAuth ||
        target.definition.pattern == CustomerRoutePaths.b2bLogin) {
      return null;
    }

    final actualContext = target.commerceContext;
    if (actualContext == null || !actualContext.hasSameScope(expectedContext)) {
      return null;
    }

    return value;
  }

  static String _authHandoff(
    String path, {
    required CustomerCommerceContext context,
    required String next,
  }) {
    final safeNext = safeReturnLocation(
      next,
      expectedContext: context,
    );
    if (safeNext == null) {
      throw ArgumentError.value(
        next,
        'next',
        'must be a local route in the same commerce context',
      );
    }

    return Uri(
      path: path,
      queryParameters: {
        ...context.toQueryParameters(),
        'next': safeNext,
      },
    ).toString();
  }

  static String _build(String pattern, Map<String, int> values) {
    var path = pattern;
    for (final entry in values.entries) {
      if (entry.value <= 0) {
        throw ArgumentError.value(
          entry.value,
          entry.key,
          'must be a positive identifier',
        );
      }
      path = path.replaceAll(':' + entry.key, entry.value.toString());
    }

    if (path.contains(':')) {
      throw ArgumentError('Missing route parameter for $pattern');
    }

    return path;
  }

  static Map<String, String> _extractPathParameters(
    String pattern,
    String path,
  ) {
    final expected = Uri.parse(pattern).pathSegments;
    final actual = Uri.parse(path).pathSegments;
    final result = <String, String>{};

    for (var index = 0; index < expected.length; index++) {
      final segment = expected[index];
      if (segment.startsWith(':')) {
        result[segment.substring(1)] = actual[index];
      }
    }

    return result;
  }
}
