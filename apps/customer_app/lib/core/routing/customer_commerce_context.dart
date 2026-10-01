import '../auth/customer_session.dart';

class CustomerCommerceContext {
  const CustomerCommerceContext.retail({
    required this.storeId,
  })  : assert(storeId > 0),
        channel = CustomerChannel.b2c,
        retailReceiverId = null;

  const CustomerCommerceContext.wholesale({
    required this.storeId,
    this.retailReceiverId,
  })  : assert(storeId > 0),
        assert(retailReceiverId == null || retailReceiverId > 0),
        channel = CustomerChannel.b2b;

  final CustomerChannel channel;
  final int storeId;
  final int? retailReceiverId;

  bool get isRetail => channel == CustomerChannel.b2c;
  bool get isWholesale => channel == CustomerChannel.b2b;

  String get scopeKey => [
        channel.name,
        storeId,
        retailReceiverId ?? '-',
      ].join(':');

  Map<String, String> toQueryParameters() => {
        'channel': channel.name,
        'store': storeId.toString(),
        if (retailReceiverId != null)
          'receiver': retailReceiverId.toString(),
      };

  bool hasSameScope(CustomerCommerceContext other) =>
      channel == other.channel &&
      storeId == other.storeId &&
      retailReceiverId == other.retailReceiverId;

  static CustomerCommerceContext? tryParseQuery(
    Map<String, String> queryParameters,
  ) {
    final channelName = queryParameters['channel'];
    final storeId = int.tryParse(queryParameters['store'] ?? '');
    final receiverId = int.tryParse(queryParameters['receiver'] ?? '');

    if (storeId == null || storeId <= 0) {
      return null;
    }

    return switch (channelName) {
      'b2c' => CustomerCommerceContext.retail(storeId: storeId),
      'b2b' => CustomerCommerceContext.wholesale(
          storeId: storeId,
          retailReceiverId:
              receiverId != null && receiverId > 0 ? receiverId : null,
        ),
      _ => null,
    };
  }

  @override
  bool operator ==(Object other) =>
      other is CustomerCommerceContext && hasSameScope(other);

  @override
  int get hashCode => Object.hash(channel, storeId, retailReceiverId);

  @override
  String toString() => 'CustomerCommerceContext($scopeKey)';
}
