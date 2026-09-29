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


## Platform Customer commerce operational events — Wave K

Wave K (#406 / issues #407-#414) extends the existing Notifications Center and live Dashboard transport. It must **reuse** this subsystem rather than create another notification store.

Minimum live Dashboard events:
- order created;
- invoice issued;
- invoice reissued/revised;
- invoice voided;
- relevant payment status change;
- driver assigned/reassigned;
- driver accepted;
- picked up;
- out for delivery;
- delivered;
- failed;
- driver note added.

Every event must carry authoritative store/channel context and only enough identifiers for authorized deep links (order/invoice/assignment). Audience resolution remains server-side:
- Retail event -> that exact Retail store's authorized Dashboard users.
- Wholesale event -> authorized Wholesale users.
- SUPER_ADMIN visibility follows explicit platform permissions.
- Reconnect/retry must suppress duplicate logical events/unread rows.

Arabic and English copy are mandatory. Event payloads must never expose another tenant's customer/order/invoice data.
