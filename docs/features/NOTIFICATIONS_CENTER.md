# FOODEX Notifications Center

Issue #93 owns bilingual notification content and audience administration.

## Admin
Authorized administrators can search and page notifications, create and edit Arabic/English title and body, target all users/customers/drivers/a specific user, choose Customer/Driver app scope, B2C/B2B business channel, in-app/push/both delivery intent, publish drafts, and delete notifications. All mutations are audited.

Push provider credentials and device delivery configuration remain owned by #98. A notification marked for push is content ready for that provider layer; #93 does not store provider secrets.

## User APIs
Authenticated users can list visible published notifications, fetch one visible notification, and mark it read. Locale is selected by `locale=ar|en` with the user locale as fallback. Broad notifications use per-user `notification_reads` records so one user's read state never changes another user's state.

Audience checks combine user target, Customer/Driver app identity and B2C/B2B channel. Non-visible notifications return 404 for detail/read operations.

## Localization
The Notifications Center labels live in `lang/ar/notifications.php` and `lang/en/notifications.php`. `TranslationCatalog` registers the group, so those strings appear in the existing Translation Center alongside Admin, Customer and Driver strings.
