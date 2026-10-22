import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/foodex_theme.dart';
import '../data/mock_data.dart';
import '../models/marketplace_models.dart';
import '../widgets/foodex_widgets.dart';

class CartScreen extends StatelessWidget {
  const CartScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: appState,
      builder: (context, _) => FoodexPageScaffold(
        navIndex: 2,
        child: appState.cart.isEmpty
            ? _EmptyCart(onBrowse: () => Navigator.pushNamedAndRemoveUntil(context, '/restaurants', (route) => false))
            : ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
                children: [
                  Text('السلة (${appState.cartCount})', style: Theme.of(context).textTheme.headlineMedium),
                  const SizedBox(height: 14),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(13),
                      child: Row(
                        children: [
                          CircleAvatar(
                            radius: 24,
                            backgroundColor: FoodexColors.limeSoft,
                            child: Image.network(
                              restaurants.first.logoUrl,
                              width: 42,
                              height: 42,
                              fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) => const Icon(Icons.restaurant_rounded, color: FoodexColors.deepGreen),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(restaurants.first.name, style: Theme.of(context).textTheme.titleMedium), const SizedBox(height: 3), Text(restaurants.first.deliveryMinutes, style: Theme.of(context).textTheme.bodySmall)])),
                          TextButton(onPressed: () => Navigator.pushNamed(context, '/restaurant', arguments: restaurants.first), child: const Text('إضافة وجبات')),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  ...appState.cart.map((line) => _CartLineCard(line: line)),
                  const SizedBox(height: 8),
                  const _PromoCodeCard(),
                  const SizedBox(height: 12),
                  _OrderTotals(subtotal: appState.subtotal),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: () {
                      if (!appState.isLoggedIn) {
                        Navigator.pushNamed(context, '/profile');
                        return;
                      }
                      Navigator.pushNamed(context, '/checkout');
                    },
                    child: Text(appState.isLoggedIn ? 'إتمام الطلب' : 'سجّل الدخول لإتمام الطلب'),
                  ),
                ],
              ),
      ),
    );
  }
}

class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  String deliveryTime = 'أسرع وقت';
  String payment = 'الدفع عند الاستلام';
  bool leaveAtDoor = false;

  @override
  Widget build(BuildContext context) {
    return FoodexPageScaffold(
      canPop: true,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          Text('إتمام الطلب', style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 16),
          _CheckoutCard(
            title: 'عنوان التوصيل',
            icon: Icons.location_on_outlined,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('المنزل • مدينة نصر، القاهرة', style: TextStyle(fontWeight: FontWeight.w800)), SizedBox(height: 4), Text('شارع أحمد الزمر، عمارة 12، شقة 5', style: TextStyle(fontSize: 11, color: FoodexColors.muted))])),
                TextButton(onPressed: () {}, child: const Text('تغيير')),
              ],
            ),
          ),
          const SizedBox(height: 12),
          _CheckoutCard(
            title: 'وقت التوصيل',
            icon: Icons.schedule_outlined,
            child: Column(
              children: [
                RadioListTile<String>(value: 'أسرع وقت', groupValue: deliveryTime, onChanged: (v) => setState(() => deliveryTime = v!), title: const Text('أسرع وقت'), subtitle: Text(restaurants.first.deliveryMinutes), contentPadding: EdgeInsets.zero),
                RadioListTile<String>(value: 'تحديد وقت', groupValue: deliveryTime, onChanged: (v) => setState(() => deliveryTime = v!), title: const Text('حدد وقت لاحق'), contentPadding: EdgeInsets.zero),
              ],
            ),
          ),
          const SizedBox(height: 12),
          _CheckoutCard(
            title: 'طريقة الدفع',
            icon: Icons.account_balance_wallet_outlined,
            child: Column(
              children: ['الدفع عند الاستلام', 'بطاقة بنكية', 'محفظة إلكترونية'].map((method) {
                return RadioListTile<String>(value: method, groupValue: payment, onChanged: (v) => setState(() => payment = v!), title: Text(method), contentPadding: EdgeInsets.zero);
              }).toList(),
            ),
          ),
          const SizedBox(height: 12),
          _CheckoutCard(
            title: 'تعليمات التوصيل',
            icon: Icons.delivery_dining_outlined,
            child: SwitchListTile.adaptive(contentPadding: EdgeInsets.zero, value: leaveAtDoor, onChanged: (v) => setState(() => leaveAtDoor = v), title: const Text('اترك الطلب عند الباب')),
          ),
          const SizedBox(height: 12),
          _OrderTotals(subtotal: appState.subtotal),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: () {
              appState.clearCart();
              Navigator.pushNamedAndRemoveUntil(context, '/tracking', (route) => false);
            },
            child: const Text('تأكيد الطلب'),
          ),
        ],
      ),
    );
  }
}

class OrdersScreen extends StatelessWidget {
  const OrdersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return FoodexPageScaffold(
      navIndex: 3,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
        children: [
          Text('طلباتي', style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 14),
          SegmentedButton<String>(segments: const [ButtonSegment(value: 'all', label: Text('الكل')), ButtonSegment(value: 'active', label: Text('الحالية')), ButtonSegment(value: 'done', label: Text('المكتملة'))], selected: const {'all'}, onSelectionChanged: (_) {}, showSelectedIcon: false),
          const SizedBox(height: 14),
          ...previousOrders.map((order) => _OrderCard(order: order)),
        ],
      ),
    );
  }
}

class OrderTrackingScreen extends StatelessWidget {
  const OrderTrackingScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return FoodexPageScaffold(
      canPop: false,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          Text('متابعة الطلب', style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 3),
          Text('#FX123456', style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 20),
          const _ProgressTimeline(),
          const SizedBox(height: 18),
          Container(
            height: 220,
            decoration: BoxDecoration(
              color: const Color(0xFFEAF2ED),
              borderRadius: BorderRadius.circular(22),
              border: Border.all(color: FoodexColors.border),
            ),
            child: Stack(
              children: [
                Positioned.fill(child: CustomPaint(painter: _MapPainter())),
                const PositionedDirectional(start: 34, bottom: 35, child: CircleAvatar(backgroundColor: FoodexColors.warning, child: Icon(Icons.delivery_dining_rounded, color: Colors.white))),
                const PositionedDirectional(end: 38, top: 36, child: CircleAvatar(backgroundColor: FoodexColors.deepGreen, child: Icon(Icons.home_rounded, color: Colors.white))),
                PositionedDirectional(
                  end: 20,
                  bottom: 18,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14), boxShadow: const [BoxShadow(color: FoodexColors.shadow, blurRadius: 10)]),
                    child: const Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('الوقت المتوقع', style: TextStyle(fontSize: 10, color: FoodexColors.muted)), SizedBox(height: 3), Text('25 - 35 دقيقة', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15))]),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 15),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(13),
              child: Row(
                children: [
                  const CircleAvatar(radius: 23, backgroundColor: FoodexColors.limeSoft, child: Icon(Icons.restaurant_rounded, color: FoodexColors.deepGreen)),
                  const SizedBox(width: 12),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(restaurants.first.name, style: Theme.of(context).textTheme.titleMedium), const SizedBox(height: 3), const Text('المطعم بيجهز طلبك دلوقتي', style: TextStyle(fontSize: 11, color: FoodexColors.muted))])),
                  IconButton.filledTonal(onPressed: () {}, icon: const Icon(Icons.call_outlined)),
                ],
              ),
            ),
          ),
          const SizedBox(height: 14),
          OutlinedButton.icon(onPressed: () => Navigator.pushNamedAndRemoveUntil(context, '/home', (route) => false), icon: const Icon(Icons.home_outlined), label: const Text('العودة للرئيسية')),
        ],
      ),
    );
  }
}

class _CartLineCard extends StatelessWidget {
  const _CartLineCard({required this.line});
  final CartLine line;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(11),
        child: Row(
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(13),
              child: Image.network(line.item.imageUrl, width: 82, height: 76, fit: BoxFit.cover, errorBuilder: (_, __, ___) => Container(width: 82, height: 76, color: FoodexColors.limeSoft, child: const Icon(Icons.fastfood_rounded))),
            ),
            const SizedBox(width: 11),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(line.item.name, style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 3),
                  Text('${line.size} • ${line.drink}', style: Theme.of(context).textTheme.bodySmall),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Text('${line.total.toStringAsFixed(0)} جنيه', style: const TextStyle(fontWeight: FontWeight.w900, color: FoodexColors.deepGreen)),
                      const Spacer(),
                      _QtyButton(icon: Icons.remove_rounded, onTap: () => appState.decrease(line)),
                      Padding(padding: const EdgeInsets.symmetric(horizontal: 9), child: Text('${line.quantity}', style: const TextStyle(fontWeight: FontWeight.w900))),
                      _QtyButton(icon: Icons.add_rounded, onTap: () => appState.increase(line)),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _QtyButton extends StatelessWidget {
  const _QtyButton({required this.icon, required this.onTap});
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Container(width: 28, height: 28, decoration: BoxDecoration(color: FoodexColors.mint, shape: BoxShape.circle, border: Border.all(color: FoodexColors.border)), child: Icon(icon, size: 16, color: FoodexColors.deepGreen)),
      );
}

class _PromoCodeCard extends StatelessWidget {
  const _PromoCodeCard();

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: TextField(
            decoration: InputDecoration(
              hintText: 'كود الخصم',
              prefixIcon: const Icon(Icons.local_offer_outlined),
              suffixIcon: TextButton(onPressed: () {}, child: const Text('تطبيق')),
            ),
          ),
        ),
      );
}

class _OrderTotals extends StatelessWidget {
  const _OrderTotals({required this.subtotal});
  final double subtotal;

  @override
  Widget build(BuildContext context) {
    const delivery = 15.0;
    final total = subtotal + delivery;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          children: [
            _AmountRow(label: 'المجموع الفرعي', value: '${subtotal.toStringAsFixed(0)} جنيه'),
            const SizedBox(height: 9),
            const _AmountRow(label: 'رسوم التوصيل', value: '15 جنيه'),
            const Divider(height: 24),
            _AmountRow(label: 'الإجمالي', value: '${total.toStringAsFixed(0)} جنيه', strong: true),
          ],
        ),
      ),
    );
  }
}

class _AmountRow extends StatelessWidget {
  const _AmountRow({required this.label, required this.value, this.strong = false});
  final String label;
  final String value;
  final bool strong;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Expanded(child: Text(label, style: TextStyle(fontWeight: strong ? FontWeight.w900 : FontWeight.w600))),
          Text(value, style: TextStyle(fontWeight: strong ? FontWeight.w900 : FontWeight.w700, color: strong ? FoodexColors.deepGreen : FoodexColors.ink)),
        ],
      );
}

class _CheckoutCard extends StatelessWidget {
  const _CheckoutCard({required this.title, required this.icon, required this.child});
  final String title;
  final IconData icon;
  final Widget child;

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [Icon(icon, color: FoodexColors.deepGreen), const SizedBox(width: 8), Text(title, style: Theme.of(context).textTheme.titleMedium)]),
              const SizedBox(height: 10),
              child,
            ],
          ),
        ),
      );
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({required this.order});
  final OrderSummary order;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: 11),
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: () {
            if (order.status.contains('تحضير')) Navigator.pushNamed(context, '/tracking');
          },
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Row(
              children: [
                const CircleAvatar(radius: 27, backgroundColor: FoodexColors.limeSoft, child: Icon(Icons.restaurant_rounded, color: FoodexColors.deepGreen)),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [Expanded(child: Text(order.restaurant, style: Theme.of(context).textTheme.titleMedium)), StatusPill(text: order.status)]),
                      const SizedBox(height: 5),
                      Text('${order.number} • ${order.date}', style: Theme.of(context).textTheme.bodySmall),
                      const SizedBox(height: 5),
                      Text('${order.total.toStringAsFixed(0)} جنيه', style: const TextStyle(fontWeight: FontWeight.w900, color: FoodexColors.deepGreen)),
                    ],
                  ),
                ),
                const Icon(Icons.chevron_left_rounded, color: FoodexColors.muted),
              ],
            ),
          ),
        ),
      );
}

class _EmptyCart extends StatelessWidget {
  const _EmptyCart({required this.onBrowse});
  final VoidCallback onBrowse;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(width: 100, height: 100, decoration: const BoxDecoration(color: FoodexColors.limeSoft, shape: BoxShape.circle), child: const Icon(Icons.shopping_cart_outlined, size: 48, color: FoodexColors.deepGreen)),
              const SizedBox(height: 18),
              Text('السلة فاضية', style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 8),
              const Text('اختار مطعم وابدأ أضف وجباتك المفضلة.', textAlign: TextAlign.center),
              const SizedBox(height: 18),
              SizedBox(width: 220, child: FilledButton(onPressed: onBrowse, child: const Text('استكشف المطاعم'))),
            ],
          ),
        ),
      );
}

class _ProgressTimeline extends StatelessWidget {
  const _ProgressTimeline();

  @override
  Widget build(BuildContext context) {
    final steps = [('تم تأكيد الطلب', true), ('جاري التحضير', true), ('في الطريق', false), ('تم التوصيل', false)];
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          children: List.generate(steps.length, (index) {
            final active = steps[index].$2;
            return Expanded(
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      children: [
                        CircleAvatar(radius: 13, backgroundColor: active ? FoodexColors.deepGreen : FoodexColors.border, child: Icon(active ? Icons.check_rounded : Icons.circle_outlined, size: 14, color: active ? Colors.white : FoodexColors.muted)),
                        const SizedBox(height: 6),
                        Text(steps[index].$1, textAlign: TextAlign.center, style: TextStyle(fontSize: 9.2, fontWeight: active ? FontWeight.w800 : FontWeight.w600, color: active ? FoodexColors.ink : FoodexColors.muted)),
                      ],
                    ),
                  ),
                  if (index < steps.length - 1)
                    Container(width: 20, height: 2, color: index < 1 ? FoodexColors.deepGreen : FoodexColors.border),
                ],
              ),
            );
          }),
        ),
      ),
    );
  }
}

class _MapPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final roadPaint = Paint()..color = Colors.white..strokeWidth = 5..strokeCap = StrokeCap.round;
    final minorPaint = Paint()..color = const Color(0xFFD5E3DA)..strokeWidth = 1.5;
    for (double y = 30; y < size.height; y += 38) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y + 18), minorPaint);
    }
    for (double x = 25; x < size.width; x += 65) {
      canvas.drawLine(Offset(x, 0), Offset(x + 25, size.height), minorPaint);
    }
    final path = Path()
      ..moveTo(55, size.height - 55)
      ..lineTo(115, size.height - 95)
      ..lineTo(175, size.height - 78)
      ..lineTo(230, 82)
      ..lineTo(size.width - 55, 55);
    canvas.drawPath(path, roadPaint);
    canvas.drawPath(path, Paint()..color = FoodexColors.deepGreen..style = PaintingStyle.stroke..strokeWidth = 2.4..strokeCap = StrokeCap.round);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
