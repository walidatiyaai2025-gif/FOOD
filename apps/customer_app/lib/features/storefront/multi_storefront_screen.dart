import 'package:flutter/material.dart';

import '../../core/api/b2b_api.dart';
import '../../core/api/b2c_catalog_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/routing/customer_routes.dart';

class MultiStorefrontScreen extends StatefulWidget {
  const MultiStorefrontScreen({
    required this.definition,
    required this.location,
    required this.session,
    required this.catalogApi,
    required this.actionApi,
    this.b2bApi,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final CustomerSession session;
  final B2cCatalogApi catalogApi;
  final CustomerActionApi actionApi;
  final B2bApi? b2bApi;

  @override
  State<MultiStorefrontScreen> createState() => _MultiStorefrontScreenState();
}

class _MultiStorefrontScreenState extends State<MultiStorefrontScreen> {
  static const grocery = _StoreTheme(Color(0xFF078A43), Color(0xFF006736), Color(0xFFB5F23E), Color(0xFFF8FBF9));
  static const pharmacy = _StoreTheme(Color(0xFF0A8DDA), Color(0xFF0668A9), Color(0xFF37CCFF), Color(0xFFF8FCFF));
  static const wholesale = _StoreTheme(Color(0xFF5D2A91), Color(0xFF35195E), Color(0xFFB983F0), Color(0xFFFBFAFD));

  int? get storeId => int.tryParse(Uri.parse(widget.location).queryParameters['store'] ?? Uri.parse(widget.location).queryParameters['store_id'] ?? '');
  int? get productId => int.tryParse(Uri.parse(widget.location).pathSegments.lastOrNull ?? '');

  @override
  Widget build(BuildContext context) {
    switch (widget.definition.pattern) {
      case CustomerRoutePaths.stores:
        return _storeSelector();
      case CustomerRoutePaths.home:
        return _retailHome();
      case CustomerRoutePaths.productDetails:
        return _retailProductDetails();
      case CustomerRoutePaths.b2bDashboard:
        return _b2bHome();
      case CustomerRoutePaths.b2bProductDetails:
        return _b2bProductDetails();
      case CustomerRoutePaths.b2bCart:
        return _b2bCart();
      case CustomerRoutePaths.b2bCheckout:
        return _b2bCheckout();
      case CustomerRoutePaths.b2bOrders:
        return _b2bOrders();
      default:
        return const SizedBox.shrink();
    }
  }

  _StoreTheme _retailTheme(B2cStore? store) {
    final key = '${store?.code ?? ''} ${store?.name ?? ''}'.toLowerCase();
    return key.contains('pharm') || key.contains('صيد') ? pharmacy : grocery;
  }

  Widget _shell({required Widget child, required _StoreTheme theme, Widget? bottom}) => Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          backgroundColor: theme.background,
          body: SafeArea(child: child),
          bottomNavigationBar: bottom,
        ),
      );

  Widget _storeSelector() => _shell(
        theme: grocery,
        child: FutureBuilder<List<B2cStore>>(
          future: widget.catalogApi.stores(),
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) return const _LoadingSkeleton();
            if (snapshot.hasError) return _ErrorState(onRetry: () => setState(() {}));
            final stores = snapshot.data ?? const <B2cStore>[];
            return ListView(
              padding: const EdgeInsets.fromLTRB(28, 24, 28, 28),
              children: [
                const _FoodexBrand(),
                const SizedBox(height: 28),
                const Text('اختر المتجر', textAlign: TextAlign.center, style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: Color(0xFF102033))),
                const SizedBox(height: 6),
                const Text('المتاجر المتاحة حسب موقع الخدمة', textAlign: TextAlign.center, style: TextStyle(fontSize: 13, color: Color(0xFF6B7785))),
                const SizedBox(height: 18),
                const _FilterPills(),
                const SizedBox(height: 12),
                ...stores.map((store) => Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: _StoreCard(
                        store: store,
                        theme: _retailTheme(store),
                        onTap: () => Navigator.of(context).pushReplacementNamed('/home?store=${store.id}'),
                      ),
                    )),
                if (widget.session.isAuthenticated && widget.session.channel == CustomerChannel.b2b)
                  _WholesaleEntryCard(
                    onTap: () => Navigator.of(context).pushReplacementNamed(CustomerRoutePaths.b2bDashboard),
                  ),
              ],
            );
          },
        ),
      );

  Widget _retailHome() {
    final id = storeId;
    if (id == null) return _shell(theme: grocery, child: _ErrorState(onRetry: () => Navigator.of(context).pushReplacementNamed(CustomerRoutePaths.stores)));
    return FutureBuilder<_RetailHomeData>(
      future: _loadRetailHome(id),
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) return _shell(theme: grocery, child: const _LoadingSkeleton());
        if (snapshot.hasError || snapshot.data == null) return _shell(theme: grocery, child: _ErrorState(onRetry: () => setState(() {})));
        final data = snapshot.data!;
        final theme = _retailTheme(data.store);
        return _shell(
          theme: theme,
          bottom: _BottomNav(theme: theme, wholesale: false),
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(child: _RetailHeader(store: data.store, theme: theme)),
              SliverToBoxAdapter(child: _SearchBox(theme: theme, hint: 'ابحث عن منتجات المتجر...')),
              SliverToBoxAdapter(child: _HeroBanner(banner: data.banners.isEmpty ? null : data.banners.first, theme: theme)),
              SliverToBoxAdapter(child: _CategoryRail(categories: data.categories, theme: theme, storeId: id)),
              SliverToBoxAdapter(child: _SectionHeader(title: 'منتجات موصى بها', theme: theme)),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(12, 0, 12, 100),
                sliver: SliverGrid(
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 3, childAspectRatio: .52, crossAxisSpacing: 8, mainAxisSpacing: 8),
                  delegate: SliverChildBuilderDelegate(
                    (context, index) => _RetailProductCard(
                      product: data.products[index],
                      theme: theme,
                      onTap: () => Navigator.of(context).pushNamed('/products/${data.products[index].id}?store=$id'),
                    ),
                    childCount: data.products.length,
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Future<_RetailHomeData> _loadRetailHome(int id) async {
    final stores = await widget.catalogApi.stores();
    final store = stores.firstWhere((s) => s.id == id);
    final result = await Future.wait<Object>([
      widget.catalogApi.categories(id),
      widget.catalogApi.products(id),
      widget.catalogApi.banners(id),
    ]);
    return _RetailHomeData(store, result[0] as List<B2cCategory>, result[1] as List<B2cProduct>, result[2] as List<B2cBanner>);
  }

  Widget _retailProductDetails() {
    final sid = storeId;
    final pid = productId;
    if (sid == null || pid == null) return _shell(theme: grocery, child: _ErrorState(onRetry: () => Navigator.of(context).pop()));
    return FutureBuilder<B2cProduct>(
      future: widget.catalogApi.product(pid, storeId: sid),
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) return _shell(theme: grocery, child: const _LoadingSkeleton());
        if (snapshot.hasError || snapshot.data == null) return _shell(theme: grocery, child: _ErrorState(onRetry: () => setState(() {})));
        final p = snapshot.data!;
        return _shell(
          theme: grocery,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 30),
            children: [
              const _TopBar(title: 'تفاصيل المنتج'),
              _ProductImage(url: p.imageUrl),
              Text(p.name, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              Text(p.sku, style: const TextStyle(fontSize: 12, color: Color(0xFF6B7785))),
              const SizedBox(height: 8),
              Text('EGP ${p.price?.toStringAsFixed(0) ?? '-'}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Color(0xFF078A43))),
              const SizedBox(height: 12),
              Row(children: [
                const _QtyStepper(),
                const SizedBox(width: 10),
                Expanded(child: FilledButton(onPressed: () => widget.actionApi.addCartItem(storeId: sid, productId: pid, quantity: 1), child: const Text('أضف إلى السلة'))),
              ]),
              const SizedBox(height: 14),
              const _Accordion(title: 'تفاصيل المنتج'),
              const _Accordion(title: 'القيم الغذائية'),
              const _Accordion(title: 'منتجات مشابهة'),
            ],
          ),
        );
      },
    );
  }

  Widget _b2bHome() => _b2bRemote(
        '/api/v1/b2b/products',
        (value) {
          final rows = _rows(value);
          return _shell(
            theme: wholesale,
            bottom: _BottomNav(theme: wholesale, wholesale: true),
            child: ListView(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 100),
              children: [
                const _B2bHeader(),
                const _SearchBox(theme: wholesale, hint: 'ابحث بالاسم أو SKU أو الباركود...'),
                const _WholesaleHero(),
                const _B2bCategoryStrip(),
                const _SectionHeader(title: 'عروض الجملة', theme: wholesale),
                GridView.builder(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: rows.length,
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 2, childAspectRatio: .70, crossAxisSpacing: 8, mainAxisSpacing: 8),
                  itemBuilder: (context, i) => _WholesaleProductCard(
                    row: rows[i],
                    onTap: () => Navigator.of(context).pushNamed('/b2b/products/${rows[i]['id']}?store_id=${rows[i]['store_id']}'),
                  ),
                ),
              ],
            ),
          );
        },
      );

  Widget _b2bProductDetails() {
    final uri = Uri.parse(widget.location);
    final sid = uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
    final pid = productId;
    if (sid == null || pid == null) return _shell(theme: wholesale, child: _ErrorState(onRetry: () => Navigator.of(context).pop()));
    return _b2bRemote(
      '/api/v1/b2b/products/$pid?store_id=$sid',
      (value) {
        final m = Map<String, dynamic>.from(value as Map);
        return _shell(
          theme: wholesale,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              const _TopBar(title: 'تفاصيل منتج الجملة'),
              _ProductImage(url: m['image_url']?.toString()),
              Text(m['name']?.toString() ?? '', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              Text('SKU: ${m['sku'] ?? ''}', style: const TextStyle(fontSize: 12, color: Color(0xFF6F6A7D))),
              const SizedBox(height: 10),
              _PriceTable(row: m),
              const SizedBox(height: 10),
              _InfoBand(text: 'الحد الأدنى ${m['minimum_order_quantity'] ?? '-'} • المتاح ${m['available_quantity'] ?? 'حسب التوفر'}'),
              const SizedBox(height: 12),
              Row(children: [
                const _QtyStepper(step: 1),
                const SizedBox(width: 10),
                Expanded(child: FilledButton(style: FilledButton.styleFrom(backgroundColor: wholesale.primary), onPressed: () => widget.actionApi.addCartItem(storeId: int.parse(sid), productId: pid, quantity: (m['minimum_order_quantity'] as num?)?.toDouble() ?? 1), child: const Text('أضف إلى الطلب'))),
              ]),
              const SizedBox(height: 12),
              const _Accordion(title: 'تفاصيل المنتج'),
              const _Accordion(title: 'منتجات ذات صلة'),
            ],
          ),
        );
      },
    );
  }

  Widget _b2bCart() {
    final uri = Uri.parse(widget.location);
    final sid = uri.queryParameters['store'] ?? uri.queryParameters['store_id'];
    final endpoint = sid == null ? '/api/v1/cart' : '/api/v1/cart?store=$sid';
    return _b2bRemote(endpoint, (value) {
      final m = Map<String, dynamic>.from(value as Map);
      final items = (m['items'] as List? ?? const []).whereType<Map>().toList();
      return _shell(
        theme: wholesale,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const _TopBar(title: 'سلة الجملة'),
            const _InfoBand(text: 'متجر الجملة'),
            ...items.map((e) => _CartLine(row: Map<String, dynamic>.from(e))),
            Container(
              margin: const EdgeInsets.only(top: 10),
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: const Color(0xFFF5F0FA), borderRadius: BorderRadius.circular(16)),
              child: Column(children: [
                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [const Text('الإجمالي'), Text('EGP ${m['subtotal'] ?? 0}', style: const TextStyle(fontWeight: FontWeight.w800))]),
                const SizedBox(height: 12),
                SizedBox(width: double.infinity, child: FilledButton(style: FilledButton.styleFrom(backgroundColor: wholesale.primary), onPressed: () => Navigator.of(context).pushNamed(CustomerRoutePaths.b2bCheckout), child: const Text('إتمام الطلب'))),
              ]),
            ),
          ],
        ),
      );
    });
  }

  Widget _b2bCheckout() => _shell(
        theme: wholesale,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const _TopBar(title: 'إتمام الطلب'),
            const _CheckoutStepper(),
            const _ChoiceCard(title: 'المخزن الرئيسي - السالمية', subtitle: 'عنوان التوصيل المعتمد', selected: true),
            const _ChoiceCard(title: 'فرع حولي', subtitle: 'عنوان بديل', selected: false),
            const _ChoiceCard(title: 'تاريخ التوصيل المتوقع', subtitle: 'الخميس 1 أكتوبر 2026 • 10:00 ص - 2:00 م', selected: true, icon: Icons.calendar_month_outlined),
            TextField(maxLines: 3, decoration: InputDecoration(hintText: 'أضف أي ملاحظات للطلب...', border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)))),
            const SizedBox(height: 18),
            SizedBox(height: 50, child: FilledButton(style: FilledButton.styleFrom(backgroundColor: wholesale.primary), onPressed: () {}, child: const Text('متابعة الدفع'))),
          ],
        ),
      );

  Widget _b2bOrders() => _b2bRemote('/api/v1/b2b/orders', (value) {
        final rows = _rows(value);
        return _shell(
          theme: wholesale,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              const _TopBar(title: 'طلباتي'),
              const _OrderTabs(),
              ...rows.map((e) => _OrderCard(row: e)),
            ],
          ),
        );
      });

  Widget _b2bRemote(String endpoint, Widget Function(Object value) builder) {
    final api = widget.b2bApi;
    if (api == null) return _shell(theme: wholesale, child: _ErrorState(onRetry: () => Navigator.of(context).pushReplacementNamed(CustomerRoutePaths.b2bLogin)));
    return FutureBuilder<Object?>(
      future: api.get(endpoint),
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) return _shell(theme: wholesale, child: const _LoadingSkeleton());
        if (snapshot.hasError || snapshot.data == null) return _shell(theme: wholesale, child: _ErrorState(onRetry: () => setState(() {})));
        return builder(snapshot.data!);
      },
    );
  }

  List<Map<String, dynamic>> _rows(Object? value) {
    if (value is Map && value['data'] is List) return (value['data'] as List).whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
    if (value is List) return value.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
    return const [];
  }
}

class _StoreTheme {
  const _StoreTheme(this.primary, this.dark, this.accent, this.background);
  final Color primary;
  final Color dark;
  final Color accent;
  final Color background;
}

class _RetailHomeData {
  const _RetailHomeData(this.store, this.categories, this.products, this.banners);
  final B2cStore store;
  final List<B2cCategory> categories;
  final List<B2cProduct> products;
  final List<B2cBanner> banners;
}

class _FoodexBrand extends StatelessWidget {
  const _FoodexBrand();
  @override
  Widget build(BuildContext context) => Row(mainAxisAlignment: MainAxisAlignment.center, children: const [Icon(Icons.shopping_basket_rounded, color: Color(0xFF078A43), size: 30), SizedBox(width: 8), Text('FOODEX', style: TextStyle(fontWeight: FontWeight.w900, color: Color(0xFF006736), fontSize: 20))]);
}

class _FilterPills extends StatelessWidget {
  const _FilterPills();
  @override
  Widget build(BuildContext context) => Container(height: 44, padding: const EdgeInsets.all(4), decoration: BoxDecoration(color: const Color(0xFFF1F4F6), borderRadius: BorderRadius.circular(999)), child: Row(children: const [Expanded(child: _Pill('الكل', true)), Expanded(child: _Pill('تجزئة', false)), Expanded(child: _Pill('جملة', false))]));
}
class _Pill extends StatelessWidget { const _Pill(this.text, this.active); final String text; final bool active; @override Widget build(BuildContext context)=>Container(alignment: Alignment.center, decoration: BoxDecoration(color: active?Colors.white:Colors.transparent,borderRadius:BorderRadius.circular(999)), child:Text(text,style:TextStyle(fontWeight:FontWeight.w700,color:active?const Color(0xFF078A43):const Color(0xFF6B7785))));}

class _StoreCard extends StatelessWidget {
  const _StoreCard({required this.store, required this.theme, required this.onTap});
  final B2cStore store; final _StoreTheme theme; final VoidCallback onTap;
  @override Widget build(BuildContext context)=>InkWell(onTap:onTap,borderRadius:BorderRadius.circular(18),child:Container(minHeight:112,padding:const EdgeInsets.all(12),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(18),border:Border.all(color:theme.primary.withValues(alpha:.35))),child:Row(children:[Container(width:82,height:82,decoration:BoxDecoration(color:theme.background,borderRadius:BorderRadius.circular(14)),child:Icon(Icons.storefront_rounded,color:theme.primary,size:40)),const SizedBox(width:12),Expanded(child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(store.name,style:const TextStyle(fontWeight:FontWeight.w800,fontSize:16)),const SizedBox(height:5),Text(store.code,style:const TextStyle(fontSize:12,color:Color(0xFF6B7785))),const SizedBox(height:8),Text('متاح الآن • توصيل سريع',style:TextStyle(fontSize:11,color:theme.primary,fontWeight:FontWeight.w700))]))])));
}

class _WholesaleEntryCard extends StatelessWidget { const _WholesaleEntryCard({required this.onTap}); final VoidCallback onTap; @override Widget build(BuildContext context)=>InkWell(onTap:onTap,borderRadius:BorderRadius.circular(18),child:Container(padding:const EdgeInsets.all(14),decoration:BoxDecoration(color:const Color(0xFFF5F0FA),borderRadius:BorderRadius.circular(18),border:Border.all(color:const Color(0xFFB983F0))),child:const Row(children:[CircleAvatar(backgroundColor:Color(0xFF5D2A91),child:Text('B2B',style:TextStyle(color:Colors.white,fontSize:10,fontWeight:FontWeight.w800))),SizedBox(width:12),Expanded(child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text('متجر الجملة',style:TextStyle(fontWeight:FontWeight.w800,fontSize:16)),Text('للمسؤولين والحسابات المعتمدة فقط',style:TextStyle(fontSize:11,color:Color(0xFF6F6A7D)))])),Icon(Icons.chevron_left_rounded)])));}

class _RetailHeader extends StatelessWidget { const _RetailHeader({required this.store,required this.theme}); final B2cStore store; final _StoreTheme theme; @override Widget build(BuildContext context)=>Container(padding:const EdgeInsets.fromLTRB(14,10,14,12),decoration:BoxDecoration(color:theme.dark),child:Row(children:[const CircleAvatar(backgroundColor:Colors.white,child:Icon(Icons.person_outline)),const SizedBox(width:10),Expanded(child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(store.name,style:const TextStyle(color:Colors.white,fontWeight:FontWeight.w800)),const Text('التوصيل إلى منزلك',style:TextStyle(color:Colors.white70,fontSize:11))])),const Icon(Icons.shopping_cart_outlined,color:Colors.white),const SizedBox(width:14),const Icon(Icons.notifications_none_rounded,color:Colors.white)]));}

class _SearchBox extends StatelessWidget { const _SearchBox({required this.theme,required this.hint}); final _StoreTheme theme; final String hint; @override Widget build(BuildContext context)=>Padding(padding:const EdgeInsets.fromLTRB(12,10,12,8),child:TextField(decoration:InputDecoration(prefixIcon:Icon(Icons.search,color:theme.primary),hintText:hint,filled:true,fillColor:Colors.white,contentPadding:const EdgeInsets.symmetric(vertical:0),border:OutlineInputBorder(borderRadius:BorderRadius.circular(14),borderSide:BorderSide.none))));}

class _HeroBanner extends StatelessWidget { const _HeroBanner({required this.banner,required this.theme}); final B2cBanner? banner; final _StoreTheme theme; @override Widget build(BuildContext context)=>Container(height:124,margin:const EdgeInsets.fromLTRB(12,0,12,8),clipBehavior:Clip.antiAlias,decoration:BoxDecoration(borderRadius:BorderRadius.circular(18),gradient:LinearGradient(colors:[theme.dark,theme.primary])),child:banner?.imageUrl!=null?Image.network(banner!.imageUrl!,fit:BoxFit.cover,errorBuilder:(_,__,___)=>_heroFallback()):_heroFallback()); Widget _heroFallback()=>Padding(padding:const EdgeInsets.all(18),child:Column(crossAxisAlignment:CrossAxisAlignment.start,mainAxisAlignment:MainAxisAlignment.center,children:const [Text('اختيارات طازجة\nبأفضل الأسعار',style:TextStyle(color:Colors.white,fontSize:20,fontWeight:FontWeight.w900)),SizedBox(height:8),Text('تسوق الآن',style:TextStyle(color:Colors.white,fontWeight:FontWeight.w700))]));}

class _CategoryRail extends StatelessWidget { const _CategoryRail({required this.categories,required this.theme,required this.storeId}); final List<B2cCategory> categories; final _StoreTheme theme; final int storeId; @override Widget build(BuildContext context)=>SizedBox(height:122,child:ListView.separated(scrollDirection:Axis.horizontal,padding:const EdgeInsets.symmetric(horizontal:12,vertical:8),itemCount:categories.length,separatorBuilder:(_,__)=>const SizedBox(width:12),itemBuilder:(context,i){final c=categories[i];return SizedBox(width:66,child:Column(children:[CircleAvatar(radius:29,backgroundColor:theme.primary.withValues(alpha:.10),backgroundImage:c.imageUrl==null?null:NetworkImage(c.imageUrl!),child:c.imageUrl==null?Icon(Icons.category_outlined,color:theme.primary):null),const SizedBox(height:5),Text(c.name,maxLines:2,textAlign:TextAlign.center,style:const TextStyle(fontSize:10,fontWeight:FontWeight.w700))]));}));}

class _SectionHeader extends StatelessWidget { const _SectionHeader({required this.title,required this.theme}); final String title; final _StoreTheme theme; @override Widget build(BuildContext context)=>Padding(padding:const EdgeInsets.fromLTRB(12,10,12,8),child:Row(mainAxisAlignment:MainAxisAlignment.spaceBetween,children:[Text(title,style:const TextStyle(fontSize:16,fontWeight:FontWeight.w900)),Text('الكل',style:TextStyle(color:theme.primary,fontWeight:FontWeight.w700,fontSize:12))]));}

class _RetailProductCard extends StatelessWidget { const _RetailProductCard({required this.product,required this.theme,required this.onTap}); final B2cProduct product; final _StoreTheme theme; final VoidCallback onTap; @override Widget build(BuildContext context)=>InkWell(onTap:onTap,borderRadius:BorderRadius.circular(14),child:Container(padding:const EdgeInsets.all(8),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(14),border:Border.all(color:const Color(0xFFE8EDF0))),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Expanded(child:_ProductImage(url:product.imageUrl,compact:true)),Text(product.name,maxLines:2,overflow:TextOverflow.ellipsis,style:const TextStyle(fontSize:11,fontWeight:FontWeight.w800)),const SizedBox(height:4),Text('EGP ${product.price?.toStringAsFixed(0)??'-'}',style:TextStyle(color:theme.primary,fontWeight:FontWeight.w900,fontSize:12)),const SizedBox(height:4),SizedBox(width:double.infinity,height:32,child:FilledButton(style:FilledButton.styleFrom(backgroundColor:theme.primary,padding:EdgeInsets.zero),onPressed:onTap,child:const Icon(Icons.add,size:18))) ])));}

class _BottomNav extends StatelessWidget { const _BottomNav({required this.theme,required this.wholesale}); final _StoreTheme theme; final bool wholesale; @override Widget build(BuildContext context)=>SafeArea(top:false,child:Container(height:72,decoration:const BoxDecoration(color:Colors.white,boxShadow:[BoxShadow(blurRadius:12,color:Color(0x18000000))]),child:Row(mainAxisAlignment:MainAxisAlignment.spaceAround,children:[_Nav(Icons.home_rounded,'الرئيسية',theme.primary,true),_Nav(Icons.grid_view_rounded,'الأقسام',theme.primary,false),_Nav(wholesale?Icons.inventory_2_outlined:Icons.favorite_border,'الطلبات',theme.primary,false),_Nav(Icons.shopping_bag_outlined,'السلة',theme.primary,false),_Nav(Icons.person_outline,'حسابي',theme.primary,false)])));}

class _Nav extends StatelessWidget { const _Nav(this.icon,this.label,this.color,this.active); final IconData icon;final String label;final Color color;final bool active;@override Widget build(BuildContext context)=>Column(mainAxisAlignment:MainAxisAlignment.center,children:[Icon(icon,color:active?color:const Color(0xFF89939D),size:21),const SizedBox(height:3),Text(label,style:TextStyle(fontSize:9,fontWeight:active?FontWeight.w800:FontWeight.w500,color:active?color:const Color(0xFF89939D))) ]);}

class _TopBar extends StatelessWidget { const _TopBar({required this.title}); final String title; @override Widget build(BuildContext context)=>SizedBox(height:52,child:Row(children:[IconButton(onPressed:()=>Navigator.of(context).maybePop(),icon:const Icon(Icons.chevron_right_rounded)),Expanded(child:Text(title,textAlign:TextAlign.center,style:const TextStyle(fontSize:18,fontWeight:FontWeight.w900))),const SizedBox(width:48)]));}
class _ProductImage extends StatelessWidget { const _ProductImage({this.url,this.compact=false}); final String? url;final bool compact;@override Widget build(BuildContext context)=>Container(height:compact?null:180,alignment:Alignment.center,decoration:BoxDecoration(color:const Color(0xFFF5F7F8),borderRadius:BorderRadius.circular(16)),child:url==null||url!.isEmpty?Icon(Icons.inventory_2_outlined,size:compact?36:72,color:const Color(0xFF9AA5AF)):Image.network(url!,fit:BoxFit.contain,errorBuilder:(_,__,___)=>Icon(Icons.inventory_2_outlined,size:compact?36:72,color:const Color(0xFF9AA5AF))));}
class _QtyStepper extends StatelessWidget { const _QtyStepper({this.step=1});final int step;@override Widget build(BuildContext context)=>Container(height:44,decoration:BoxDecoration(border:Border.all(color:const Color(0xFFDDE3E7)),borderRadius:BorderRadius.circular(12)),child:Row(mainAxisSize:MainAxisSize.min,children:[IconButton(onPressed:(){},icon:const Icon(Icons.add,size:18)),Text('$step',style:const TextStyle(fontWeight:FontWeight.w800)),IconButton(onPressed:(){},icon:const Icon(Icons.remove,size:18))]));}
class _Accordion extends StatelessWidget { const _Accordion({required this.title});final String title;@override Widget build(BuildContext context)=>Container(margin:const EdgeInsets.only(bottom:8),decoration:BoxDecoration(color:Colors.white,border:Border.all(color:const Color(0xFFE5E9ED)),borderRadius:BorderRadius.circular(12)),child:ListTile(title:Text(title,style:const TextStyle(fontWeight:FontWeight.w700)),trailing:const Icon(Icons.keyboard_arrow_down_rounded)));}

class _B2bHeader extends StatelessWidget { const _B2bHeader();@override Widget build(BuildContext context)=>Container(padding:const EdgeInsets.all(12),decoration:const BoxDecoration(color:Color(0xFF35195E),borderRadius:BorderRadius.vertical(bottom:Radius.circular(18))),child:const Row(children:[CircleAvatar(backgroundColor:Colors.white,child:Icon(Icons.business_center_outlined,color:Color(0xFF5D2A91))),SizedBox(width:10),Expanded(child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text('متجر الجملة',style:TextStyle(color:Colors.white,fontWeight:FontWeight.w900)),Text('أسعار خاصة للحساب المعتمد',style:TextStyle(color:Colors.white70,fontSize:11))])),Icon(Icons.shopping_cart_outlined,color:Colors.white),SizedBox(width:12),Icon(Icons.notifications_none_rounded,color:Colors.white)]));}
class _WholesaleHero extends StatelessWidget { const _WholesaleHero();@override Widget build(BuildContext context)=>Container(height:128,margin:const EdgeInsets.symmetric(vertical:8),padding:const EdgeInsets.all(16),decoration:BoxDecoration(borderRadius:BorderRadius.circular(18),gradient:const LinearGradient(colors:[Color(0xFF35195E),Color(0xFF5D2A91)])),child:const Column(crossAxisAlignment:CrossAxisAlignment.start,mainAxisAlignment:MainAxisAlignment.center,children:[Text('أفضل أسعار\nلتجار التجزئة',style:TextStyle(color:Colors.white,fontSize:22,fontWeight:FontWeight.w900)),SizedBox(height:6),Text('توصيل سريع وكميات جملة',style:TextStyle(color:Colors.white70,fontSize:11))]));}
class _B2bCategoryStrip extends StatelessWidget { const _B2bCategoryStrip();@override Widget build(BuildContext context)=>SizedBox(height:86,child:ListView(scrollDirection:Axis.horizontal,children:const [_B2bCat(Icons.local_drink_outlined,'مشروبات'),_B2bCat(Icons.rice_bowl_outlined,'بقوليات'),_B2bCat(Icons.cleaning_services_outlined,'منظفات'),_B2bCat(Icons.local_grocery_store_outlined,'أغذية') ]));}
class _B2bCat extends StatelessWidget { const _B2bCat(this.icon,this.label);final IconData icon;final String label;@override Widget build(BuildContext context)=>SizedBox(width:78,child:Column(children:[CircleAvatar(radius:26,backgroundColor:const Color(0xFFF5F0FA),child:Icon(icon,color:const Color(0xFF5D2A91))),const SizedBox(height:5),Text(label,style:const TextStyle(fontSize:10,fontWeight:FontWeight.w700))]));}
class _WholesaleProductCard extends StatelessWidget { const _WholesaleProductCard({required this.row,required this.onTap});final Map<String,dynamic> row;final VoidCallback onTap;@override Widget build(BuildContext context)=>InkWell(onTap:onTap,borderRadius:BorderRadius.circular(14),child:Container(padding:const EdgeInsets.all(9),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(14),border:Border.all(color:const Color(0xFFE7E1ED))),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Expanded(child:_ProductImage(url:row['image_url']?.toString(),compact:true)),Text(row['name']?.toString()??'',maxLines:2,overflow:TextOverflow.ellipsis,style:const TextStyle(fontWeight:FontWeight.w800,fontSize:12)),Text('SKU ${row['sku']??''}',style:const TextStyle(fontSize:9,color:Color(0xFF6F6A7D))),Text('EGP ${row['unit_price']??row['account_price']??'-'}',style:const TextStyle(fontWeight:FontWeight.w900,color:Color(0xFF5D2A91))),Text('الحد الأدنى ${row['minimum_quantity']??row['minimum_order_quantity']??1}',style:const TextStyle(fontSize:9,color:Color(0xFF6F6A7D))),const SizedBox(height:3),const _QtyStepper()])));}
class _PriceTable extends StatelessWidget { const _PriceTable({required this.row}); final Map<String,dynamic> row; @override Widget build(BuildContext context)=>Container(padding:const EdgeInsets.all(12),decoration:BoxDecoration(color:const Color(0xFFF5F0FA),borderRadius:BorderRadius.circular(14)),child:Column(children:[_kv('سعر الجملة الأساسي','EGP ${row['base_price']??row['account_price']??'-'}'),_kv('سعر العميل','EGP ${row['account_price']??'-'}'),_kv('السعر المرجعي للتجزئة','EGP ${row['retail_reference_price']??'-'}')])); Widget _kv(String a,String b)=>Padding(padding:const EdgeInsets.symmetric(vertical:4),child:Row(mainAxisAlignment:MainAxisAlignment.spaceBetween,children:[Text(a),Text(b,style:const TextStyle(fontWeight:FontWeight.w800))]));}
class _InfoBand extends StatelessWidget { const _InfoBand({required this.text});final String text;@override Widget build(BuildContext context)=>Container(padding:const EdgeInsets.all(12),decoration:BoxDecoration(color:const Color(0xFFF5F0FA),borderRadius:BorderRadius.circular(12)),child:Text(text,style:const TextStyle(fontWeight:FontWeight.w700,color:Color(0xFF5D2A91))));}
class _CartLine extends StatelessWidget { const _CartLine({required this.row});final Map<String,dynamic> row;@override Widget build(BuildContext context){final p=Map<String,dynamic>.from(row['product'] as Map? ?? const {});return Container(margin:const EdgeInsets.only(top:8),padding:const EdgeInsets.all(12),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(14),border:Border.all(color:const Color(0xFFE8E3EC))),child:Row(children:[const SizedBox(width:64,height:64,child:_ProductImage(compact:true)),const SizedBox(width:10),Expanded(child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(p['name']?.toString()??'',style:const TextStyle(fontWeight:FontWeight.w800)),Text('EGP ${row['unit_price_snapshot']??'-'}',style:const TextStyle(color:Color(0xFF128B49),fontWeight:FontWeight.w800))])),Text('× ${row['quantity']??1}',style:const TextStyle(fontWeight:FontWeight.w800))]));}}
class _CheckoutStepper extends StatelessWidget { const _CheckoutStepper(); @override Widget build(BuildContext context)=>Padding(padding:const EdgeInsets.symmetric(vertical:10),child:Row(children:const [Expanded(child:_StepDot('1','العنوان',true)),Expanded(child:_StepDot('2','الدفع',false)),Expanded(child:_StepDot('3','التأكيد',false))]));}
class _StepDot extends StatelessWidget { const _StepDot(this.n,this.label,this.active);final String n,label;final bool active;@override Widget build(BuildContext context)=>Column(children:[CircleAvatar(radius:13,backgroundColor:active?const Color(0xFF5D2A91):const Color(0xFFE4E0E8),child:Text(n,style:TextStyle(fontSize:10,color:active?Colors.white:const Color(0xFF6F6A7D),fontWeight:FontWeight.w800))),const SizedBox(height:4),Text(label,style:const TextStyle(fontSize:9))]);}
class _ChoiceCard extends StatelessWidget { const _ChoiceCard({required this.title,required this.subtitle,required this.selected,this.icon=Icons.location_on_outlined});final String title,subtitle;final bool selected;final IconData icon;@override Widget build(BuildContext context)=>Container(margin:const EdgeInsets.only(bottom:10),padding:const EdgeInsets.all(12),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(14),border:Border.all(color:selected?const Color(0xFF5D2A91):const Color(0xFFE4E0E8))),child:Row(children:[Icon(icon,color:const Color(0xFF5D2A91)),const SizedBox(width:10),Expanded(child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(title,style:const TextStyle(fontWeight:FontWeight.w800)),Text(subtitle,style:const TextStyle(fontSize:10,color:Color(0xFF6F6A7D)))])),Icon(selected?Icons.radio_button_checked:Icons.radio_button_off,color:const Color(0xFF5D2A91))]));}
class _OrderTabs extends StatelessWidget { const _OrderTabs();@override Widget build(BuildContext context)=>Container(height:42,margin:const EdgeInsets.symmetric(vertical:10),decoration:BoxDecoration(color:const Color(0xFFF1EFF4),borderRadius:BorderRadius.circular(999)),child:Row(children:const [Expanded(child:_Pill('الكل',true)),Expanded(child:_Pill('قيد التجهيز',false)),Expanded(child:_Pill('تم التوصيل',false))]));}
class _OrderCard extends StatelessWidget { const _OrderCard({required this.row});final Map<String,dynamic> row;@override Widget build(BuildContext context)=>Container(margin:const EdgeInsets.only(bottom:10),padding:const EdgeInsets.all(12),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(14),border:Border.all(color:const Color(0xFFE6E1EA))),child:Row(children:[const Icon(Icons.inventory_2_outlined,color:Color(0xFF5D2A91),size:34),const SizedBox(width:10),Expanded(child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text('#${row['order_number']??row['id']??''}',style:const TextStyle(fontWeight:FontWeight.w900)),Text(row['created_at']?.toString()??'',style:const TextStyle(fontSize:10,color:Color(0xFF6F6A7D))),Text('EGP ${row['grand_total']??row['total']??'-'}',style:const TextStyle(color:Color(0xFF128B49),fontWeight:FontWeight.w800))])),Text(row['status']?.toString()??'',style:const TextStyle(fontSize:10,color:Color(0xFF5D2A91),fontWeight:FontWeight.w800)),const Icon(Icons.chevron_left_rounded)]));}
class _LoadingSkeleton extends StatelessWidget { const _LoadingSkeleton();@override Widget build(BuildContext context)=>ListView(padding:const EdgeInsets.all(16),children:List.generate(7,(i)=>Container(height:i==2?120:64,margin:const EdgeInsets.only(bottom:10),decoration:BoxDecoration(color:const Color(0xFFEDEFF1),borderRadius:BorderRadius.circular(14)))));}
class _ErrorState extends StatelessWidget { const _ErrorState({required this.onRetry});final VoidCallback onRetry;@override Widget build(BuildContext context)=>Center(child:Padding(padding:const EdgeInsets.all(24),child:Column(mainAxisSize:MainAxisSize.min,children:[const Icon(Icons.error_outline_rounded,size:44,color:Color(0xFFDC4C4C)),const SizedBox(height:8),const Text('تعذر تحميل البيانات',style:TextStyle(fontWeight:FontWeight.w800)),const SizedBox(height:10),FilledButton(onPressed:onRetry,child:const Text('إعادة المحاولة'))])));}
extension _LastOrNull<T> on List<T> { T? get lastOrNull => isEmpty ? null : last; }
