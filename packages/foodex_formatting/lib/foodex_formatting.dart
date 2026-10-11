/// Shared display-only formatting for FOODEX numeric values.
///
/// These helpers never alter numeric authority, persistence, request payloads,
/// idempotency keys, settlement precision, or calculation inputs.
String foodexCompactDecimal(num value, {int maxFractionDigits = 3}) {
  if (maxFractionDigits < 0) {
    throw ArgumentError.value(
      maxFractionDigits,
      'maxFractionDigits',
      'must be zero or greater',
    );
  }
  final fixed = value.toStringAsFixed(maxFractionDigits);
  if (maxFractionDigits == 0 || !fixed.contains('.')) return fixed;
  return fixed
      .replaceFirst(RegExp(r'0+$'), '')
      .replaceFirst(RegExp(r'\.$'), '');
}

/// Formats only the numeric amount. EGP is compacted for display while other
/// currencies retain their requested fixed precision.
String foodexMoneyAmount(num value, String currency, {int fractionDigits = 3}) {
  final normalized = currency.trim().toUpperCase();
  if (normalized == 'EGP') {
    return foodexCompactDecimal(value, maxFractionDigits: fractionDigits);
  }
  return value.toStringAsFixed(fractionDigits);
}

/// Formats a money value with its currency code.
String foodexMoneyText(
  num value,
  String currency, {
  int fractionDigits = 3,
  bool currencyFirst = false,
}) {
  final amount = foodexMoneyAmount(
    value,
    currency,
    fractionDigits: fractionDigits,
  );
  final code = currency.trim();
  if (code.isEmpty) return amount;
  return currencyFirst ? '$code $amount' : '$amount $code';
}

/// Formats a unit/count display without redundant trailing zeros.
///
/// Set [preserveFixedPrecision] for measurement/configuration fields whose
/// presentation contract explicitly requires a fixed decimal scale.
String foodexQuantity(
  num value, {
  int fractionDigits = 3,
  bool preserveFixedPrecision = false,
}) {
  if (preserveFixedPrecision) {
    return value.toStringAsFixed(fractionDigits);
  }
  return foodexCompactDecimal(value, maxFractionDigits: fractionDigits);
}
