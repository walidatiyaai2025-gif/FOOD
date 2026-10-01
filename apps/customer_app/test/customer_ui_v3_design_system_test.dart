import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('Customer UI V3 exposes the approved central visual tokens', () {
    expect(CustomerUiColors.deepGreen, const Color(0xFF00452F));
    expect(CustomerUiColors.lime, const Color(0xFF9BE252));
    expect(CustomerUiColors.mint, const Color(0xFFEDF7F1));
    expect(CustomerUiColors.mintStrong, const Color(0xFFC5E0CC));
    expect(CustomerUiRadii.pill, greaterThan(CustomerUiRadii.xl));
    expect(CustomerUiMotion.standard, const Duration(milliseconds: 180));
    expect(CustomerAssets.productImagesAreBackendDriven, isTrue);
    expect(CustomerAssets.storeImagesAreBackendDriven, isTrue);
    expect(CustomerAssets.proprietaryReferenceAssetsAllowed, isFalse);
  });

  testWidgets('reduced-motion media settings collapse V3 motion durations',
      (tester) async {
    late Duration resolved;

    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(disableAnimations: true),
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: Builder(
            builder: (context) {
              resolved = CustomerUiMotion.resolve(
                context,
                CustomerUiMotion.emphasis,
              );
              return const SizedBox();
            },
          ),
        ),
      ),
    );

    expect(resolved, Duration.zero);
  });

  testWidgets('shared primitives remain usable in RTL with larger text',
      (tester) async {
    var favoritePressed = false;
    var addPressed = false;

    await tester.pumpWidget(
      MaterialApp(
        theme: FoodexTheme.light(),
        home: MediaQuery(
          data: const MediaQueryData(
            size: Size(320, 700),
            textScaler: TextScaler.linear(1.35),
          ),
          child: Directionality(
            textDirection: TextDirection.rtl,
            child: Scaffold(
              body: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  const CustomerSearchPill(
                    hintText: 'ابحث عن المنتجات',
                  ),
                  const SizedBox(height: 12),
                  const CustomerCategoryTile(
                    label: 'الخضروات والفواكه',
                  ),
                  const SizedBox(height: 12),
                  CustomerProductCard(
                    title: 'منتج تجريبي طويل لاختبار تكبير الخط',
                    priceLabel: '1.250 د.ك',
                    oldPriceLabel: '1.500 د.ك',
                    categoryLabel: 'بقالة',
                    discountLabel: '-15%',
                    onFavorite: () => favoritePressed = true,
                    onAdd: () => addPressed = true,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );

    expect(find.text('ابحث عن المنتجات'), findsOneWidget);
    expect(find.text('الخضروات والفواكه'), findsOneWidget);
    expect(tester.takeException(), isNull);

    final favorite = find.byIcon(Icons.favorite_border_rounded);
    await tester.ensureVisible(favorite);
    await tester.pumpAndSettle();
    await tester.tap(favorite);
    await tester.pump();

    final add = find.byIcon(Icons.add_rounded);
    await tester.ensureVisible(add);
    await tester.pumpAndSettle();
    await tester.tap(add);
    await tester.pump();

    expect(favoritePressed, isTrue);
    expect(addPressed, isTrue);
    expect(tester.takeException(), isNull);
  });

  testWidgets('curved surface and state primitive compose without business data',
      (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: FoodexTheme.light(),
        home: Scaffold(
          body: CustomerCurvedHeaderSurface(
            header: const Text('Header'),
            child: const CustomerStateView(
              kind: CustomerStateKind.empty,
              title: 'No items',
              message: 'Try again later',
            ),
          ),
        ),
      ),
    );

    expect(find.text('Header'), findsOneWidget);
    expect(find.text('No items'), findsOneWidget);
    expect(find.byIcon(Icons.inbox_outlined), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
