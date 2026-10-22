class Restaurant {
  const Restaurant({
    required this.id,
    required this.name,
    required this.cuisine,
    required this.imageUrl,
    required this.logoUrl,
    required this.rating,
    required this.reviewCount,
    required this.deliveryMinutes,
    required this.deliveryFee,
    required this.minimumOrder,
    this.isOpen = true,
    this.isFavorite = false,
  });

  final int id;
  final String name;
  final String cuisine;
  final String imageUrl;
  final String logoUrl;
  final double rating;
  final int reviewCount;
  final String deliveryMinutes;
  final double deliveryFee;
  final double minimumOrder;
  final bool isOpen;
  final bool isFavorite;
}

class MenuItem {
  const MenuItem({
    required this.id,
    required this.restaurantId,
    required this.name,
    required this.description,
    required this.imageUrl,
    required this.price,
    required this.category,
    this.isBestSeller = false,
  });

  final int id;
  final int restaurantId;
  final String name;
  final String description;
  final String imageUrl;
  final double price;
  final String category;
  final bool isBestSeller;
}

class CartLine {
  CartLine({required this.item, this.quantity = 1, this.size = 'عادي', this.drink = 'بدون مشروب'});

  final MenuItem item;
  int quantity;
  String size;
  String drink;

  double get total => item.price * quantity + (size == 'كبير' ? 25 : size == 'جامبو' ? 40 : 0);
}

class OrderSummary {
  const OrderSummary({
    required this.number,
    required this.restaurant,
    required this.total,
    required this.status,
    required this.date,
  });

  final String number;
  final String restaurant;
  final double total;
  final String status;
  final String date;
}
