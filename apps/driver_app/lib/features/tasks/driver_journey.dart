import 'package:flutter/material.dart';

import '../../navigation.dart';

enum DriverLoadState { loading, ready, empty, error, offline }

class DriverAssignment {
  const DriverAssignment({required this.id, required this.channel, required this.reference, required this.status});
  final int id;
  final DriverChannel channel;
  final String reference;
  final String status;
}

abstract interface class DriverAssignmentRepository {
  Future<List<DriverAssignment>> list(DriverChannel channel);
  Future<void> transition(int id, DriverChannel channel, String action);
}

class DriverJourneyPage extends StatefulWidget {
  const DriverJourneyPage({super.key, required this.channel, required this.repository});
  final DriverChannel channel;
  final DriverAssignmentRepository repository;

  @override
  State<DriverJourneyPage> createState() => _DriverJourneyPageState();
}

class _DriverJourneyPageState extends State<DriverJourneyPage> {
  DriverLoadState state = DriverLoadState.loading;
  List<DriverAssignment> assignments = const [];

  @override
  void initState() { super.initState(); _load(); }

  Future<void> _load() async {
    setState(() => state = DriverLoadState.loading);
    try {
      final rows = await widget.repository.list(widget.channel);
      if (!mounted) return;
      assignments = rows.where((row) => row.channel == widget.channel).toList(growable: false);
      state = assignments.isEmpty ? DriverLoadState.empty : DriverLoadState.ready;
    } on DriverOfflineException {
      if (mounted) setState(() => state = DriverLoadState.offline);
    } catch (_) {
      if (mounted) setState(() => state = DriverLoadState.error);
    }
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(textDirection: Directionality.of(context), child: Scaffold(
      appBar: AppBar(title: Text(widget.channel == DriverChannel.b2c ? 'توصيلات التجزئة' : 'توصيلات الجملة')),
      body: switch (state) {
        DriverLoadState.loading => const Center(child: CircularProgressIndicator(key: Key('driver-loading'))),
        DriverLoadState.empty => const Center(child: Text('لا توجد توصيلات مسندة', key: Key('driver-empty'))),
        DriverLoadState.error => _Retry(message: 'تعذر تحميل التوصيلات', onRetry: _load, keyName: 'driver-error'),
        DriverLoadState.offline => _Retry(message: 'لا يوجد اتصال. أعد المحاولة عند عودة الشبكة.', onRetry: _load, keyName: 'driver-offline'),
        DriverLoadState.ready => RefreshIndicator(onRefresh: _load, child: ListView(children: assignments.map((a) => ListTile(key: Key('assignment-${a.id}'), title: Text(a.reference), subtitle: Text(a.status))).toList())),
      },
    ));
  }
}

class _Retry extends StatelessWidget {
  const _Retry({required this.message, required this.onRetry, required this.keyName});
  final String message; final VoidCallback onRetry; final String keyName;
  @override Widget build(BuildContext context) => Center(key: Key(keyName), child: Column(mainAxisSize: MainAxisSize.min, children: [Text(message), const SizedBox(height: 12), FilledButton(onPressed: onRetry, child: const Text('إعادة المحاولة'))]));
}

class DriverOfflineException implements Exception { const DriverOfflineException(); }
