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

  test('removed selections clear instead of silently falling back to defaults',
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
  });

  test('stale Van quote cannot attach after a draft quantity change', () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(_customer);
    draft.applyOptions(_options);
    draft.add(_product);

    final first = draft.createQuoteSnapshot();
    expect(first, isNotNull);
    expect(first!.lines.single.quantity, 1);

    draft.changeQuantity(_product, 2);

    expect(draft.quote, isNull);
    expect(draft.hasCurrentQuote, isFalse);
    expect(draft.setQuoteForRevision(first.revision, _quote), isFalse);

    final latest = draft.createQuoteSnapshot();
    expect(latest, isNotNull);
    expect(latest!.revision, greaterThan(first.revision));
    expect(latest.lines.single.quantity, 2);
    expect(draft.setQuoteForRevision(latest.revision, _quote), isTrue);
    expect(draft.hasCurrentQuote, isTrue);
    expect(draft.quoteRevision, latest.revision);
  });

  test('new selection supersedes an in-flight Van quote snapshot', () {
    final draft = VanOrderDraftController();
    draft.selectCustomer(_customer);
    draft.applyOptions(_options);
    draft.add(_product);

    final first = draft.createQuoteSnapshot()!;
    expect(first.warehouseId, 20);
    expect(first.addressId, 10);
    expect(first.paymentMethod, 'cash');

    draft.setSelection(warehouse: 21, address: 11, payment: 'card');
    final latest = draft.createQuoteSnapshot(note: 'Latest note')!;

    expect(latest.revision, greaterThan(first.revision));
    expect(latest.warehouseId, 21);
    expect(latest.addressId, 11);
    expect(latest.paymentMethod, 'card');
    expect(latest.customerNote, 'Latest note');
    expect(draft.setQuoteForRevision(first.revision, _quote), isFalse);
    expect(draft.setQuoteForRevision(latest.revision, _quote), isTrue);
    expect(draft.ownsCurrentQuote(latest.revision), isTrue);
  });
}

const _customer = VanCustomerScope(
  type: 'b2b',
  id: 7,
  name: 'Customer',
  storeId: 3,
);


const _product = VanCatalogProduct(
  id: 99,
  name: 'Product',
  sku: 'SKU-99',
  unitPrice: 10,
  minimumQuantity: 1,
  orderingIncrement: 1,
  isAvailable: true,
  currency: 'EGP',
);

const _quote = VanOrderQuote(
  currency: 'EGP',
  subtotal: 10,
  discountTotal: 0,
  deliveryTotal: 0,
  taxTotal: 0,
  grandTotal: 10,
  hasUnavailableItems: false,
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
