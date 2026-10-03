import 'package:flutter/material.dart';

import '../core/api/b2c_account_api.dart';

class CustomerFavoriteButton extends StatefulWidget {
  const CustomerFavoriteButton({
    required this.api,
    required this.storeId,
    required this.productId,
    required this.isAuthenticated,
    required this.loginRoute,
    super.key,
  });

  final B2cRetailFavoritesApi? api;
  final int storeId;
  final int productId;
  final bool isAuthenticated;
  final String loginRoute;

  @override
  State<CustomerFavoriteButton> createState() => _CustomerFavoriteButtonState();
}

class _CustomerFavoriteButtonState extends State<CustomerFavoriteButton> {
  bool _favorite = false;
  bool _loading = false;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant CustomerFavoriteButton oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.storeId != widget.storeId ||
        oldWidget.productId != widget.productId ||
        oldWidget.isAuthenticated != widget.isAuthenticated) {
      _load();
    }
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

    setState(() => _loading = true);
    try {
      final raw = await widget.api!.favoritesForStore(widget.storeId);
      final rows = raw is Map && raw['data'] is List
          ? (raw['data'] as List)
          : raw is List
              ? raw
              : const <Object?>[];
      final favorite = rows.whereType<Map>().any((row) {
        final id = int.tryParse(row['id']?.toString() ?? '');
        return id == widget.productId;
      });
      if (mounted) {
        setState(() {
          _favorite = favorite;
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
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
      if (mounted) setState(() => _favorite = !_favorite);
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذر تحديث المفضلة. حاول مرة أخرى.')),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => IconButton(
        key: ValueKey('favorite-${widget.storeId}-${widget.productId}'),
        tooltip: _favorite ? 'إزالة من المفضلة' : 'إضافة إلى المفضلة',
        onPressed: _loading || _busy ? null : _toggle,
        icon: _loading
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Icon(
                _favorite
                    ? Icons.favorite_rounded
                    : Icons.favorite_border_rounded,
                color: _favorite ? const Color(0xFFE5484D) : null,
              ),
      );
}
