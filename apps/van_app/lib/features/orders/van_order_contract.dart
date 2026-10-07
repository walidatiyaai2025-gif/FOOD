import 'package:flutter/foundation.dart';

import '../wallet/van_wallet_contract.dart';

class VanCatalogProduct {
  const VanCatalogProduct({
    required this.id,
    required this.name,
    required this.sku,
    required this.unitPrice,
    required this.minimumQuantity,
    required this.orderingIncrement,
    required this.isAvailable,
    this.barcode,
    this.availableQuantity,
    this.currency = 'KWD',
  });

  final int id;
  final String name;
  final String sku;
  final String? barcode;
  final double unitPrice;
  final double minimumQuantity;
  final double orderingIncrement;
  final double? availableQuantity;
  final bool isAvailable;
  final String currency;
}

class VanOrderAddressOption {
  const VanOrderAddressOption({
    required this.id,
    required this.label,
    required this.address,
    required this.isDefault,
  });

  final int id;
  final String label;
  final String address;
  final bool isDefault;
}

class VanWarehouseOption {
  const VanWarehouseOption({
    required this.id,
    required this.code,
    required this.name,
  });

  final int id;
  final String code;
  final String name;
}

class VanOrderOptions {
  const VanOrderOptions({
    required this.addresses,
    required this.warehouses,
    required this.paymentMethods,
    required this.defaultPaymentMethod,
  });

  final List<VanOrderAddressOption> addresses;
  final List<VanWarehouseOption> warehouses;
  final List<String> paymentMethods;
  final String defaultPaymentMethod;
}

class VanOrderLine {
  const VanOrderLine({required this.product, required this.quantity});

  final VanCatalogProduct product;
  final double quantity;
}

class VanOrderQuote {
  const VanOrderQuote({
    required this.currency,
    required this.subtotal,
    required this.discountTotal,
    required this.deliveryTotal,
    required this.taxTotal,
    required this.grandTotal,
    required this.hasUnavailableItems,
  });

  final String currency;
  final double subtotal;
  final double discountTotal;
  final double deliveryTotal;
  final double taxTotal;
  final double grandTotal;
  final bool hasUnavailableItems;
}

class VanOrderRecord {
  const VanOrderRecord({
    required this.id,
    required this.orderNumber,
    required this.customerType,
    required this.customerId,
    required this.storeId,
    required this.status,
    required this.currency,
    required this.grandTotal,
    this.createdAt,
  });

  final int id;
  final String orderNumber;
  final String customerType;
  final int customerId;
  final int storeId;
  final String status;
  final String currency;
  final double grandTotal;
  final String? createdAt;
}

abstract interface class VanOrderRepository {
  Future<List<VanCatalogProduct>> catalog(
    VanCustomerScope customer, {
    String search = '',
  });

  Future<VanOrderOptions> options(VanCustomerScope customer);

  Future<VanOrderQuote> quote({
    required VanCustomerScope customer,
    required List<VanOrderLine> lines,
    required String paymentMethod,
    int? addressId,
    int? warehouseId,
    String? customerNote,
  });

  Future<VanOrderRecord> createOrder({
    required VanCustomerScope customer,
    required List<VanOrderLine> lines,
    required String paymentMethod,
    required String idempotencyKey,
    int? addressId,
    int? warehouseId,
    String? customerNote,
  });

  Future<List<VanOrderRecord>> orders({
    VanCustomerScope? customer,
    String? status,
  });
}

class VanOrderDraftController extends ChangeNotifier {
  VanCustomerScope? customer;
  VanOrderOptions? options;
  VanOrderQuote? quote;
  int? addressId;
  int? warehouseId;
  String? paymentMethod;
  String? customerNote;
  final Map<int, VanOrderLine> _lines = {};

  List<VanOrderLine> get lines => _lines.values.toList(growable: false);

  void selectCustomer(VanCustomerScope value) {
    if (customer?.type == value.type && customer?.id == value.id) return;
    customer = value;
    _lines.clear();
    options = null;
    quote = null;
    addressId = null;
    warehouseId = null;
    paymentMethod = null;
    customerNote = null;
    notifyListeners();
  }

  void add(VanCatalogProduct product) {
    final existing = _lines[product.id];
    final quantity = existing == null
        ? product.minimumQuantity
        : existing.quantity + product.orderingIncrement;
    _lines[product.id] = VanOrderLine(product: product, quantity: quantity);
    quote = null;
    notifyListeners();
  }

  void changeQuantity(VanCatalogProduct product, double quantity) {
    if (quantity <= 0) {
      _lines.remove(product.id);
    } else {
      _lines[product.id] = VanOrderLine(
        product: product,
        quantity: quantity < product.minimumQuantity
            ? product.minimumQuantity
            : quantity,
      );
    }
    quote = null;
    notifyListeners();
  }

  void applyOptions(VanOrderOptions value) {
    options = value;
    if (addressId == null) {
      for (final item in value.addresses) {
        if (item.isDefault) {
          addressId = item.id;
          break;
        }
      }
    }
    addressId ??= value.addresses.isEmpty ? null : value.addresses.first.id;
    warehouseId ??=
        value.warehouses.isEmpty ? null : value.warehouses.first.id;
    paymentMethod ??= value.paymentMethods.contains(value.defaultPaymentMethod)
        ? value.defaultPaymentMethod
        : (value.paymentMethods.isEmpty ? null : value.paymentMethods.first);
    notifyListeners();
  }

  void setSelection({
    int? address,
    int? warehouse,
    String? payment,
    String? note,
  }) {
    addressId = address;
    warehouseId = warehouse;
    paymentMethod = payment ?? paymentMethod;
    customerNote = note ?? customerNote;
    quote = null;
    notifyListeners();
  }

  void setQuote(VanOrderQuote value) {
    quote = value;
    notifyListeners();
  }

  void reset() {
    customer = null;
    _lines.clear();
    options = null;
    quote = null;
    addressId = null;
    warehouseId = null;
    paymentMethod = null;
    customerNote = null;
    notifyListeners();
  }
}
