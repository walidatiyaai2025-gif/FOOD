import 'package:flutter/material.dart';

import '../../core/theme/foodex_van_theme.dart';

class VanFoundationScreen extends StatelessWidget {
  const VanFoundationScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 4,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('FOODEX Van'),
          bottom: const TabBar(
            isScrollable: true,
            tabs: [
              Tab(text: 'Overview'),
              Tab(text: 'Routes'),
              Tab(text: 'Visits'),
              Tab(text: 'Wallet'),
            ],
          ),
        ),
        body: const TabBarView(
          children: [
            _FoundationState(
              icon: Icons.local_shipping_outlined,
              title: 'Van foundation ready',
              subtitle: 'Operational modules plug into this shell without duplicating business domains.',
            ),
            _FoundationState(
              icon: Icons.route_outlined,
              title: 'Routes',
              subtitle: 'Routing remains backend-authoritative and configuration-driven.',
            ),
            _FoundationState(
              icon: Icons.people_outline,
              title: 'Visits',
              subtitle: 'Field visits and order capture will reuse canonical customer and commerce APIs.',
            ),
            _FoundationState(
              icon: Icons.account_balance_wallet_outlined,
              title: 'Wallet',
              subtitle: 'Collections and remittances will reuse the shared custody domain.',
            ),
          ],
        ),
      ),
    );
  }
}

class _FoundationState extends StatelessWidget {
  const _FoundationState({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  final IconData icon;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 520),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: FoodexVanTokens.surface,
              border: Border.all(color: FoodexVanTokens.border),
              borderRadius: BorderRadius.circular(FoodexVanTokens.cardRadius),
            ),
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(icon, size: 40, color: FoodexVanTokens.green),
                  const SizedBox(height: 16),
                  Text(title, style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 8),
                  Text(
                    subtitle,
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: FoodexVanTokens.muted,
                        ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
