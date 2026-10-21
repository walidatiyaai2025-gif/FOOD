import 'package:flutter/material.dart';
import 'package:foodex_customer_app/shared/foodex_wave_loading.dart';

import '../core/api/b2c_account_api.dart';

class _FavoriteStoreState {
  final ValueNotifier<int> revision = ValueNotifier<int>(0);
  final Set<int> productIds = <int>{};
  bool loaded = false;
  Future<void>? loading;
}

final Map<String, _FavoriteStoreState> _favoriteStoreStates =
    <String, _FavoriteStoreState>{};

String _favoriteStoreKey(B2cRetailFavoritesApi api, int storeId) =>
    '${identityHashCode(api)}:$storeId';

class CustomerFavoriteButton extends StatefulWidget {
  const CustomerFavoriteButton({
    required this.api,
    required this.storeId,
    required this.productId,
    required this.isAuthenticated,
    required this.loginRoute,
    this.compactOverlay = false,
    this.inactiveColor = Colors.white,
    this.activeColor = const Color(0xFFE5484D),
    super.key,
  });

  final B2cRetailFavoritesApi? api;
  final int storeId;
  final int productId;
  final bool isAuthenticated;
  final String loginRoute;
  final bool compactOverlay;
  final Color inactiveColor;
  final Color activeColor;

  @override
  State<CustomerFavoriteButton> createState() => _CustomerFavoriteButtonState();
}

class _CustomerFavoriteButtonState extends State<CustomerFavoriteButton> {
  bool _favorite = false;
  bool _loading = false;
  bool _busy = false;
  _FavoriteStoreState? _sharedState;

  @override
  void initState() {
    super.initState();
    _bindSharedState();
    _load();
  }

  @override
  void didUpdateWidget(covariant CustomerFavoriteButton oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.storeId != widget.storeId ||
        oldWidget.productId != widget.productId ||
        oldWidget.isAuthenticated != widget.isAuthenticated) {
      _bindSharedState();
      _load();
    }
  }

  void _bindSharedState() {
    _sharedState?.revision.removeListener(_syncFromSharedState);
    final api = widget.api;
    if (api == null || widget.storeId <= 0) {
      _sharedState = null;
      return;
    }
    _sharedState = _favoriteStoreStates.putIfAbsent(
      _favoriteStoreKey(api, widget.storeId),
      _FavoriteStoreState.new,
    );
    _sharedState!.revision.addListener(_syncFromSharedState);
  }

  void _syncFromSharedState() {
    final shared = _sharedState;
    if (!mounted || shared == null) return;
    setState(() {
      _favorite = shared.productIds.contains(widget.productId);
      _loading = !shared.loaded && shared.loading != null;
    });
  }

  @override
  void dispose() {
    _sharedState?.revision.removeListener(_syncFromSharedState);
    super.dispose();
  }

  Future<void> _load() async {
    if (!widget.isAuthenticated ||
        widget.api == null ||
        widget.storeId <= 0 ||
        widget.productId <= 0) {
      if (mounted) {
        setState(() {
          _favorite = false;
          _loading = false;
        });
      }
      return;
    }

    final shared = _sharedState!;
    if (shared.loaded) {
      if (mounted) {
        setState(() {
          _favorite = shared.productIds.contains(widget.productId);
          _loading = false;
        });
      }
      return;
    }

    if (mounted) setState(() => _loading = true);

    Future<void> loadShared() async {
      final raw = await widget.api!.favoritesForStore(widget.storeId);
      final rows = raw is Map && raw['data'] is List
          ? (raw['data'] as List)
          : raw is List
              ? raw
              : const <Object?>[];
      shared.productIds
        ..clear()
        ..addAll(
          rows.whereType<Map>().map(
                (row) => int.tryParse(
                  (row['product'] is Map
                          ? (row['product'] as Map)['id']
                          : row['id'])
                      ?.toString() ??
                      '',
                ),
              ).whereType<int>(),
        );
      shared.loaded = true;
      shared.revision.value++;
    }

    try {
      shared.loading ??= loadShared();
      await shared.loading;
      if (mounted) {
        setState(() {
          _favorite = shared.productIds.contains(widget.productId);
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    } finally {
      shared.loading = null;
    }
  }

  Future<void> _toggle() async {
    if (!widget.isAuthenticated) {
      await Navigator.of(context).pushNamed(widget.loginRoute);
      return;
    }
    if (widget.api == null || _busy) return;

    setState(() => _busy = true);
    try {
      if (_favorite) {
        await widget.api!.removeFavoriteForStore(
          widget.storeId,
          widget.productId,
        );
      } else {
        await widget.api!.addFavoriteForStore(
          widget.storeId,
          widget.productId,
        );
      }

      final shared = _sharedState;
      if (shared != null) {
        if (_favorite) {
          shared.productIds.remove(widget.productId);
        } else {
          shared.productIds.add(widget.productId);
        }
        shared.loaded = true;
        shared.revision.value++;
      } else if (mounted) {
        setState(() => _favorite = !_favorite);
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذر تحديث المفضلة. حاول مرة أخرى.')),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _icon() => _loading || _busy
      ? SizedBox(
          width: widget.compactOverlay ? 14 : 20,
          height: widget.compactOverlay ? 14 : 20,
          child: const FoodexWaveLoading(),
        )
      : Icon(
          _favorite ? Icons.favorite_rounded : Icons.favorite_border_rounded,
          color: _favorite ? widget.activeColor : widget.inactiveColor,
          size: widget.compactOverlay ? 17 : 24,
        );

  @override
  Widget build(BuildContext context) {
    if (!widget.compactOverlay) {
      return IconButton(
        key: ValueKey('favorite-${widget.storeId}-${widget.productId}'),
        tooltip: _favorite ? 'إزالة من المفضلة' : 'إضافة إلى المفضلة',
        onPressed: _loading || _busy ? null : _toggle,
        icon: _icon(),
      );
    }

    return Material(
      key: ValueKey('favorite-${widget.storeId}-${widget.productId}'),
      color: Colors.black.withOpacity(.20),
      shape: const CircleBorder(),
      elevation: 1,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: _loading || _busy ? null : _toggle,
        child: SizedBox.square(
          dimension: 27,
          child: Center(child: _icon()),
        ),
      ),
    );
  }
}
