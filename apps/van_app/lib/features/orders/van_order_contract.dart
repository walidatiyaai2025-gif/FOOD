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
    this.vanExecutionStatus,
    this.vanFailureReasonCode,
    this.vanLastTransitionAt,
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
  final String? vanExecutionStatus;
  final String? vanFailureReasonCode;
  final String? vanLastTransitionAt;
}

class VanOrderCustomer {
  const VanOrderCustomer({
    required this.name,
    this.phone,
    this.email,
  });

  final String name;
  final String? phone;
  final String? email;
}

class VanOrderDeliveryAddress {
  const VanOrderDeliveryAddress({
    required this.formatted,
    required this.hasCoordinates,
    this.latitude,
    this.longitude,
  });

  final String formatted;
  final bool hasCoordinates;
  final double? latitude;
  final double? longitude;
}

class VanOrderItemRecord {
  const VanOrderItemRecord({
    required this.name,
    required this.sku,
    required this.quantity,
    required this.lineTotal,
  });

  final String name;
  final String sku;
  final double quantity;
  final double lineTotal;
}

class VanOrderInvoiceRecord {
  const VanOrderInvoiceRecord({
    required this.id,
    required this.number,
    required this.status,
    required this.currency,
    required this.total,
    required this.paidAmount,
    required this.outstandingAmount,
  });

  final int id;
  final String number;
  final String status;
  final String currency;
  final double total;
  final double paidAmount;
  final double outstandingAmount;
}

class VanOrderPaymentRecord {
  const VanOrderPaymentRecord({
    required this.provider,
    required this.status,
    required this.amount,
    required this.currency,
  });

  final String provider;
  final String status;
  final double amount;
  final String currency;
}

class VanOrderCollectionRecord {
  const VanOrderCollectionRecord({
    required this.status,
    required this.source,
    required this.amount,
    required this.currency,
  });

  final String status;
  final String source;
  final double amount;
  final String currency;
}

class VanOrderTimelineRecord {
  const VanOrderTimelineRecord({
    required this.stage,
    required this.status,
    required this.source,
    this.occurredAt,
  });

  final String stage;
  final String status;
  final String source;
  final String? occurredAt;
}

class VanOrderDetail {
  const VanOrderDetail({
    required this.summary,
    required this.items,
    required this.payments,
    required this.collections,
    required this.timeline,
    this.paymentMethod,
    this.customer,
    this.deliveryAddress,
    this.invoice,
  });

  final VanOrderRecord summary;
  final String? paymentMethod;
  final VanOrderCustomer? customer;
  final VanOrderDeliveryAddress? deliveryAddress;
  final List<VanOrderItemRecord> items;
  final VanOrderInvoiceRecord? invoice;
  final List<VanOrderPaymentRecord> payments;
  final List<VanOrderCollectionRecord> collections;
  final List<VanOrderTimelineRecord> timeline;
}

class VanFailureReasonOption {
  const VanFailureReasonOption({
    required this.code,
    required this.labelAr,
    required this.labelEn,
  });

  final String code;
  final String labelAr;
  final String labelEn;

  String labelFor(String languageCode) =>
      languageCode.toLowerCase() == 'ar' ? labelAr : labelEn;
}

class VanProofAttachment {
  const VanProofAttachment({
    required this.path,
    required this.fileName,
    required this.byteLength,
    this.mimeType,
  });

  static const maxBytes = 5 * 1024 * 1024;

  final String path;
  final String fileName;
  final int byteLength;
  final String? mimeType;

  bool get isWithinSizeLimit => byteLength > 0 && byteLength <= maxBytes;
}

class VanOrderProofRecord {
  const VanOrderProofRecord({
    required this.id,
    required this.type,
    required this.available,
    this.capturedAt,
  });

  final int id;
  final String type;
  final bool available;
  final String? capturedAt;
}

class VanOrderExecutionState {
  const VanOrderExecutionState({
    required this.orderId,
    required this.orderStatus,
    required this.status,
    required this.allowedActions,
    required this.proofRequiredForDelivered,
    required this.deliveryProofReady,
    this.failureReasonCode,
    this.failureNote,
    this.latestProof,
    this.lastTransitionAt,
  });

  final int orderId;
  final String orderStatus;
  final String status;
  final List<String> allowedActions;
  final bool proofRequiredForDelivered;
  final bool deliveryProofReady;
  final String? failureReasonCode;
  final String? failureNote;
  final VanOrderProofRecord? latestProof;
  final String? lastTransitionAt;
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

  Future<VanOrderDetail> order(int orderId);

  Future<VanOrderExecutionState> execution(int orderId);

  Future<VanOrderExecutionState> transitionOrder({
    required int orderId,
    required String status,
    required String idempotencyKey,
  });

  Future<List<VanFailureReasonOption>> failedDeliveryReasons();

  Future<VanOrderExecutionState> uploadProof({
    required int orderId,
    required VanProofAttachment proof,
    required String idempotencyKey,
    String? note,
  });

  Future<VanOrderExecutionState> failOrder({
    required int orderId,
    required String failureReason,
    required String idempotencyKey,
    String? note,
    VanProofAttachment? proof,
  });

  Future<VanOrderExecutionState> retryOrder({
    required int orderId,
    required String idempotencyKey,
    String? note,
  });
}

class VanOrderDraftSnapshot {
  const VanOrderDraftSnapshot({
    required this.revision,
    required this.customer,
    required this.lines,
    required this.paymentMethod,
    required this.addressId,
    required this.warehouseId,
    required this.customerNote,
  });

  final int revision;
  final VanCustomerScope customer;
  final List<VanOrderLine> lines;
  final String paymentMethod;
  final int? addressId;
  final int? warehouseId;
  final String? customerNote;
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
  bool _addressSelectionInitialized = false;
  bool _warehouseSelectionInitialized = false;
  bool _paymentSelectionInitialized = false;
  int _revision = 0;
  int? _quoteRevision;

  List<VanOrderLine> get lines => _lines.values.toList(growable: false);
  int get revision => _revision;
  int? get quoteRevision => _quoteRevision;
  bool get hasCurrentQuote =>
      quote != null && _quoteRevision != null && _quoteRevision == _revision;

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
    _addressSelectionInitialized = false;
    _warehouseSelectionInitialized = false;
    _paymentSelectionInitialized = false;
    _quoteRevision = null;
    _revision += 1;
    notifyListeners();
  }

  void add(VanCatalogProduct product) {
    final existing = _lines[product.id];
    final quantity = existing == null
        ? product.minimumQuantity
        : existing.quantity + product.orderingIncrement;
    _lines[product.id] = VanOrderLine(product: product, quantity: quantity);
    _invalidateQuote();
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
    _invalidateQuote();
  }

  void applyOptions(VanOrderOptions value) {
    final previousOptions = options;
    final previousAddress = addressId;
    final previousWarehouse = warehouseId;
    final previousPayment = paymentMethod;
    options = value;

    if (!_addressSelectionInitialized) {
      for (final item in value.addresses) {
        if (item.isDefault) {
          addressId = item.id;
          break;
        }
      }
      addressId ??= value.addresses.isEmpty ? null : value.addresses.first.id;
      _addressSelectionInitialized = true;
    } else if (addressId != null &&
        !value.addresses.any((item) => item.id == addressId)) {
      addressId = null;
    }

    if (!_warehouseSelectionInitialized) {
      warehouseId = value.warehouses.isEmpty ? null : value.warehouses.first.id;
      _warehouseSelectionInitialized = true;
    } else if (warehouseId != null &&
        !value.warehouses.any((item) => item.id == warehouseId)) {
      warehouseId = null;
    }

    if (!_paymentSelectionInitialized) {
      paymentMethod = value.paymentMethods.contains(value.defaultPaymentMethod)
          ? value.defaultPaymentMethod
          : (value.paymentMethods.isEmpty ? null : value.paymentMethods.first);
      _paymentSelectionInitialized = true;
    } else if (paymentMethod != null &&
        !value.paymentMethods.contains(paymentMethod)) {
      paymentMethod = null;
    }

    final changed = !identical(previousOptions, value) ||
        previousAddress != addressId ||
        previousWarehouse != warehouseId ||
        previousPayment != paymentMethod;
    if (changed) {
      _invalidateQuote();
    } else {
      notifyListeners();
    }
  }

  void setSelection({
    int? address,
    int? warehouse,
    String? payment,
    String? note,
  }) {
    var changed = false;
    if (address != null) {
      changed = changed ||
          !_addressSelectionInitialized ||
          addressId != address;
      addressId = address;
      _addressSelectionInitialized = true;
    }
    if (warehouse != null) {
      changed = changed ||
          !_warehouseSelectionInitialized ||
          warehouseId != warehouse;
      warehouseId = warehouse;
      _warehouseSelectionInitialized = true;
    }
    if (payment != null) {
      changed = changed ||
          !_paymentSelectionInitialized ||
          paymentMethod != payment;
      paymentMethod = payment;
      _paymentSelectionInitialized = true;
    }
    if (note != null && customerNote != note) {
      customerNote = note;
      changed = true;
    }
    if (changed) {
      _invalidateQuote();
    }
  }

  void clearAddressSelection() {
    final changed = addressId != null || !_addressSelectionInitialized;
    addressId = null;
    _addressSelectionInitialized = true;
    if (changed) _invalidateQuote();
  }

  void clearWarehouseSelection() {
    final changed = warehouseId != null || !_warehouseSelectionInitialized;
    warehouseId = null;
    _warehouseSelectionInitialized = true;
    if (changed) _invalidateQuote();
  }

  void clearPaymentSelection() {
    final changed = paymentMethod != null || !_paymentSelectionInitialized;
    paymentMethod = null;
    _paymentSelectionInitialized = true;
    if (changed) _invalidateQuote();
  }

  VanOrderDraftSnapshot? createQuoteSnapshot({String? note}) {
    if (note != null && customerNote != note) {
      customerNote = note;
    }

    final currentCustomer = customer;
    final currentPayment = paymentMethod;
    final currentLines = lines;
    if (currentCustomer == null ||
        currentPayment == null ||
        currentLines.isEmpty) {
      _invalidateQuote();
      return null;
    }

    _revision += 1;
    quote = null;
    _quoteRevision = null;
    final snapshot = VanOrderDraftSnapshot(
      revision: _revision,
      customer: currentCustomer,
      lines: List<VanOrderLine>.unmodifiable(currentLines),
      paymentMethod: currentPayment,
      addressId: addressId,
      warehouseId: warehouseId,
      customerNote: customerNote,
    );
    notifyListeners();
    return snapshot;
  }

  bool setQuoteForRevision(int revision, VanOrderQuote value) {
    if (revision != _revision) return false;
    quote = value;
    _quoteRevision = revision;
    notifyListeners();
    return true;
  }

  bool ownsCurrentQuote(int revision) =>
      revision == _revision && _quoteRevision == revision && quote != null;

  void setQuote(VanOrderQuote value) {
    quote = value;
    _quoteRevision = _revision;
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
    _addressSelectionInitialized = false;
    _warehouseSelectionInitialized = false;
    _paymentSelectionInitialized = false;
    _quoteRevision = null;
    _revision += 1;
    notifyListeners();
  }

  void _invalidateQuote() {
    _revision += 1;
    quote = null;
    _quoteRevision = null;
    notifyListeners();
  }
}
