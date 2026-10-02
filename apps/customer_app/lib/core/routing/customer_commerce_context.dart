enum CustomerCommerceChannel { retail, wholesale }

enum CustomerCommerceSource {
  marketplace,
  retailBanner,
  wholesaleEntry,
  search,
  deepLink,
  preview,
}

extension CustomerCommerceSourceWireName on CustomerCommerceSource {
  String get wireName => switch (this) {
        CustomerCommerceSource.marketplace => 'marketplace',
        CustomerCommerceSource.retailBanner => 'retail_banner',
        CustomerCommerceSource.wholesaleEntry => 'wholesale_entry',
        CustomerCommerceSource.search => 'search',
        CustomerCommerceSource.deepLink => 'deep_link',
        CustomerCommerceSource.preview => 'preview',
      };
}

class CustomerCommerceContext {
  const CustomerCommerceContext({
    required this.channel,
    required this.storeId,
    this.retailReceiverId,
    this.source,
    this.entryPlacementId,
  })  : assert(storeId > 0),
        assert(retailReceiverId == null || retailReceiverId > 0),
        assert(entryPlacementId == null || entryPlacementId > 0),
        assert(entryPlacementId == null || source != null);

  final CustomerCommerceChannel channel;

  /// Exact owning commerce store. This is routing input only; backend APIs remain
  /// authoritative for authorization, tenant ownership and product/store scope.
  final int storeId;

  final int? retailReceiverId;

  /// Provenance of the current commerce entry. It never changes identity or
  /// authorization and exists only so auth/deep-link handoffs can resume the
  /// exact user journey.
  final CustomerCommerceSource? source;

  /// Optional Dashboard placement/banner that opened this commerce context.
  final int? entryPlacementId;

  bool get isRetail => channel == CustomerCommerceChannel.retail;
  bool get isWholesale => channel == CustomerCommerceChannel.wholesale;

  Map<String, String> toQueryParameters() => <String, String>{
        'channel': channel.name,
        'store_id': storeId.toString(),
        if (retailReceiverId != null)
          'retail_receiver_id': retailReceiverId.toString(),
        if (source != null) 'source': source!.wireName,
        if (entryPlacementId != null)
          'placement_id': entryPlacementId.toString(),
      };

  Map<String, Object?> toJson() => <String, Object?>{
        'channel': channel.name,
        'store_id': storeId,
        'retail_receiver_id': retailReceiverId,
        'source': source?.wireName,
        'placement_id': entryPlacementId,
      };

  /// Commerce ownership boundary. Entry provenance is intentionally excluded:
  /// a banner/search/deep-link may all point at the same exact store scope.
  bool sameScope(CustomerCommerceContext other) =>
      channel == other.channel &&
      storeId == other.storeId &&
      retailReceiverId == other.retailReceiverId;

  bool sameEntry(CustomerCommerceContext other) =>
      sameScope(other) &&
      source == other.source &&
      entryPlacementId == other.entryPlacementId;

  CustomerCommerceContext copyWith({
    CustomerCommerceChannel? channel,
    int? storeId,
    int? retailReceiverId,
    bool clearRetailReceiver = false,
    CustomerCommerceSource? source,
    bool clearSource = false,
    int? entryPlacementId,
    bool clearEntryPlacement = false,
  }) {
    final resolvedSource = clearSource ? null : source ?? this.source;
    final resolvedPlacement = clearSource || clearEntryPlacement
        ? null
        : entryPlacementId ?? this.entryPlacementId;

    return CustomerCommerceContext(
      channel: channel ?? this.channel,
      storeId: storeId ?? this.storeId,
      retailReceiverId:
          clearRetailReceiver ? null : retailReceiverId ?? this.retailReceiverId,
      source: resolvedSource,
      entryPlacementId: resolvedPlacement,
    );
  }

  static CustomerCommerceContext? tryFromJson(Map<String, dynamic> payload) {
    final channel = _parseChannel(payload['channel']?.toString());
    final storeId = _positiveInt(payload['store_id']?.toString());
    final receiverRaw = payload['retail_receiver_id'];
    final receiverId =
        receiverRaw == null ? null : _positiveInt(receiverRaw.toString());
    final sourceRaw = payload['source'];
    final source =
        sourceRaw == null ? null : _parseSource(sourceRaw.toString());
    final placementRaw = payload['placement_id'];
    final placementId =
        placementRaw == null ? null : _positiveInt(placementRaw.toString());

    if (channel == null ||
        storeId == null ||
        (receiverRaw != null && receiverId == null) ||
        (sourceRaw != null && source == null) ||
        (placementRaw != null && placementId == null) ||
        (placementId != null && source == null)) {
      return null;
    }

    return CustomerCommerceContext(
      channel: channel,
      storeId: storeId,
      retailReceiverId: receiverId,
      source: source,
      entryPlacementId: placementId,
    );
  }

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
    final queryChannel = _parseChannel(channelValue);

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

    final sourceRaw = uri.queryParameters['source'];
    final source = sourceRaw == null ? null : _parseSource(sourceRaw);
    if (sourceRaw != null && source == null) {
      return null;
    }

    final placementRaw = uri.queryParameters['placement_id'];
    final placementId =
        placementRaw == null ? null : _positiveInt(placementRaw);
    if ((placementRaw != null && placementId == null) ||
        (placementId != null && source == null)) {
      return null;
    }

    return CustomerCommerceContext(
      channel: channel,
      storeId: storeId,
      retailReceiverId: receiverId,
      source: source,
      entryPlacementId: placementId,
    );
  }

  static CustomerCommerceChannel? _parseChannel(String? raw) => switch (raw) {
        'retail' => CustomerCommerceChannel.retail,
        'wholesale' => CustomerCommerceChannel.wholesale,
        null || '' => null,
        _ => null,
      };

  static CustomerCommerceSource? _parseSource(String? raw) => switch (raw) {
        'marketplace' => CustomerCommerceSource.marketplace,
        'retail_banner' => CustomerCommerceSource.retailBanner,
        'wholesale_entry' => CustomerCommerceSource.wholesaleEntry,
        'search' => CustomerCommerceSource.search,
        'deep_link' => CustomerCommerceSource.deepLink,
        'preview' => CustomerCommerceSource.preview,
        _ => null,
      };

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
          retailReceiverId == other.retailReceiverId &&
          source == other.source &&
          entryPlacementId == other.entryPlacementId;

  @override
  int get hashCode =>
      Object.hash(channel, storeId, retailReceiverId, source, entryPlacementId);

  @override
  String toString() =>
      'CustomerCommerceContext(channel: ${channel.name}, storeId: $storeId, '
      'retailReceiverId: $retailReceiverId, source: ${source?.wireName}, '
      'entryPlacementId: $entryPlacementId)';
}
