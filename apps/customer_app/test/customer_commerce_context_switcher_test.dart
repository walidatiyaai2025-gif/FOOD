import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_store.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_switcher.dart';

void main() {
  const session = CustomerSession.platformCustomer(
    accessToken: 'same-platform-token',
    b2bRetailStoreId: 91,
  );

  test('principal Wholesale is first and switches to existing b2b home', () async {
    final store = _MemoryContextStore();
    final switcher = CustomerCommerceContextSwitcher(
      storefrontApi: _SelectorApi(),
      contextStore: store,
    );

    final targets = await switcher.availableTargets(session);
    expect(targets, hasLength(3));
    expect(targets.first.isPrincipalWholesale, isTrue);
    expect(targets.first.context.channel, CustomerCommerceChannel.wholesale);
    expect(targets.first.context.storeId, 7);

    final result = await switcher.switchTo(
      session: session,
      requested: const CustomerCommerceContext(
        channel: CustomerCommerceChannel.wholesale,
        storeId: 7,
      ),
    );

    expect(
      result.location,
      '/b2b/home?channel=wholesale&store_id=7',
    );
    expect(result.context, store.value);
    expect(result.session.accessToken, session.accessToken);
    expect(result.session.platformWide, isTrue);
    expect(result.session.b2bRetailStoreId, isNull);
  });

  test('allowed Retail switch keeps the same identity and exact store route',
      () async {
    final store = _MemoryContextStore();
    final switcher = CustomerCommerceContextSwitcher(
      storefrontApi: _SelectorApi(),
      contextStore: store,
    );

    final result = await switcher.switchTo(
      session: session,
      requested: const CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 22,
      ),
    );

    expect(
      result.location,
      '/retail/22/home?channel=retail&store_id=22',
    );
    expect(result.session.accessToken, 'same-platform-token');
    expect(result.context.storeId, 22);
    expect(store.value, result.context);
  });

  test('forged store or non-principal Wholesale target is rejected', () async {
    final store = _MemoryContextStore();
    final switcher = CustomerCommerceContextSwitcher(
      storefrontApi: _SelectorApi(),
      contextStore: store,
    );

    for (final requested in <CustomerCommerceContext>[
      const CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 999,
      ),
      const CustomerCommerceContext(
        channel: CustomerCommerceChannel.wholesale,
        storeId: 8,
      ),
    ]) {
      await expectLater(
        switcher.switchTo(session: session, requested: requested),
        throwsA(
          isA<CustomerCommerceContextSwitchException>().having(
            (error) => error.code,
            'code',
            'commerce_context_not_authorized',
          ),
        ),
      );
    }

    expect(store.value, isNull);
  });

  test('non-platform session cannot use cross-context switch engine', () async {
    final switcher = CustomerCommerceContextSwitcher(
      storefrontApi: _SelectorApi(),
      contextStore: _MemoryContextStore(),
    );

    await expectLater(
      switcher.availableTargets(
        const CustomerSession.authenticated(
          CustomerChannel.b2b,
          accessToken: 'legacy-token',
        ),
      ),
      throwsA(
        isA<CustomerCommerceContextSwitchException>().having(
          (error) => error.code,
          'code',
          'platform_customer_session_required',
        ),
      ),
    );
  });

  test('selector contract fails closed when principal marker is missing',
      () async {
    final switcher = CustomerCommerceContextSwitcher(
      storefrontApi: _SelectorApi(markPrincipal: false),
      contextStore: _MemoryContextStore(),
    );

    await expectLater(
      switcher.availableTargets(session),
      throwsA(
        isA<CustomerCommerceContextSwitchException>().having(
          (error) => error.code,
          'code',
          'principal_wholesale_missing',
        ),
      ),
    );
  });
}

class _SelectorApi implements StorefrontApi {
  const _SelectorApi({this.markPrincipal = true});

  final bool markPrincipal;

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      {
        'retail_stores': [
          {'id': 22, 'name': 'Retail B'},
          {'id': 23, 'name': 'Retail C'},
        ],
        'wholesale_stores': [
          {
            'id': 7,
            'name': 'FOODEX Main Wholesale',
            'is_platform_principal': markPrincipal,
          },
        ],
        'entitlements': {
          'direct_b2b': true,
          'principal_wholesale_store_id': 7,
        },
      };

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async =>
      const {};
}

class _MemoryContextStore implements CustomerCommerceContextStore {
  CustomerCommerceContext? value;

  @override
  Future<CustomerCommerceContext?> read() async => value;

  @override
  Future<void> write(CustomerCommerceContext context) async {
    value = context;
  }

  @override
  Future<void> clear() async {
    value = null;
  }
}
