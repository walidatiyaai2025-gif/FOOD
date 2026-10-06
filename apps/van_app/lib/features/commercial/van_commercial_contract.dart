import '../wallet/van_wallet_contract.dart';

const vanCommercialReasonCodes = <String>{
  'PRODUCT_INACTIVE',
  'PRODUCT_CLOSED',
  'PRODUCT_RESTRICTED',
  'CHANNEL_BLOCKED',
  'OUTSIDE_AVAILABILITY',
  'MAX_PER_ORDER_EXCEEDED',
  'MAX_PER_DAY_EXCEEDED',
  'MAX_PER_WEEK_EXCEEDED',
  'MAX_PER_MONTH_EXCEEDED',
  'MAX_LIFETIME_EXCEEDED',
  'FLASH_NOT_ACTIVE',
  'FLASH_SOLD_OUT',
  'FLASH_CUSTOMER_LIMIT_REACHED',
  'FLASH_RESERVATION_EXPIRED',
  'ONLINE_VALIDATION_REQUIRED',
  'OVERRIDE_REQUIRED',
};

class VanCommercialOfferProduct {
  const VanCommercialOfferProduct({
    required this.id,
    required this.productId,
    required this.conversionFactor,
    required this.flashPrice,
    this.sellingUnitId,
    this.sellingUnitCode,
    this.allocationBase,
  });

  final int id;
  final int productId;
  final int? sellingUnitId;
  final String? sellingUnitCode;
  final double conversionFactor;
  final double flashPrice;
  final double? allocationBase;
}

class VanCommercialOffer {
  const VanCommercialOffer({
    required this.id,
    required this.storeId,
    required this.titleAr,
    required this.titleEn,
    required this.status,
    required this.startsAt,
    required this.endsAt,
    required this.priority,
    required this.reservationSeconds,
    required this.products,
    this.bodyAr,
    this.bodyEn,
  });

  final int id;
  final int storeId;
  final String titleAr;
  final String titleEn;
  final String? bodyAr;
  final String? bodyEn;
  final String status;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final int priority;
  final int reservationSeconds;
  final List<VanCommercialOfferProduct> products;

  String titleFor(String languageCode) =>
      languageCode == 'ar' && titleAr.trim().isNotEmpty ? titleAr : titleEn;

  String? bodyFor(String languageCode) {
    final preferred = languageCode == 'ar' ? bodyAr : bodyEn;
    final fallback = languageCode == 'ar' ? bodyEn : bodyAr;
    final resolved = preferred?.trim().isNotEmpty == true ? preferred : fallback;
    return resolved?.trim().isEmpty == true ? null : resolved;
  }
}

class VanNormalOffer {
  const VanNormalOffer({
    required this.id,
    required this.name,
    required this.type,
    this.value,
  });

  final int id;
  final String name;
  final String type;
  final double? value;
}

class VanCommercialOfferFeed {
  const VanCommercialOfferFeed({
    required this.serverTime,
    required this.offers,
    required this.normalOffers,
  });

  final DateTime serverTime;
  final List<VanCommercialOffer> offers;
  final List<VanNormalOffer> normalOffers;
}

class VanSellingUnit {
  const VanSellingUnit({
    required this.code,
    required this.name,
    required this.conversionFactor,
    this.price,
    this.sku,
    this.barcode,
    this.isBase = false,
  });

  final String code;
  final String name;
  final double conversionFactor;
  final double? price;
  final String? sku;
  final String? barcode;
  final bool isBase;
}

class VanCommercialQuote {
  const VanCommercialQuote({
    required this.allowed,
    required this.status,
    required this.reasonCodes,
    required this.sellingUnit,
    required this.sellingUnits,
    required this.overrideApplied,
  });

  final bool allowed;
  final String status;
  final List<String> reasonCodes;
  final VanSellingUnit sellingUnit;
  final List<VanSellingUnit> sellingUnits;
  final bool overrideApplied;
}

class VanCommercialPolicyDecision {
  const VanCommercialPolicyDecision({
    required this.allowed,
    required this.reasonCode,
    this.reason,
    this.effectiveMaxBaseUnits,
    this.remainingQuotaBaseUnits,
    this.overrideAllowed = false,
  });

  final bool allowed;
  final String? reasonCode;
  final String? reason;
  final double? effectiveMaxBaseUnits;
  final double? remainingQuotaBaseUnits;
  final bool overrideAllowed;

  bool get hasCanonicalReason =>
      reasonCode == null || vanCommercialReasonCodes.contains(reasonCode);
}

abstract interface class VanCommercialRepository {
  Future<VanCommercialOfferFeed> offersFor(VanCustomerScope customer);

  /// Flash reservation must remain server-authoritative and customer-scoped.
  ///
  /// The selected customer identity must be carried by the canonical backend
  /// contract; the authenticated Van operator must never be substituted for it.
  /// Implementations MUST NOT fall back to local/offline eligibility or stock
  /// calculations when this operation cannot reach the canonical backend.
  Future<VanCommercialQuote> quoteForCustomer({
    required VanCustomerScope customer,
    required int productId,
    required String sellingUnitCode,
    required double quantity,
    String? overrideReason,
  });

  Future<void> reserveFlashForCustomer({
    required VanCustomerScope customer,
    required int offerProductId,
    required double quantity,
    required String idempotencyKey,
    String? overrideReason,
  });
}

class VanCommercialContractPendingException implements Exception {
  const VanCommercialContractPendingException([
    this.reasonCode = 'ONLINE_VALIDATION_REQUIRED',
  ]);

  final String reasonCode;

  @override
  String toString() => reasonCode;
}
