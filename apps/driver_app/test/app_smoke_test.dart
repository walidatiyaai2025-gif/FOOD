import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';

void main() {
  testWidgets('application root boots', (tester) async {
    await tester.pumpWidget(const FoodexDriverApp());
    expect(find.text('FOODEX Driver'), findsOneWidget);
    expect(find.text('Bootstrap foundation'), findsOneWidget);
  });
}
