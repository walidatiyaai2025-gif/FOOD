import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/auth/customer_session_store.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_store.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  group('Customer commerce context', () {
    const retail = CustomerCommerceContext(
      channel: CustomerCommerceChannel.retail,
      storeId: 7,
      retailReceiverId: 19,
    );

    test('route builders preserve exact store and receiver context', () {
      final checkout = CustomerRouteLocations.retailCheckout(retail);

      expect(
        checkout,
        '/checkout/address-payment'
        '?channel=retail&store_id=7&retail_receiver_id=19',
      );
      expect(
        CustomerCommerceContext.tryParseLocation(checkout),
        retail,
      );
      expect(
        safeCustomerContextReturnLocation(checkout, context: retail),
        checkout,
      );
    });

    test('return validation rejects external and cross-store targets', () {
      expect(
        safeCustomerContextReturnLocation(
          'https://evil.example/checkout/address-payment'
          '?channel=retail&store_id=7&retail_receiver_id=19',
          context: retail,
        ),
        isNull,
      );
      expect(
        safeCustomerContextReturnLocation(
          '/checkout/address-payment'
          '?channel=retail&store_id=8&retail_receiver_id=19',
          context: retail,
        ),
        isNull,
      );
      expect(
        CustomerCommerceContext.tryParseLocation(
          '/retail/7/home?channel=retail&store_id=8',
        ),
        isNull,
      );
    });

    test('auth handoff keeps exact return context for login and register', () {
      final next = CustomerRouteLocations.retailCart(retail);
      final login = Uri.parse(
        CustomerRouteLocations.authHandoff(
          context: retail,
          next: next,
        ),
      );
      final register = Uri.parse(
        CustomerRouteLocations.authHandoff(
          context: retail,
          next: next,
          entry: CustomerAuthEntry.register,
        ),
      );

      expect(login.path, CustomerRoutePaths.checkoutAuth);
      expect(login.queryParameters['entry'], 'login');
      expect(login.queryParameters['store_id'], '7');
      expect(login.queryParameters['retail_receiver_id'], '19');
      expect(login.queryParameters['next'], next);

      expect(register.queryParameters['entry'], 'register');
      expect(register.queryParameters['next'], next);
    });
  });

  group('Persistent customer commerce state', () {
    test('active commerce context survives store recreation', () async {
      final storage = _MemorySecureStore();
      final first = SecureCustomerCommerceContextStore(storage: storage);
      const context = CustomerCommerceContext(
        channel: CustomerCommerceChannel.wholesale,
        storeId: 12,
        retailReceiverId: 4,
      );

      await first.write(context);

      final restored =
          await SecureCustomerCommerceContextStore(storage: storage).read();
      expect(restored, context);
    });

    test('guest cart tokens survive restart and never cross stores', () async {
      final storage = _MemorySecureStore();
      final first = SecureCustomerGuestCartTokenStore(storage: storage);

      await first.writeToken(7, 'guest-store-7');
      await first.writeToken(8, 'guest-store-8');

      final restored =
          SecureCustomerGuestCartTokenStore(storage: storage);
      expect(await restored.readToken(7), 'guest-store-7');
      expect(await restored.readToken(8), 'guest-store-8');
      expect(await restored.readToken(9), isNull);

      await restored.removeToken(7);
      expect(await restored.readToken(7), isNull);
      expect(await restored.readToken(8), 'guest-store-8');
    });

    test('invalid persisted guest cart payload fails closed', () async {
      final storage = _MemorySecureStore();
      await storage.write(
        'foodex.customer.guest_cart_tokens.v1',
        '{"version":1,"tokens":{"7":"ok","bad":"token"}}',
      );

      final store = SecureCustomerGuestCartTokenStore(storage: storage);
      expect(await store.readAll(), isEmpty);
      expect(
        await storage.read('foodex.customer.guest_cart_tokens.v1'),
        isNull,
      );
    });
  });

  group('Customer identity', () {
    test('platform identity is not modeled as a B2B channel', () async {
      const session = CustomerSession.platformCustomer(
        accessToken: 'platform-token',
      );
      expect(session.isAuthenticated, isTrue);
      expect(session.platformWide, isTrue);
      expect(session.channel, isNull);
      expect(session.allowsChannel(CustomerChannel.b2c), isTrue);
      expect(session.allowsChannel(CustomerChannel.b2b), isTrue);

      final storage = _MemorySecureStore();
      await SecureCustomerSessionStore(storage: storage).write(session);
      final restored =
          await SecureCustomerSessionStore(storage: storage).read();

      expect(restored, isNotNull);
      expect(restored!.accessToken, 'platform-token');
      expect(restored.channel, isNull);
      expect(restored.platformWide, isTrue);
    });
  });
}

class _MemorySecureStore implements CustomerSecureKeyValueStore {
  final Map<String, String> _values = <String, String>{};

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async {
    _values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    _values.remove(key);
  }
}
