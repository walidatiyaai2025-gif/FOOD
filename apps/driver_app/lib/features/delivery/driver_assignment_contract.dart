import '../../core/auth/driver_session.dart';

class DriverOrderItem {
  const DriverOrderItem({
    required this.name,
    required this.quantity,
    required this.lineTotal,
    this.sku = '',
    this.imageUrl = '',
    this.variant = '',
    this.unit = '',
    this.note = '',
    this.unitPrice = 0,
  });

  final String name;
  final String sku;
  final String imageUrl;
  final String variant;
  final String unit;
  final String note;
  final double quantity;
  final double unitPrice;
  final double lineTotal;
}

class DriverInvoice {
  const DriverInvoice({
    required this.id,
    required this.number,
    required this.status,
    required this.currency,
    required this.grandTotal,
    this.revision = 1,
    this.subtotal = 0,
    this.discountTotal = 0,
    this.deliveryTotal = 0,
    this.taxTotal = 0,
    this.paymentMethod = '',
    this.paymentStatus = '',
    this.issuedAt = '',
    this.items = const [],
  });

  final int id;
  final String number;
  final int revision;
  final String status;
  final String currency;
  final double subtotal;
  final double discountTotal;
  final double deliveryTotal;
  final double taxTotal;
  final double grandTotal;
  final String paymentMethod;
  final String paymentStatus;
  final String issuedAt;
  final List<DriverOrderItem> items;
}

class DriverAssignment {
  const DriverAssignment({
    required this.id,
    required this.channel,
    required this.reference,
    required this.status,
    this.orderId = 0,
    this.storeId = 0,
    this.storeName = '',
    this.orderStatus = '',
    this.customerName = '',
    this.customerPhone = '',
    this.address = '',
    this.navigationLatitude,
    this.navigationLongitude,
    this.currency = 'KWD',
    this.grandTotal = 0,
    this.paymentMethod = '',
    this.paymentStatus = '',
    this.customerNote = '',
    this.items = const [],
    this.availableStatuses = const [],
    this.assignedAt = '',
    this.completedAt = '',
    this.createdAt = '',
    this.invoice,
  });

  final int id;
  final int orderId;
  final int storeId;
  final DriverChannel channel;
  final String reference;
  final String status;
  final String storeName;
  final String orderStatus;
  final String customerName;
  final String customerPhone;
  final String address;
  final double? navigationLatitude;
  final double? navigationLongitude;
  final String currency;
  final double grandTotal;
  final String paymentMethod;
  final String paymentStatus;
  final String customerNote;
  final List<DriverOrderItem> items;
  final List<String> availableStatuses;
  final String assignedAt;
  final String completedAt;
  final String createdAt;
  final DriverInvoice? invoice;

  bool get hasNavigation =>
      (navigationLatitude != null && navigationLongitude != null) ||
      address.trim().isNotEmpty;
}

abstract interface class DriverAssignmentRepository {
  Future<List<DriverAssignment>> list(DriverChannel channel);

  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  });
}

abstract interface class DriverProofAssignmentRepository
    implements DriverAssignmentRepository {
  Future<void> transitionWithProof(
    int id,
    DriverChannel channel,
    String status,
    String proofImagePath, {
    String? note,
    String? failureReason,
  });
}
