import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/features/orders/van_order_contract.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';

void main() {
  test('Van order draft preserves sibling selections across field updates', () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(_customer);
    draft.applyOptions(_options);

    expect(draft.addressId, 10);
    expect(draft.warehouseId, 20);
    expect(draft.paymentMethod, 'cash');

    draft.setSelection(warehouse: 21);
    expect(draft.addressId, 10);
    expect(draft.warehouseId, 21);
    expect(draft.paymentMethod, 'cash');

    draft.setSelection(address: 11);
    expect(draft.addressId, 11);
    expect(draft.warehouseId, 21);
    expect(draft.paymentMethod, 'cash');

    draft.setSelection(payment: 'card');
    expect(draft.addressId, 11);
    expect(draft.warehouseId, 21);
    expect(draft.paymentMethod, 'card');

    draft.setSelection(note: 'Call before delivery');
    expect(draft.addressId, 11);
    expect(draft.warehouseId, 21);
    expect(draft.paymentMethod, 'card');
    expect(draft.customerNote, 'Call before delivery');

    draft.applyOptions(_options);
    expect(draft.addressId, 11);
    expect(draft.warehouseId, 21);
    expect(draft.paymentMethod, 'card');
  });

  test('explicit clear stays clear through quote option refresh', () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(_customer);
    draft.applyOptions(_options);
    draft.setSelection(
      address: 11,
      warehouse: 21,
      payment: 'card',
      note: 'Keep note',
    );

    draft.clearAddressSelection();
    draft.clearWarehouseSelection();
    draft.clearPaymentSelection();

    expect(draft.addressId, isNull);
    expect(draft.warehouseId, isNull);
    expect(draft.paymentMethod, isNull);
    expect(draft.customerNote, 'Keep note');

    draft.applyOptions(_options);

    expect(draft.addressId, isNull);
    expect(draft.warehouseId, isNull);
    expect(draft.paymentMethod, isNull);
    expect(draft.customerNote, 'Keep note');
  });

  test(
    'removed selections clear instead of silently falling back to defaults',
    () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(_customer);
    draft.applyOptions(_options);
    draft.setSelection(address: 11, warehouse: 21, payment: 'card');

    draft.applyOptions(
      const VanOrderOptions(
        addresses: [
          VanOrderAddressOption(
            id: 10,
            label: 'Default',
            address: 'Street 1',
            isDefault: true,
          ),
        ],
        warehouses: [
          VanWarehouseOption(id: 20, code: 'WH-A', name: 'Warehouse A'),
        ],
        paymentMethods: ['cash'],
        defaultPaymentMethod: 'cash',
      ),
    );

    expect(draft.addressId, isNull);
    expect(draft.warehouseId, isNull);
    expect(draft.paymentMethod, isNull);
    },
  );

  test('stale quote cannot attach after the draft revision changes', () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(_customer);
    draft.add(_product);
    draft.applyOptions(_options);

    final quotedRevision = draft.revision;
    expect(draft.setQuoteForRevision(_quote, quotedRevision), isTrue);
    expect(draft.hasCurrentQuote, isTrue);

    draft.changeQuantity(_product, 2);

    expect(draft.quote, isNull);
    expect(draft.hasCurrentQuote, isFalse);
    expect(draft.setQuoteForRevision(_quote, quotedRevision), isFalse);
    expect(draft.quote, isNull);
  });

  test('review selection and note edits invalidate the accepted quote', () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(_customer);
    draft.add(_product);
    draft.applyOptions(_options);

    expect(draft.setQuoteForRevision(_quote, draft.revision), isTrue);
    draft.setSelection(payment: 'card');
    expect(draft.hasCurrentQuote, isFalse);

    expect(draft.setQuoteForRevision(_quote, draft.revision), isTrue);
    draft.setSelection(note: 'Updated note');
    expect(draft.hasCurrentQuote, isFalse);
  });
}

const _customer = VanCustomerScope(
  type: 'b2b',
  id: 7,
  name: 'Customer',
  storeId: 3,
);

const _options = VanOrderOptions(
  addresses: [
    VanOrderAddressOption(
      id: 10,
      label: 'Default',
      address: 'Street 1',
      isDefault: true,
    ),
    VanOrderAddressOption(
      id: 11,
      label: 'Branch',
      address: 'Street 2',
      isDefault: false,
    ),
  ],
  warehouses: [
    VanWarehouseOption(id: 20, code: 'WH-A', name: 'Warehouse A'),
    VanWarehouseOption(id: 21, code: 'WH-B', name: 'Warehouse B'),
  ],
  paymentMethods: ['cash', 'card'],
  defaultPaymentMethod: 'cash',
);


const _product = VanCatalogProduct(
  id: 100,
  name: 'Product',
  sku: 'SKU-100',
  unitPrice: 12,
  minimumQuantity: 1,
  orderingIncrement: 1,
  isAvailable: true,
);

const _quote = VanOrderQuote(
  currency: 'KWD',
  subtotal: 12,
  discountTotal: 0,
  deliveryTotal: 0,
  taxTotal: 0,
  grandTotal: 12,
  hasUnavailableItems: false,
);
