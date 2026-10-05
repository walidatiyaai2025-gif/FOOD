import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/app.dart';

void main() {
  testWidgets('Van scaffold exposes tabs-first foundation', (tester) async {
    await tester.pumpWidget(const FoodexVanApp());

    expect(find.text('FOODEX Van'), findsOneWidget);
    expect(find.text('Overview'), findsOneWidget);
    expect(find.text('Routes'), findsOneWidget);
    expect(find.text('Visits'), findsOneWidget);
    expect(find.text('Wallet'), findsOneWidget);
  });
}
