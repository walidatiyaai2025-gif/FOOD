class VanNotificationRecord {
  const VanNotificationRecord({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.publishedAt,
    required this.read,
  });

  final int id;
  final String type;
  final String title;
  final String body;
  final String? publishedAt;
  final bool read;
}

abstract interface class VanNotificationRepository {
  Future<List<VanNotificationRecord>> notifications({required String locale});
  Future<void> markRead(int notificationId);
}
