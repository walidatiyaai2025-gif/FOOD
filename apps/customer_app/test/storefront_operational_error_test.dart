import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/api/wholesale_commerce_api.dart';
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

  test('cart HTTP 500 reports server fault instead of misleading pricing text', () {
    const error = WholesaleCommerceException(
      'Server Error',
      statusCode: 500,
    );
    final message = operationalErrorMessage(error, isArabic: true);
    expect(message, contains('HTTP 500'));
    expect(message, isNot(contains('Server Error')));
  });

  test('cart access denial explains account or store eligibility', () {
    const error = WholesaleCommerceException(
      'Forbidden',
      statusCode: 403,
    );
    final message = operationalErrorMessage(error, isArabic: true);
    expect(message, contains('حساب الجملة'));
  });

  test('wholesale quick-add HTTP 500 reports unavailable service', () {
    const error = CustomerActionException('http_500', statusCode: 500);
    expect(
      operationalErrorMessage(error, isArabic: true),
      contains('HTTP 500'),
    );
  });

  test('selling-unit conflict points to the existing cart row', () {
    const error = CustomerActionException(
      'validation_failed',
      statusCode: 422,
      fieldErrors: {
        'selling_unit_code': [
          'Remove the existing product line before changing its selling unit.'
        ],
      },
    );
    expect(
      operationalErrorMessage(error, isArabic: true),
      contains('وحدة بيع مختلفة'),
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
