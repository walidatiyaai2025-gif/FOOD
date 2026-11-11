import '../api/storefront_api.dart';
import '../auth/customer_session.dart';
import 'customer_commerce_context.dart';
import 'customer_commerce_context_store.dart';
import 'customer_routes.dart';

class CustomerCommerceContextSwitchException implements Exception {
  const CustomerCommerceContextSwitchException(this.code);

  final String code;

  @override
  String toString() => code;
}

class CustomerCommerceSwitchTarget {
  const CustomerCommerceSwitchTarget({
    required this.context,
    required this.name,
    required this.isPrincipalWholesale,
  });

  final CustomerCommerceContext context;
  final String name;
  final bool isPrincipalWholesale;
}

class CustomerCommerceSwitchResult {
  const CustomerCommerceSwitchResult({
    required this.session,
    required this.context,
    required this.location,
  });

  final CustomerSession session;
  final CustomerCommerceContext context;
  final String location;
}

/// Resolves and validates commerce-context switches for one authenticated
/// Platform Customer. The server-side store selector remains authoritative for
/// which Retail stores and principal Wholesale store are selectable.
class CustomerCommerceContextSwitcher {
  const CustomerCommerceContextSwitcher({
    required this.storefrontApi,
    required this.contextStore,
  });

  final StorefrontApi storefrontApi;
  final CustomerCommerceContextStore contextStore;

  Future<List<CustomerCommerceSwitchTarget>> availableTargets(
    CustomerSession session,
  ) async {
    _requirePlatformSession(session);

    final payload = await storefrontApi.selection();
    final entitlements = _map(payload['entitlements']);
    final principalId =
        _positiveInt(entitlements['principal_wholesale_store_id']);
    final wholesaleRows = _rows(payload['wholesale_stores'], 'wholesale');
    final retailRows = _rows(payload['retail_stores'], 'retail');

    // A principal marker without the corresponding authoritative entitlement
    // ID must never mint a locally selectable Wholesale commerce context.
    if (wholesaleRows.isNotEmpty && principalId == null) {
      throw const CustomerCommerceContextSwitchException(
        'principal_wholesale_contract_mismatch',
      );
    }

    CustomerCommerceSwitchTarget? principal;
    for (final row in wholesaleRows) {
      final storeId = _positiveInt(row['id']);
      if (storeId == null) {
        throw const CustomerCommerceContextSwitchException(
          'wholesale_store_id_invalid',
        );
      }
      if (row['is_platform_principal'] != true) {
        continue;
      }
      if (principalId != null && storeId != principalId) {
        throw const CustomerCommerceContextSwitchException(
          'principal_wholesale_contract_mismatch',
        );
      }
      if (principal != null) {
        throw const CustomerCommerceContextSwitchException(
          'multiple_principal_wholesale_stores',
        );
      }
      principal = CustomerCommerceSwitchTarget(
        context: CustomerCommerceContext(
          channel: CustomerCommerceChannel.wholesale,
          storeId: storeId,
        ),
        name: _name(row, fallback: 'Principal Wholesale'),
        isPrincipalWholesale: true,
      );
    }

    final retailContextIds = entitlements['retail_context_ids'];
    final expectsWholesale = entitlements['direct_b2b'] == true ||
        entitlements['support_access'] == true ||
        (retailContextIds is List && retailContextIds.isNotEmpty);
    if ((expectsWholesale || principalId != null || wholesaleRows.isNotEmpty) &&
        principal == null) {
      throw const CustomerCommerceContextSwitchException(
        'principal_wholesale_missing',
      );
    }

    final targets = <CustomerCommerceSwitchTarget>[
      if (principal != null) principal,
    ];

    final seenRetail = <int>{};
    for (final row in retailRows) {
      final storeId = _positiveInt(row['id'] ?? row['store_id']);
      if (storeId == null) {
        throw const CustomerCommerceContextSwitchException(
          'retail_store_id_invalid',
        );
      }
      if (!seenRetail.add(storeId)) {
        throw const CustomerCommerceContextSwitchException(
          'duplicate_retail_context',
        );
      }
      targets.add(
        CustomerCommerceSwitchTarget(
          context: CustomerCommerceContext(
            channel: CustomerCommerceChannel.retail,
            storeId: storeId,
          ),
          name: _name(row, fallback: 'Retail store #$storeId'),
          isPrincipalWholesale: false,
        ),
      );
    }

    return List<CustomerCommerceSwitchTarget>.unmodifiable(targets);
  }

  Future<CustomerCommerceSwitchResult> switchTo({
    required CustomerSession session,
    required CustomerCommerceContext requested,
  }) async {
    _requirePlatformSession(session);

    final targets = await availableTargets(session);
    CustomerCommerceSwitchTarget? authorized;
    for (final target in targets) {
      if (target.context.sameScope(requested)) {
        authorized = target;
        break;
      }
    }

    if (authorized == null) {
      throw const CustomerCommerceContextSwitchException(
        'commerce_context_not_authorized',
      );
    }

    final context = authorized.context;
    await contextStore.write(context);

    // Shopping context must never retain the old B2B Retail-receiver persona.
    // This keeps the same token/identity while forcing fresh APIs to resolve
    // against the newly selected commerce context.
    final normalizedSession = session.asB2bRetailContext(null);
    final location = context.isWholesale
        ? CustomerRouteLocations.wholesaleHome(context)
        : CustomerRouteLocations.retailHome(context);

    return CustomerCommerceSwitchResult(
      session: normalizedSession,
      context: context,
      location: location,
    );
  }

  static void _requirePlatformSession(CustomerSession session) {
    if (!session.isAuthenticated || !session.platformWide) {
      throw const CustomerCommerceContextSwitchException(
        'platform_customer_session_required',
      );
    }
  }

  static List<Map<String, dynamic>> _rows(Object? raw, String scope) {
    if (raw is! List) {
      throw CustomerCommerceContextSwitchException(
        '${scope}_selector_rows_invalid',
      );
    }
    final rows = <Map<String, dynamic>>[];
    for (final row in raw) {
      if (row is! Map || row.keys.any((key) => key is! String)) {
        throw CustomerCommerceContextSwitchException(
          '${scope}_selector_rows_invalid',
        );
      }
      rows.add(Map<String, dynamic>.from(row));
    }
    return List<Map<String, dynamic>>.unmodifiable(rows);
  }

  static Map<String, dynamic> _map(Object? raw) {
    if (raw is! Map || raw.keys.any((key) => key is! String)) {
      throw const CustomerCommerceContextSwitchException(
        'selector_entitlements_invalid',
      );
    }
    return Map<String, dynamic>.from(raw);
  }

  static int? _positiveInt(Object? raw) {
    final value = raw is int ? raw : int.tryParse(raw?.toString() ?? '');
    return value != null && value > 0 ? value : null;
  }

  static String _name(
    Map<String, dynamic> row, {
    required String fallback,
  }) {
    final raw = row['name'];
    final value = raw?.toString().trim();
    return value == null || value.isEmpty ? fallback : value;
  }
}
