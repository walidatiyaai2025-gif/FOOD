import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';

void main() {
  testWidgets('application root boots', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp());
    expect(find.text('FOODEX Customer'), findsOneWidget);
    expect(find.text('Bootstrap foundation'), findsOneWidget);
  });
}
