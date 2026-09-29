import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../config/foodex_environment.dart';

class CustomerLiveAd {
  const CustomerLiveAd({
    required this.id,
    required this.title,
    required this.body,
    required this.frequency,
    required this.dismissible,
    this.imageUrl,
    this.ctaLabel,
    this.ctaTarget,
  });

  final int id;
  final String title;
  final String body;
  final String frequency;
  final bool dismissible;
  final String? imageUrl;
  final String? ctaLabel;
  final String? ctaTarget;

  factory CustomerLiveAd.fromJson(Map<String, dynamic> json) => CustomerLiveAd(
        id: (json['id'] as num?)?.toInt() ?? 0,
        title: json['title']?.toString() ?? '',
        body: json['body']?.toString() ?? '',
        frequency: json['frequency']?.toString() ?? 'once_per_install',
        dismissible: json['dismissible'] == true || json['dismissible'] == 1,
        imageUrl: json['image_url']?.toString(),
        ctaLabel: json['cta_label']?.toString(),
        ctaTarget: json['cta_target']?.toString(),
      );
}

class CustomerLiveAdService {
  CustomerLiveAdService({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;
  final Set<int> _sessionShown = <int>{};

  Future<void> showForContext(
    BuildContext context, {
    required String channel,
    int? storeId,
  }) async {
    final ads = await _fetch(
      channel: channel,
      storeId: storeId,
      locale: Localizations.localeOf(context).languageCode,
    );

    for (final ad in ads) {
      if (!await _shouldShow(ad)) continue;
      if (!context.mounted) return;

      await showDialog<void>(
        context: context,
        barrierDismissible: ad.dismissible,
        builder: (dialogContext) => PopScope(
          canPop: ad.dismissible,
          child: AlertDialog(
            contentPadding: const EdgeInsets.fromLTRB(20, 18, 20, 10),
            content: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (ad.imageUrl != null && ad.imageUrl!.isNotEmpty) ...[
                      ClipRRect(
                        borderRadius: BorderRadius.circular(16),
                        child: Image.network(
                          ad.imageUrl!,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                        ),
                      ),
                      const SizedBox(height: 14),
                    ],
                    Text(
                      ad.title,
                      style: Theme.of(dialogContext).textTheme.titleLarge?.copyWith(
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                    if (ad.body.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Text(ad.body),
                    ],
                  ],
                ),
              ),
            ),
            actions: [
              if (ad.dismissible)
                TextButton(
                  onPressed: () => Navigator.pop(dialogContext),
                  child: Text(
                    Localizations.localeOf(dialogContext).languageCode == 'ar'
                        ? 'إغلاق'
                        : 'Close',
                  ),
                ),
              if (ad.ctaLabel != null && ad.ctaLabel!.trim().isNotEmpty)
                FilledButton(
                  onPressed: () {
                    Navigator.pop(dialogContext);
                    final target = ad.ctaTarget?.trim();
                    if (target != null && target.startsWith('/')) {
                      Navigator.of(context).pushNamed(target);
                    }
                  },
                  child: Text(ad.ctaLabel!),
                ),
            ],
          ),
        ),
      );

      await _markShown(ad);
      if (ad.frequency != 'every_open') return;
    }
  }

  Future<List<CustomerLiveAd>> _fetch({
    required String channel,
    required String locale,
    int? storeId,
  }) async {
    final baseUrl = FoodexEnvironment.apiBaseUrl;
    if (baseUrl.isEmpty) return const [];

    final query = <String, String>{
      'channel': channel,
      'locale': locale,
      if (storeId != null && storeId > 0) 'store_id': '$storeId',
    };
    final uri = Uri.parse('$baseUrl/api/v1/live-ads').replace(queryParameters: query);
    final response = await _client.get(uri, headers: const {'Accept': 'application/json'});
    if (response.statusCode < 200 || response.statusCode >= 300 || response.body.isEmpty) {
      return const [];
    }

    final decoded = jsonDecode(response.body);
    if (decoded is! Map || decoded['data'] is! List) return const [];

    return (decoded['data'] as List)
        .whereType<Map>()
        .map((row) => CustomerLiveAd.fromJson(Map<String, dynamic>.from(row)))
        .where((ad) => ad.id > 0)
        .toList(growable: false);
  }

  Future<bool> _shouldShow(CustomerLiveAd ad) async {
    if (ad.frequency == 'every_open') return true;
    if (_sessionShown.contains(ad.id)) return false;
    if (ad.frequency == 'once_per_session') return true;

    final prefs = await SharedPreferences.getInstance();
    return !(prefs.getStringList('foodex.live_ads.seen') ?? const <String>[])
        .contains('${ad.id}');
  }

  Future<void> _markShown(CustomerLiveAd ad) async {
    _sessionShown.add(ad.id);
    if (ad.frequency != 'once_per_install') return;

    final prefs = await SharedPreferences.getInstance();
    final seen = <String>{
      ...(prefs.getStringList('foodex.live_ads.seen') ?? const <String>[]),
      '${ad.id}',
    }.toList(growable: false);
    await prefs.setStringList('foodex.live_ads.seen', seen);
  }
}
