import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/localization/app_translations.dart';

typedef MarketplaceBarcodeScanner = Future<String?> Function(BuildContext context);

Future<String?> showMarketplaceBarcodeScanner(BuildContext context) {
  return Navigator.of(context).push<String>(
    MaterialPageRoute<String>(
      fullscreenDialog: true,
      builder: (_) => const _MarketplaceBarcodeScannerPage(),
    ),
  );
}

class _MarketplaceBarcodeScannerPage extends StatefulWidget {
  const _MarketplaceBarcodeScannerPage();

  @override
  State<_MarketplaceBarcodeScannerPage> createState() =>
      _MarketplaceBarcodeScannerPageState();
}

class _MarketplaceBarcodeScannerPageState
    extends State<_MarketplaceBarcodeScannerPage> {
  bool _completed = false;

  void _handleCapture(BarcodeCapture capture) {
    if (_completed) return;

    String? value;
    for (final barcode in capture.barcodes) {
      final candidate = barcode.rawValue?.trim();
      if (candidate != null && candidate.isNotEmpty) {
        value = candidate;
        break;
      }
    }

    if (value == null) return;
    _completed = true;
    Navigator.of(context).pop(value);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(
          backgroundColor: Colors.black,
          foregroundColor: Colors.white,
          title: Text(context.tr('customer.marketplace.scan')),
        ),
        body: Stack(
          fit: StackFit.expand,
          children: [
            MobileScanner(
              onDetect: _handleCapture,
            ),
            IgnorePointer(
              child: Center(
                child: Container(
                  width: 260,
                  height: 180,
                  decoration: BoxDecoration(
                    border: Border.all(color: Colors.white, width: 3),
                    borderRadius: BorderRadius.circular(22),
                  ),
                ),
              ),
            ),
            PositionedDirectional(
              start: 24,
              end: 24,
              bottom: 34,
              child: SafeArea(
                top: false,
                child: Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 12,
                  ),
                  decoration: BoxDecoration(
                    color: Colors.black.withOpacity(.66),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Text(
                    context.tr('customer.marketplace.scan'),
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      );
}
