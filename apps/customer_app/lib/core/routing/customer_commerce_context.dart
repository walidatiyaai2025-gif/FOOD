enum CustomerCommerceChannel { retail, wholesale }

class CustomerCommerceContext {
  const CustomerCommerceContext({
    required this.channel,
    required this.storeId,
    this.retailReceiverId,
  })  : assert(storeId > 0),
        assert(retailReceiverId == null || retailReceiverId > 0);

  final CustomerCommerceChannel channel;
  final int storeId;
  final int? retailReceiverId;

  bool get isRetail => channel == CustomerCommerceChannel.retail;
  bool get isWholesale => channel == CustomerCommerceChannel.wholesale;

  Map<String, String> toQueryParameters() => <String, String>{
        'channel': channel.name,
        'store_id': storeId.toString(),
        if (retailReceiverId != null)
          'retail_receiver_id': retailReceiverId.toString(),
      };

  bool sameScope(CustomerCommerceContext other) =>
      channel == other.channel &&
      storeId == other.storeId &&
      retailReceiverId == other.retailReceiverId;

  CustomerCommerceContext copyWith({
    CustomerCommerceChannel? channel,
    int? storeId,
    int? retailReceiverId,
    bool clearRetailReceiver = false,
  }) =>
      CustomerCommerceContext(
        channel: channel ?? this.channel,
        storeId: storeId ?? this.storeId,
        retailReceiverId:
            clearRetailReceiver ? null : retailReceiverId ?? this.retailReceiverId,
      );

  static CustomerCommerceContext? tryParseLocation(String? location) {
    if (location == null || location.isEmpty || location != location.trim()) {
      return null;
    }

    final uri = Uri.tryParse(location);
    if (uri == null ||
        uri.hasScheme ||
        uri.hasAuthority ||
        !uri.path.startsWith('/') ||
        uri.path.startsWith('//') ||
        uri.path.contains(r'\\')) {
      return null;
    }

    final segments = uri.pathSegments;
    CustomerCommerceChannel? inferredChannel;
    int? inferredStoreId;

    if (segments.length >= 2 && segments.first == 'retail') {
      inferredChannel = CustomerCommerceChannel.retail;
      inferredStoreId = _positiveInt(segments[1]);
    } else if (segments.isNotEmpty && segments.first == 'b2b') {
      inferredChannel = CustomerCommerceChannel.wholesale;
    }

    final channelValue = uri.queryParameters['channel'];
    final queryChannel = switch (channelValue) {
      'retail' => CustomerCommerceChannel.retail,
      'wholesale' => CustomerCommerceChannel.wholesale,
      null || '' => null,
      _ => null,
    };

    if (channelValue != null &&
        channelValue.isNotEmpty &&
        queryChannel == null) {
      return null;
    }
    if (inferredChannel != null &&
        queryChannel != null &&
        inferredChannel != queryChannel) {
      return null;
    }

    final channel = queryChannel ?? inferredChannel;
    if (channel == null) {
      return null;
    }

    final queryStoreId = _positiveInt(
      uri.queryParameters['store_id'] ?? uri.queryParameters['store'],
    );
    if (inferredStoreId != null &&
        queryStoreId != null &&
        inferredStoreId != queryStoreId) {
      return null;
    }

    final storeId = inferredStoreId ?? queryStoreId;
    if (storeId == null) {
      return null;
    }

    final receiverRaw = uri.queryParameters['retail_receiver_id'] ??
        uri.queryParameters['receiver_id'];
    final receiverId =
        receiverRaw == null ? null : _positiveInt(receiverRaw);
    if (receiverRaw != null && receiverId == null) {
      return null;
    }

    return CustomerCommerceContext(
      channel: channel,
      storeId: storeId,
      retailReceiverId: receiverId,
    );
  }

  static int? _positiveInt(String? raw) {
    final value = int.tryParse(raw ?? '');
    return value != null && value > 0 ? value : null;
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CustomerCommerceContext &&
          channel == other.channel &&
          storeId == other.storeId &&
          retailReceiverId == other.retailReceiverId;

  @override
  int get hashCode => Object.hash(channel, storeId, retailReceiverId);

  @override
  String toString() =>
      'CustomerCommerceContext(channel: ${channel.name}, storeId: $storeId, '
      'retailReceiverId: $retailReceiverId)';
}
