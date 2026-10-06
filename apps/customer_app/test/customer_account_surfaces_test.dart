import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/location/customer_location_service.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';
import 'package:foodex_customer_app/features/customer_account/customer_account_data.dart';
import 'package:foodex_customer_app/features/customer_account/customer_account_screen.dart';
import 'package:foodex_customer_app/features/customer_account/customer_account_v3_widgets.dart';
import 'package:foodex_customer_app/features/customer_account/customer_address_book_screen.dart';
import 'package:foodex_customer_app/features/customer_account/customer_favorites_screen.dart';
import 'package:foodex_customer_app/features/customer_account/customer_notification_center_screen.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';

void main() {
  testWidgets(
    'account overview isolates section failure and scopes favorites to store',
    (tester) async {
      final api = _AccountFakeApi(
        profileError: const B2cAccountException('network_unavailable'),
        addressesValue: {
          'data': [
            {'id': 1},
            {'id': 2},
          ],
        },
        favoritesValue: {
          'data': [
            {
              'product': {'id': 8, 'name': 'Coffee'},
            },
          ],
        },
        notificationsValue: const {'data': []},
      );

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('en'),
          home: CustomerAccountScreen(
            api: api,
            favoritesApi: api,
            retailStoreId: 19,
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(api.favoriteLoads, <int?>[19]);
      expect(find.byKey(const ValueKey('customer-account-screen')), findsOneWidget);
      expect(find.byKey(const ValueKey('customer-account-profile-section')), findsOneWidget);
      expect(find.text('2'), findsOneWidget);
      expect(find.text('1'), findsOneWidget);
      expect(find.byIcon(Icons.refresh_rounded), findsOneWidget);
    },
  );

  testWidgets('favorites remove action keeps exact retail store context',
      (tester) async {
    final api = _AccountFakeApi(
      favoritesValue: {
        'data': [
          {
            'product': {'id': 8, 'name': 'Coffee', 'price': 2.5},
          },
        ],
      },
    );

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: CustomerFavoritesScreen(
          api: api,
          favoritesApi: api,
          retailStoreId: 19,
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(
      find.byKey(const ValueKey('customer-favorite-remove-8')),
    );
    await tester.pumpAndSettle();

    expect(api.favoriteLoads, everyElement(19));
    expect(api.removedFavorites, <(int, int?)>[(8, 19)]);
  });

  testWidgets(
    'notification center marks read and emits only structured order target',
    (tester) async {
      final api = _AccountFakeApi(
        notificationsValue: {
          'data': [
            {
              'id': 7,
              'title': 'Order update',
              'body': 'Preparing',
              'read_at': null,
              'data': {
                'order_id': 55,
                'channel': 'b2c',
                'store_id': 19,
                'url': 'https://untrusted.example/redirect',
              },
            },
          ],
        },
      );
      CustomerNotificationTarget? target;

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('en'),
          home: CustomerNotificationCenterScreen(
            api: api,
            onOpenOrder: (value) => target = value,
          ),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(
        find.byKey(const ValueKey('customer-notification-7')),
      );
      await tester.pumpAndSettle();

      expect(api.readNotifications, <int>[7]);
      expect(target?.orderId, 55);
      expect(target?.channel, 'b2c');
      expect(target?.storeId, 19);
    },
  );

  testWidgets('address book saves authoritative GPS location with address',
      (tester) async {
    final api = _AccountFakeApi(addressesValue: const {'data': []});

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: CustomerAddressBookScreen(
          api: api,
          locationService: const _LocationFake(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('customer-address-add')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('customer-address-line1')),
      'Test Street',
    );
    final currentLocation = find.byKey(
      const ValueKey('customer-address-current-location'),
    );
    await tester.ensureVisible(currentLocation);
    await tester.tap(currentLocation);
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('customer-address-coordinates')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('customer-address-save')));
    await tester.pumpAndSettle();

    expect(api.createdAddresses, hasLength(1));
    expect(api.createdAddresses.single['line1'], 'Test Street');
    expect(api.createdAddresses.single['latitude'], 29.3759);
    expect(api.createdAddresses.single['longitude'], 47.9774);
    expect(api.createdAddresses.single['location_source'], 'current_location');
  });

  testWidgets(
    'account V3 stays coherent in RTL on a narrow phone with enlarged text',
    (tester) async {
      final api = _AccountFakeApi(
        addressesValue: {
          'data': [
            {
              'id': 1,
              'label': 'Home',
              'line1': 'Street 1',
              'city': 'Kuwait City',
              'is_default': true,
            },
          ],
        },
        favoritesValue: const {'data': []},
        notificationsValue: const {'data': []},
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: FoodexTheme.light(),
          builder: (context, child) => MediaQuery(
            data: MediaQuery.of(context).copyWith(
              size: const Size(360, 760),
              textScaler: const TextScaler.linear(1.3),
            ),
            child: Directionality(
              textDirection: TextDirection.rtl,
              child: child!,
            ),
          ),
          home: CustomerAccountScreen(
            api: api,
            favoritesApi: api,
            retailStoreId: 19,
            onOpenAddresses: () {},
            onOpenFavorites: () {},
            onOpenNotifications: () {},
            onOpenOrders: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(CustomerCurvedHeaderSurface), findsOneWidget);
      expect(find.byType(CustomerAccountAvatar), findsOneWidget);
      expect(find.byType(CustomerAccountShortcutCard), findsNWidgets(3));

      await tester.drag(
        find.byType(ListView),
        const Offset(0, -300),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('customer-account-orders-section')),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'account surfaces compact headers and refresh automatically on resume',
    (tester) async {
      final api = _AccountFakeApi(
        addressesValue: const {'data': []},
        favoritesValue: const {'data': []},
        notificationsValue: const {'data': []},
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: FoodexTheme.light(),
          home: CustomerAccountScreen(
            api: api,
            favoritesApi: api,
            retailStoreId: 19,
          ),
        ),
      );
      await tester.pumpAndSettle();

      final surface = tester.widget<CustomerCurvedHeaderSurface>(
        find.byType(CustomerCurvedHeaderSurface),
      );
      expect(
        surface.headerPadding,
        const EdgeInsetsDirectional.fromSTEB(12, 6, 12, 8),
      );
      expect(
        tester.getSize(find.byType(CustomerAccountHeader)).height,
        lessThan(72),
      );

      final profileBefore = api.profileLoads;
      final addressesBefore = api.addressLoads;
      final favoritesBefore = api.favoriteLoads.length;
      final notificationsBefore = api.notificationLoads;

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();

      expect(api.profileLoads, profileBefore + 1);
      expect(api.addressLoads, addressesBefore + 1);
      expect(api.favoriteLoads.length, favoritesBefore + 1);
      expect(api.notificationLoads, notificationsBefore + 1);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('notification and address empty states use V3 state primitives',
      (tester) async {
    final api = _AccountFakeApi(
      addressesValue: const {'data': []},
      notificationsValue: const {'data': []},
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: FoodexTheme.light(),
        home: CustomerNotificationCenterScreen(api: api),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(CustomerCurvedHeaderSurface), findsOneWidget);
    expect(find.byType(CustomerStateView), findsOneWidget);

    await tester.pumpWidget(
      MaterialApp(
        theme: FoodexTheme.light(),
        home: CustomerAddressBookScreen(api: api),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(CustomerCurvedHeaderSurface), findsOneWidget);
    expect(find.byType(CustomerStateView), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

}

class _LocationFake implements CustomerLocationService {
  const _LocationFake();

  @override
  Future<CustomerLocationPoint> currentLocation() async =>
      const CustomerLocationPoint(
        latitude: 29.3759,
        longitude: 47.9774,
        accuracyMeters: 4.5,
      );
}

class _AccountFakeApi implements B2cAccountApi, B2cRetailFavoritesApi {
  _AccountFakeApi({
    this.profileError,
    this.addressesValue = const {'data': []},
    this.favoritesValue = const {'data': []},
    this.notificationsValue = const {'data': []},
  });

  final Object? profileError;
  final Object? profileValue = const {
    'name': 'Customer',
    'email': 'c@example.test',
  };
  final Object? addressesValue;
  final Object? favoritesValue;
  final Object? notificationsValue;

  int profileLoads = 0;
  int addressLoads = 0;
  int notificationLoads = 0;
  final List<int?> favoriteLoads = <int?>[];
  final List<(int, int?)> removedFavorites = <(int, int?)>[];
  final List<int> readNotifications = <int>[];
  final List<Map<String, dynamic>> createdAddresses =
      <Map<String, dynamic>>[];

  @override
  Future<Object?> profile() async {
    profileLoads += 1;
    if (profileError != null) throw profileError!;
    return profileValue;
  }

  @override
  Future<Object?> addresses() async {
    addressLoads += 1;
    return addressesValue;
  }

  @override
  Future<Object?> favorites() async => favoritesValue;

  @override
  Future<void> removeFavorite(int productId) async {
    removedFavorites.add((productId, null));
  }

  @override
  Future<Object?> favoritesForStore(int storeId) async {
    favoriteLoads.add(storeId);
    return favoritesValue;
  }

  @override
  Future<void> addFavoriteForStore(int storeId, int productId) async {}

  @override
  Future<void> removeFavoriteForStore(int storeId, int productId) async {
    removedFavorites.add((productId, storeId));
  }

  @override
  Future<Object?> notifications({String locale = 'ar'}) async {
    notificationLoads += 1;
    return notificationsValue;
  }

  @override
  Future<void> markNotificationRead(int notificationId) async {
    readNotifications.add(notificationId);
  }

  @override
  Future<Object?> createAddress(Map<String, dynamic> values) async {
    createdAddresses.add(Map<String, dynamic>.from(values));
    return values;
  }

  @override
  Future<Object?> updateProfile(Map<String, dynamic> values) async => values;

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      Future<Object?>.value(null);
}
