import 'dart:convert';

import 'package:http/http.dart' as http;

enum MobileFooterDisplayMode { persistent, aboutOnly, hidden }

class MobileRuntimeVisibility {
  const MobileRuntimeVisibility({
    this.footerDisplayMode = MobileFooterDisplayMode.persistent,
  });

  final MobileFooterDisplayMode footerDisplayMode;

  bool get showPersistentFooter =>
      footerDisplayMode == MobileFooterDisplayMode.persistent;

  static Future<MobileRuntimeVisibility> fetch({
    required String baseUrl,
    required String locale,
    http.Client? client,
  }) async {
    if (baseUrl.trim().isEmpty) return const MobileRuntimeVisibility();
    final ownedClient = client == null;
    final httpClient = client ?? http.Client();
    try {
      final uri = Uri.parse(
        '$baseUrl/api/v1/mobile/runtime?app=customer&environment=production&locale=${locale == 'en' ? 'en' : 'ar'}',
      );
      final response = await httpClient
          .get(uri, headers: const {'Accept': 'application/json'})
          .timeout(const Duration(seconds: 8));
      if (response.statusCode < 200 || response.statusCode >= 300) {
        return const MobileRuntimeVisibility();
      }
      final decoded = jsonDecode(response.body);
      final data = decoded is Map ? decoded['data'] : null;
      final value = data is Map ? data['footer_display_mode'] : null;
      return MobileRuntimeVisibility(
        footerDisplayMode: switch (value) {
          'about_only' => MobileFooterDisplayMode.aboutOnly,
          'hidden' => MobileFooterDisplayMode.hidden,
          _ => MobileFooterDisplayMode.persistent,
        },
      );
    } catch (_) {
      return const MobileRuntimeVisibility();
    } finally {
      if (ownedClient) httpClient.close();
    }
  }
}
