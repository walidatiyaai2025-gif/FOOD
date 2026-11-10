import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/features/customer_account/customer_account_screen.dart';

void main() {
  test('all existing deletion statuses have AR/EN user-facing copy', () {
    for (final status in <String>[
      'COMPLETED',
      'PENDING_RETENTION',
      'OPERATIONAL_REVIEW_REQUIRED',
      'PROCESSING',
      'REQUESTED',
      'CANCELLED',
    ]) {
      for (final locale in <String>['ar', 'en']) {
        final label = customerAccountDeletionStatusMessage(status, locale);
        expect(label, isNotEmpty);
        expect(label, isNot(contains(status)));
        expect(label, isNot(contains('_')));
      }
    }

    expect(
      customerAccountDeletionStatusMessage('PENDING_RETENTION', 'ar'),
      contains('قيد المراجعة'),
    );
    expect(
      customerAccountDeletionStatusMessage('PENDING_RETENTION', 'en'),
      contains('under review'),
    );
    expect(
      customerAccountDeletionStatusMessage('OPERATIONAL_REVIEW_REQUIRED', 'ar'),
      contains('مراجعة إدارية'),
    );
    expect(
      customerAccountDeletionStatusMessage('COMPLETED', 'en'),
      contains('complete'),
    );
  });

  test('unknown and malformed status never falsely confirms deletion', () {
    for (final rawStatus in <String?>['UNKNOWN_STATUS', '', null]) {
      for (final locale in <String>['ar', 'en']) {
        final copy = customerAccountDeletionStatusMessage(rawStatus, locale);
        expect(copy, isNot(contains('UNKNOWN_STATUS')));
        expect(copy, isNot(contains('complete.')));
        expect(copy, isNot(contains('اكتمل طلب')));
      }
    }
  });

  test('credential and network failure copy is localized without raw codes', () {
    expect(
      customerAccountDeletionErrorMessage('ar', passwordRejected: true),
      contains('كلمة المرور'),
    );
    expect(
      customerAccountDeletionErrorMessage('en', passwordRejected: true),
      contains('Password'),
    );
    expect(
      customerAccountDeletionErrorMessage('ar', passwordRejected: false),
      contains('تعذّر'),
    );
    expect(
      customerAccountDeletionErrorMessage('en', passwordRejected: false),
      contains('Unable'),
    );
  });

  testWidgets('customer deletion action shows localized authoritative status',
      (tester) async {
    final api = _DeletionStatusFakeApi('PENDING_RETENTION');

    await tester.pumpWidget(MaterialApp(
      locale: const Locale('en'),
      home: CustomerAccountScreen(
        api: api,
        favoritesApi: api,
        retailStoreId: 19,
      ),
    ));
    await tester.pumpAndSettle();

    final button = find.byKey(const ValueKey('customer-account-delete-account'));
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('customer-account-deletion-password')),
      'correct-password',
    );
    await tester.tap(
      find.byKey(const ValueKey('customer-account-deletion-confirm')),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));

    expect(api.deletionRequests, 1);
    expect(api.lastPassword, 'correct-password');
    expect(find.textContaining('under review'), findsOneWidget);
    expect(find.textContaining('PENDING_RETENTION'), findsNothing);
  });
}

class _DeletionStatusFakeApi extends HttpB2cAccountApi {
  _DeletionStatusFakeApi(this.status)
      : super(
          baseUrl: 'https://example.test',
          token: 'test-token',
          guestSession: CustomerGuestSession(),
        );

  final String status;
  int deletionRequests = 0;
  String? lastPassword;

  @override
  Future<Object?> profile() async => const {
        'name': 'Customer',
        'email': 'customer@example.test',
      };

  @override
  Future<Object?> addresses() async => const {'data': []};

  @override
  Future<Object?> notifications({String locale = 'ar'}) async =>
      const {'data': []};

  @override
  Future<Object?> favoritesForStore(int storeId) async =>
      const {'data': []};

  @override
  Future<Object?> requestAccountDeletion(String password) async {
    deletionRequests += 1;
    lastPassword = password;
    return {
      'data': {'status': status},
    };
  }
}
