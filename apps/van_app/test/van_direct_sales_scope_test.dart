import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/features/orders/van_direct_sales_scope.dart';
import 'package:foodex_van_app/features/orders/van_order_contract.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';

void main() {
  const wholesale = VanCustomerScope(
    type: 'b2b',
    id: 10,
    name: 'Wholesale customer',
    storeId: 2,
  );
  const retail = VanCustomerScope(
    type: 'b2c',
    id: 20,
    name: 'Retail customer',
    storeId: 3,
  );

  test('Van sales catalog projects assigned Wholesale customers only', () {
    final draft = VanOrderDraftController();
    final customers = syncWholesaleDirectSaleDraft(draft, [
      retail,
      wholesale,
    ]);

    expect(customers, [wholesale]);
    expect(draft.customer, same(wholesale));
  });

  test('legacy Retail draft is cleared before a Wholesale sale can start', () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(retail);
    draft.add(
      const VanCatalogProduct(
        id: 1,
        name: 'Retail product',
        sku: 'RETAIL',
        unitPrice: 5,
        minimumQuantity: 1,
        orderingIncrement: 1,
        isAvailable: true,
      ),
    );

    final customers = syncWholesaleDirectSaleDraft(draft, [
      retail,
      wholesale,
    ]);

    expect(customers, [wholesale]);
    expect(draft.customer, same(wholesale));
    expect(draft.lines, isEmpty);
    expect(draft.quote, isNull);
  });

  test('withdrawn Wholesale assignment clears old draft and customer', () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(wholesale);

    expect(syncWholesaleDirectSaleDraft(draft, [retail]), isEmpty);
    expect(draft.customer, isNull);
    expect(draft.lines, isEmpty);
  });

  test('still authorized Wholesale selection survives customer refresh', () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(wholesale);
    final revision = draft.revision;

    expect(syncWholesaleDirectSaleDraft(draft, [retail, wholesale]), [
      wholesale,
    ]);
    expect(draft.customer, same(wholesale));
    expect(draft.revision, revision);
  });
}
