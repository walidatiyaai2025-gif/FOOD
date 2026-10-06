import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_store.dart';
import 'package:foodex_customer_app/features/retail/commerce/retail_commerce_api.dart';
import 'package:foodex_customer_app/features/retail/commerce/retail_commerce_screens.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('checkout options come from authenticated backend contract', () async {
    final api = HttpRetailCheckoutOptionsApi(
      baseUrl: 'https://foodex.example',
      token: 'customer-token',
      client: MockClient((request) async {
        expect(request.url.path, '/api/v1/checkout/options');
        expect(request.url.queryParameters['store_id'], '7');
        expect(request.headers['Authorization'], 'Bearer customer-token');
        expect(request.headers['X-FOODEX-Store-ID'], '7');

        return http.Response(
          jsonEncode({
            'store_id': 7,
            'addresses': [
              {
                'id': 9,
                'label': 'Home',
                'line1': 'Street 1',
                'city': 'Kuwait City',
                'is_default': true,
              },
            ],
            'payment_methods': ['cash_on_delivery', 'knet'],
          }),
          200,
        );
      }),
    );

    final options = await api.load(storeId: 7);
    expect(options.storeId, 7);
    expect(options.addresses.single.id, 9);
    expect(options.paymentMethods, ['cash_on_delivery', 'knet']);
  });


  test(
    'authenticated merge hydrates persisted guest token and retires it once',
    () async {
      const guestToken =
          'guest-token-store-7-abcdefghijklmnopqrstuvwxyz-0123456789';
      final guestSession = CustomerGuestSession();
      final tokenStore = _MemoryGuestCartTokenStore({
        7: guestToken,
      });
      final accountApi = _RecordingB2cAccountApi(guestSession);

      final api = DefaultRetailCommerceApi(
        accountApi: accountApi,
        actionApi: _NoopCustomerActionApi(),
        checkoutOptionsApi: _NoopCheckoutOptionsApi(),
        guestSession: guestSession,
        guestCartTokenStore: tokenStore,
      );

      final cart = await api.mergeGuestCartAfterAuthentication(storeId: 7);

      expect(cart.storeId, 7);
      expect(accountApi.cartCalls, 1);
      expect(accountApi.lastStoreId, 7);
      expect(accountApi.guestTokenSeen, guestToken);
      expect(guestSession.tokenForStore(7), isNull);
      expect(await tokenStore.readToken(7), isNull);
      expect(tokenStore.removeCalls, 1);

      await api.loadCart(storeId: 7);
      expect(accountApi.cartCalls, 2);
      expect(accountApi.guestTokenSeen, isNull);
      expect(tokenStore.removeCalls, 1);
    },
  );

  test('idempotency key is reused after retry and changes with payload', () async {
    final api = _FakeRetailCommerceApi();
    var generated = 0;
    final guard = RetailCheckoutSubmissionGuard(
      api,
      idempotencyKeyFactory: (storeId) =>
          'retail-$storeId-key-${++generated}-000000',
    );

    api.checkoutFailures = 1;
    await expectLater(
      guard.submit(
        storeId: 7,
        addressId: 9,
        paymentMethod: 'knet',
      ),
      throwsA(isA<RetailCommerceException>()),
    );

    await guard.submit(
      storeId: 7,
      addressId: 9,
      paymentMethod: 'knet',
    );
    expect(api.idempotencyKeys[0], api.idempotencyKeys[1]);

    await guard.submit(
      storeId: 7,
      addressId: 10,
      paymentMethod: 'knet',
    );
    expect(api.idempotencyKeys[2], isNot(api.idempotencyKeys[1]));
  });

  test('submission guard blocks simultaneous duplicate submit', () async {
    final api = _FakeRetailCommerceApi();
    api.checkoutCompleter = Completer<RetailCreatedOrder>();
    final guard = RetailCheckoutSubmissionGuard(
      api,
      idempotencyKeyFactory: (_) => 'retail-7-fixed-key-000000',
    );

    final first = guard.submit(
      storeId: 7,
      addressId: 9,
      paymentMethod: 'cash_on_delivery',
    );
    await expectLater(
      guard.submit(
        storeId: 7,
        addressId: 9,
        paymentMethod: 'cash_on_delivery',
      ),
      throwsA(
        isA<RetailCommerceException>().having(
          (error) => error.code,
          'code',
          'checkout_in_progress',
        ),
      ),
    );

    api.checkoutCompleter!.complete(
      const RetailCreatedOrder(id: 101, storeId: 7),
    );
    expect((await first).id, 101);
    expect(api.submitCalls, 1);
  });

  testWidgets('cart uses geometry skeleton while backend is loading',
      (tester) async {
    final api = _FakeRetailCommerceApi()
      ..cartCompleter = Completer<RetailCartSnapshot>();

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailCartScreen(
          storeId: 7,
          api: api,
          isAuthenticated: true,
          onCheckout: (_) {},
        ),
      ),
    );
    await tester.pump();

    expect(find.byType(CustomerSkeletonBox), findsNWidgets(3));
    expect(find.byType(CircularProgressIndicator), findsNothing);

    api.cartCompleter!.complete(api.cart);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('retail-cart-summary')), findsOneWidget);
  });

  testWidgets('cart refreshes authoritative snapshot on resume', (tester) async {
    final api = _FakeRetailCommerceApi();

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailCartScreen(
          storeId: 7,
          api: api,
          isAuthenticated: true,
          onCheckout: (_) {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final beforeResume = api.loadCartCalls;
    expect(beforeResume, 1);

    await tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.paused,
    );
    await tester.pump();
    await tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    );
    await tester.pumpAndSettle();

    expect(api.loadCartCalls, beforeResume + 1);
    expect(tester.takeException(), isNull);
  });

  testWidgets('cart V3 fits narrow RTL layout with larger text', (tester) async {
    final api = _FakeRetailCommerceApi();
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context).copyWith(
            textScaler: const TextScaler.linear(1.35),
          ),
          child: Directionality(
            textDirection: TextDirection.rtl,
            child: child!,
          ),
        ),
        home: RetailCartScreen(
          storeId: 7,
          api: api,
          isAuthenticated: true,
          onCheckout: (_) {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(
      find.byKey(const ValueKey('retail-cart-minus-1')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('retail-cart-plus-1')),
      findsOneWidget,
    );
  });

  testWidgets('guest auth handoff preserves store and merges once', (tester) async {
    final guestApi = _FakeRetailCommerceApi();
    final authenticatedApi = _FakeRetailCommerceApi();
    int? checkoutStore;
    final authIntents = <RetailAuthIntent>[];
    final authStores = <int>[];

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailCartScreen(
          storeId: 7,
          api: guestApi,
          isAuthenticated: false,
          onAuthenticate: (intent, storeId) async {
            authIntents.add(intent);
            authStores.add(storeId);
            return authenticatedApi;
          },
          onCheckout: (storeId) => checkoutStore = storeId,
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('retail-cart-checkout')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('retail-cart-register')));
    await tester.pumpAndSettle();

    expect(authIntents, [RetailAuthIntent.register]);
    expect(authStores, [7]);
    expect(authenticatedApi.mergeCalls, 1);
    expect(authenticatedApi.lastMergeStore, 7);
    expect(checkoutStore, 7);
  });

  testWidgets('checkout offers Add Address when no valid address exists',
      (tester) async {
    final api = _FakeRetailCommerceApi()..emptyAddresses = true;
    var addAddressCalls = 0;

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailCheckoutScreen(
          storeId: 7,
          api: api,
          onAddAddress: (_) async {
            addAddressCalls++;
          },
          onOrderCreated: (_, __) {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('retail-checkout-add-address')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('retail-checkout-add-address')),
    );
    await tester.pumpAndSettle();

    expect(addAddressCalls, 1);
  });

  testWidgets('checkout exposes backend payment options and created order',
      (tester) async {
    final api = _FakeRetailCommerceApi();
    int? createdOrder;
    int? createdStore;

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailCheckoutScreen(
          storeId: 7,
          api: api,
          onOrderCreated: (orderId, storeId) {
            createdOrder = orderId;
            createdStore = storeId;
          },
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('retail-checkout-address-section')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('retail-checkout-payment-section')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('retail-checkout-payment-method')),
      findsOneWidget,
    );
    expect(find.text('Cash on delivery'), findsOneWidget);
    expect(find.byType(TextField), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('retail-checkout-submit')));
    await tester.pumpAndSettle();

    expect(api.lastSubmitStore, 7);
    expect(api.lastPaymentMethod, 'cash_on_delivery');
    expect(createdOrder, 101);
    expect(createdStore, 7);
  });
}

class _FakeRetailCommerceApi implements RetailCommerceApi {
  bool emptyAddresses = false;
  int loadCartCalls = 0;
  int mergeCalls = 0;
  int? lastMergeStore;
  int submitCalls = 0;
  int? lastSubmitStore;
  String? lastPaymentMethod;
  int checkoutFailures = 0;
  Completer<RetailCreatedOrder>? checkoutCompleter;
  Completer<RetailCartSnapshot>? cartCompleter;
  final List<String> idempotencyKeys = <String>[];

  RetailCartSnapshot get cart => const RetailCartSnapshot(
        storeId: 7,
        currency: 'KWD',
        items: [
          RetailCartItem(
            id: 1,
            productId: 42,
            name: 'Milk',
            quantity: 1,
            lineTotal: 1.25,
            isAvailable: true,
          ),
        ],
        subtotal: 1.25,
        grandTotal: 1.25,
        hasUnavailableItems: false,
      );

  @override
  Future<RetailCheckoutOptions> checkoutOptions({required int storeId}) async =>
      RetailCheckoutOptions(
        storeId: storeId,
        addresses: emptyAddresses
            ? const <RetailCheckoutAddress>[]
            : const [
                RetailCheckoutAddress(
                  id: 9,
                  label: 'Home',
                  line1: 'Street 1',
                  city: 'Kuwait City',
                  isDefault: true,
                ),
              ],
        paymentMethods: const ['cash_on_delivery', 'knet'],
      );

  @override
  Future<RetailCartSnapshot> loadCart({required int storeId}) async {
    loadCartCalls += 1;
    final pending = cartCompleter;
    if (pending != null) return pending.future;
    return cart;
  }

  @override
  Future<RetailCartSnapshot> mergeGuestCartAfterAuthentication({
    required int storeId,
  }) async {
    mergeCalls++;
    lastMergeStore = storeId;
    return cart;
  }

  @override
  Future<RetailCartSnapshot> removeItem({
    required int storeId,
    required int itemId,
  }) async =>
      cart;

  @override
  Future<RetailCreatedOrder> submitCheckout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async {
    submitCalls++;
    lastSubmitStore = storeId;
    lastPaymentMethod = paymentMethod;
    idempotencyKeys.add(idempotencyKey);

    if (checkoutFailures > 0) {
      checkoutFailures--;
      throw const RetailCommerceException('network_retry');
    }
    if (checkoutCompleter != null) {
      return checkoutCompleter!.future;
    }
    return RetailCreatedOrder(id: 101, storeId: storeId);
  }

  @override
  Future<RetailCartSnapshot> updateQuantity({
    required int storeId,
    required int itemId,
    required double quantity,
  }) async =>
      cart;
}

class _MemoryGuestCartTokenStore implements CustomerGuestCartTokenStore {
  _MemoryGuestCartTokenStore([Map<int, String>? seed])
      : _tokens = <int, String>{...?seed};

  final Map<int, String> _tokens;
  int removeCalls = 0;

  @override
  Future<void> clear() async => _tokens.clear();

  @override
  Future<Map<int, String>> readAll() async => Map<int, String>.from(_tokens);

  @override
  Future<String?> readToken(int storeId) async => _tokens[storeId];

  @override
  Future<void> removeToken(int storeId) async {
    removeCalls++;
    _tokens.remove(storeId);
  }

  @override
  Future<void> writeToken(int storeId, String token) async {
    _tokens[storeId] = token;
  }
}

class _RecordingB2cAccountApi implements B2cAccountApi {
  _RecordingB2cAccountApi(this.guestSession);

  final CustomerGuestSession guestSession;
  int cartCalls = 0;
  int? lastStoreId;
  String? guestTokenSeen;

  @override
  Future<Object?> cart({int? storeId}) async {
    cartCalls++;
    lastStoreId = storeId;
    guestTokenSeen =
        storeId == null ? guestSession.token : guestSession.tokenForStore(storeId);
    return {
      'store_id': storeId,
      'currency': 'KWD',
      'items': <Object>[],
      'subtotal': 0,
      'has_unavailable_items': false,
      'quote': {
        'grand_total': 0,
      },
    };
  }

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError(invocation.memberName.toString());
}

class _NoopCustomerActionApi implements CustomerActionApi {
  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError(invocation.memberName.toString());
}

class _NoopCheckoutOptionsApi implements RetailCheckoutOptionsApi {
  @override
  Future<RetailCheckoutOptions> load({required int storeId}) {
    throw UnimplementedError();
  }
}

