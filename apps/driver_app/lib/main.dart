import 'dart:async';

import 'package:flutter/material.dart';

import 'app.dart';
import 'core/push/firebase_push_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const _FoodexDriverBootstrap());
}

class _FoodexDriverBootstrap extends StatefulWidget {
  const _FoodexDriverBootstrap();

  @override
  State<_FoodexDriverBootstrap> createState() => _FoodexDriverBootstrapState();
}

class _FoodexDriverBootstrapState extends State<_FoodexDriverBootstrap> {
  DriverFirebasePushService? _pushService;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(_bootstrapPush());
    });
  }

  Future<void> _bootstrapPush() async {
    final service = await DriverFirebasePushService.bootstrap();
    if (!mounted) {
      await service.dispose();
      return;
    }
    setState(() => _pushService = service);
  }

  @override
  void dispose() {
    unawaited(_pushService?.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FoodexDriverApp(pushService: _pushService);
  }
}
