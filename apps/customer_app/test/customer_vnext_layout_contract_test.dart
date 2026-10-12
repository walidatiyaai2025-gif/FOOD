import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_layout.dart';

void main() {
  testWidgets('Customer VNext product grid is always three columns on phones',
      (tester) async {
    for (final width in <double>[320, 360, 390, 412, 430]) {
      tester.view.physicalSize = Size(width, 760);
      tester.view.devicePixelRatio = 1;

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CustomerProductGrid(
              itemCount: 6,
              itemBuilder: (_, index) => SizedBox(
                key: ValueKey('product-$index'),
                height: 120,
              ),
            ),
          ),
        ),
      );

      final grid = tester.widget<GridView>(find.byType(GridView));
      final delegate =
          grid.gridDelegate as SliverGridDelegateWithFixedCrossAxisCount;
      expect(delegate.crossAxisCount, 3);
      expect(tester.takeException(), isNull);
    }

    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
  });
}
