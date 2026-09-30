import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/diagnostics/driver_runtime_inspector.dart';
import '../../core/localization/driver_translations.dart';
import '../../core/version/driver_version_policy_client.dart';
import '../../version_policy.dart';

typedef DriverUpdateLauncher = Future<bool> Function(Uri uri);

class DriverVersionPolicyGate extends StatefulWidget {
  const DriverVersionPolicyGate({
    super.key,
    required this.client,
    required this.child,
    this.updateLauncher,
  });

  final DriverVersionPolicyClient client;
  final Widget child;
  final DriverUpdateLauncher? updateLauncher;

  @override
  State<DriverVersionPolicyGate> createState() =>
      _DriverVersionPolicyGateState();
}

class _DriverVersionPolicyGateState extends State<DriverVersionPolicyGate> {
  AppVersionPolicy? _policy;
  Object? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant DriverVersionPolicyGate oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.client, widget.client)) {
      _load();
    }
  }

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final policy = await widget.client.fetch();
      if (!mounted) return;
      setState(() {
        _policy = policy;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _policy = null;
        _error = error;
        _loading = false;
      });
    }
  }

  Future<void> _openUpdate(AppVersionPolicy policy) async {
    final launcher = widget.updateLauncher ??
        (uri) => launchUrl(uri, mode: LaunchMode.externalApplication);
    final opened = await launcher(policy.storeUrl);
    if (!mounted || opened) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(context.tr('driver.update.open_failed'))),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return _StatusScaffold(
        key: const Key('driver-version-loading'),
        title: context.tr('driver.update.checking_title'),
        body: context.tr('driver.update.checking'),
        progress: true,
      );
    }

    if (_error != null) {
      return _StatusScaffold(
        key: const Key('driver-version-error'),
        title: context.tr('driver.update.error_title'),
        body: context.tr('driver.update.error'),
        action: FilledButton.icon(
          key: const Key('driver-version-retry'),
          onPressed: _load,
          icon: const Icon(Icons.refresh_rounded),
          label: Text(context.tr('driver.update.retry')),
        ),
      );
    }

    final policy = _policy;
    if (policy == null) {
      return const SizedBox.shrink();
    }

    if (policy.blocksApp) {
      return Scaffold(
        key: const Key('driver-version-blocking'),
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 520),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.system_update_alt_rounded, size: 56),
                        const SizedBox(height: 16),
                        Text(
                          context.tr('driver.update.required_title'),
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.headlineSmall
                              ?.copyWith(fontWeight: FontWeight.w900),
                        ),
                        const SizedBox(height: 10),
                        Text(
                          context.tr('driver.update.required_body'),
                          textAlign: TextAlign.center,
                        ),
                        const SizedBox(height: 20),
                        _VersionRow(
                          label: context.tr('driver.update.current'),
                          value: driverAppVersion,
                        ),
                        _VersionRow(
                          label: context.tr('driver.update.latest'),
                          value: policy.latestVersion,
                        ),
                        _VersionRow(
                          label: context.tr('driver.update.minimum'),
                          value: policy.minimumSupportedVersion,
                        ),
                        if (policy.releaseNotes?.trim().isNotEmpty == true) ...[
                          const SizedBox(height: 12),
                          Text(
                            policy.releaseNotes!.trim(),
                            textAlign: TextAlign.center,
                          ),
                        ],
                        const SizedBox(height: 20),
                        FilledButton.icon(
                          key: const Key('driver-version-update-now'),
                          onPressed: () => _openUpdate(policy),
                          icon: const Icon(Icons.open_in_new_rounded),
                          label: Text(context.tr('driver.update.now')),
                        ),
                        const SizedBox(height: 8),
                        TextButton(
                          key: const Key('driver-version-refresh-policy'),
                          onPressed: _load,
                          child: Text(context.tr('driver.update.check_again')),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      );
    }

    if (policy.status == AppUpdateStatus.optional) {
      return Column(
        children: [
          MaterialBanner(
            key: const Key('driver-version-optional'),
            content: Text(
              '${context.tr('driver.update.optional')} '
              '${policy.latestVersion}',
            ),
            actions: [
              TextButton(
                key: const Key('driver-version-optional-update'),
                onPressed: () => _openUpdate(policy),
                child: Text(context.tr('driver.update.now')),
              ),
            ],
          ),
          Expanded(child: widget.child),
        ],
      );
    }

    return widget.child;
  }
}

class _VersionRow extends StatelessWidget {
  const _VersionRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
          const SizedBox(width: 16),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.end,
            ),
          ),
        ],
      ),
    );
  }
}

class _StatusScaffold extends StatelessWidget {
  const _StatusScaffold({
    super.key,
    required this.title,
    required this.body,
    this.action,
    this.progress = false,
  });

  final String title;
  final String body;
  final Widget? action;
  final bool progress;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (progress) ...[
                    const CircularProgressIndicator(),
                    const SizedBox(height: 20),
                  ],
                  Text(
                    title,
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.headlineSmall
                        ?.copyWith(fontWeight: FontWeight.w900),
                  ),
                  const SizedBox(height: 10),
                  Text(body, textAlign: TextAlign.center),
                  if (action != null) ...[
                    const SizedBox(height: 20),
                    action!,
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
