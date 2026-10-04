import 'dart:convert';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../config/foodex_environment.dart';

class CustomerNotificationCampaignPopup {
  const CustomerNotificationCampaignPopup({
    required this.id,
    required this.title,
    required this.body,
    required this.frequency,
    this.imageUrl,
    this.ctaLabel,
    this.ctaTarget,
  });

  final int id;
  final String title;
  final String body;
  final String frequency;
  final String? imageUrl;
  final String? ctaLabel;
  final String? ctaTarget;

  factory CustomerNotificationCampaignPopup.fromJson(
    Map<String, dynamic> json,
  ) =>
      CustomerNotificationCampaignPopup(
        id: (json['id'] as num?)?.toInt() ?? 0,
        title: json['title']?.toString() ?? '',
        body: json['body']?.toString() ?? '',
        frequency: json['frequency']?.toString() ?? 'once_per_session',
        imageUrl: json['image_url']?.toString(),
        ctaLabel: json['cta_label']?.toString(),
        ctaTarget: json['cta_target']?.toString(),
      );
}

enum _CampaignPopupAction { dismiss, open }

class CustomerNotificationCampaignPopupService {
  CustomerNotificationCampaignPopupService({http.Client? client})
      : _client = client ?? http.Client();

  static const _installIdKey = 'foodex.notification_campaign.install_id';
  static final Set<int> _shownThisProcess = <int>{};

  final http.Client _client;

  Future<void> showForContext(
    BuildContext context, {
    required String channel,
    int? storeId,
    String? accessToken,
  }) async {
    final installId = await _installId();
    final locale = Localizations.localeOf(context).languageCode;
    final campaigns = await _fetch(
      channel: channel,
      storeId: storeId,
      locale: locale,
      installId: installId,
      accessToken: accessToken,
    );

    for (final campaign in campaigns) {
      if (_shownThisProcess.contains(campaign.id)) continue;
      _shownThisProcess.add(campaign.id);

      if (!context.mounted) return;
      await _event(
        campaign: campaign,
        event: 'impression',
        channel: channel,
        storeId: storeId,
        locale: locale,
        installId: installId,
        accessToken: accessToken,
      );

      if (!context.mounted) return;
      final action = await showDialog<_CampaignPopupAction>(
        context: context,
        barrierDismissible: true,
        builder: (dialogContext) => AlertDialog(
          contentPadding: const EdgeInsets.fromLTRB(20, 18, 20, 10),
          content: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (campaign.imageUrl != null &&
                      campaign.imageUrl!.trim().isNotEmpty) ...[
                    ClipRRect(
                      borderRadius: BorderRadius.circular(16),
                      child: Image.network(
                        campaign.imageUrl!,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) =>
                            const SizedBox.shrink(),
                      ),
                    ),
                    const SizedBox(height: 14),
                  ],
                  Text(
                    campaign.title,
                    style: Theme.of(dialogContext)
                        .textTheme
                        .titleLarge
                        ?.copyWith(fontWeight: FontWeight.w900),
                  ),
                  if (campaign.body.trim().isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Text(campaign.body),
                  ],
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(
                dialogContext,
                _CampaignPopupAction.dismiss,
              ),
              child: Text(
                Localizations.localeOf(dialogContext).languageCode == 'ar'
                    ? 'إغلاق'
                    : 'Close',
              ),
            ),
            if (campaign.ctaLabel != null &&
                campaign.ctaLabel!.trim().isNotEmpty &&
                campaign.ctaTarget != null &&
                campaign.ctaTarget!.startsWith('/'))
              FilledButton(
                onPressed: () => Navigator.pop(
                  dialogContext,
                  _CampaignPopupAction.open,
                ),
                child: Text(campaign.ctaLabel!),
              ),
          ],
        ),
      );

      final resolvedAction = action ?? _CampaignPopupAction.dismiss;
      await _event(
        campaign: campaign,
        event: resolvedAction == _CampaignPopupAction.open ? 'open' : 'dismiss',
        channel: channel,
        storeId: storeId,
        locale: locale,
        installId: installId,
        accessToken: accessToken,
      );

      if (resolvedAction == _CampaignPopupAction.open &&
          context.mounted &&
          campaign.ctaTarget != null &&
          campaign.ctaTarget!.startsWith('/')) {
        Navigator.of(context).pushNamed(campaign.ctaTarget!);
      }

      return;
    }
  }

  Future<List<CustomerNotificationCampaignPopup>> _fetch({
    required String channel,
    required String locale,
    required String installId,
    int? storeId,
    String? accessToken,
  }) async {
    final baseUrl = FoodexEnvironment.apiBaseUrl;
    if (baseUrl.isEmpty) return const [];

    try {
      final query = <String, String>{
        'channel': channel,
        'locale': locale == 'en' ? 'en' : 'ar',
        'install_id': installId,
        if (storeId != null && storeId > 0) 'store_id': '$storeId',
      };
      final response = await _client.get(
        Uri.parse('$baseUrl/api/v1/notification-campaign-popups')
            .replace(queryParameters: query),
        headers: _headers(accessToken),
      );

      if (response.statusCode < 200 ||
          response.statusCode >= 300 ||
          response.body.isEmpty) {
        return const [];
      }

      final decoded = jsonDecode(response.body);
      if (decoded is! Map || decoded['data'] is! List) return const [];

      return (decoded['data'] as List)
          .whereType<Map>()
          .map(
            (row) => CustomerNotificationCampaignPopup.fromJson(
              Map<String, dynamic>.from(row),
            ),
          )
          .where((campaign) => campaign.id > 0)
          .toList(growable: false);
    } catch (_) {
      return const [];
    }
  }

  Future<void> _event({
    required CustomerNotificationCampaignPopup campaign,
    required String event,
    required String channel,
    required String locale,
    required String installId,
    int? storeId,
    String? accessToken,
  }) async {
    final baseUrl = FoodexEnvironment.apiBaseUrl;
    if (baseUrl.isEmpty) return;

    try {
      await _client.post(
        Uri.parse(
          '$baseUrl/api/v1/notification-campaign-popups/${campaign.id}/events',
        ),
        headers: {
          ..._headers(accessToken),
          'Content-Type': 'application/json',
        },
        body: jsonEncode({
          'event': event,
          'channel': channel,
          'locale': locale == 'en' ? 'en' : 'ar',
          'install_id': installId,
          if (storeId != null && storeId > 0) 'store_id': storeId,
        }),
      );
    } catch (_) {
      // Engagement telemetry is best effort and cannot block navigation.
    }
  }

  Map<String, String> _headers(String? accessToken) => {
        'Accept': 'application/json',
        if (accessToken != null && accessToken.trim().isNotEmpty)
          'Authorization': 'Bearer ${accessToken.trim()}',
      };

  Future<String> _installId() async {
    final prefs = await SharedPreferences.getInstance();
    final existing = prefs.getString(_installIdKey)?.trim();
    if (existing != null && existing.isNotEmpty) return existing;

    final random = Random.secure();
    final entropy = List<int>.generate(12, (_) => random.nextInt(256))
        .map((value) => value.toRadixString(16).padLeft(2, '0'))
        .join();
    final generated =
        'campaign-${DateTime.now().microsecondsSinceEpoch.toRadixString(36)}-$entropy';
    await prefs.setString(_installIdKey, generated);

    return generated;
  }
}
