import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/features/storefront/storefront_design_system.dart';

void main() {
  test('checkout action exceptions never show Dart object identity', () {
    const error = CustomerActionException('http_422');
    expect(error.toString(), 'http_422');
    final text = operationalErrorMessage(error, isArabic: true);
    expect(text, contains('تعذر تنفيذ العملية'));
    expect(text, contains('http_422'));
    expect(text, isNot(contains('Instance of')));
  });

  test('checkout displays backend field-validation detail', () {
    const error = CustomerActionException(
      'validation_failed',
      fieldErrors: {
        'quantity': ['Quantity does not meet the minimum order size.'],
      },
    );
    expect(
      operationalErrorMessage(error, isArabic: false),
      'Quantity does not meet the minimum order size.',
    );
  });

  test('expired session asks for reauthentication', () {
    const error = CustomerActionException('authentication_required');
    expect(
      operationalErrorMessage(error, isArabic: true),
      contains('سجل الدخول مجددًا'),
    );
  });

  test('commercial conflict points to stock and price refresh', () {
    const error = CustomerActionException('http_409');
    expect(
      operationalErrorMessage(error, isArabic: true),
      contains('المخزون'),
    );
  });

  test('unknown errors never expose raw Dart instance labels', () {
    final text = operationalErrorMessage(
      Object(),
      isArabic: false,
    );
    expect(text, isNot(contains('Instance of')));
  });
}
