# FOODEX Dashboard Updates

هذا المجلد مخصص **لما بعد أول Setup**.

## الطريقة العادية بعد تعديل الكود

1. ارفع رقم `VERSION` قبل إصدار التعديل، مثال: `1.0.0 -> 1.0.1`.
2. ادمج التعديل إلى `main`.
3. Workflow باسم **FOODEX Trial Distribution Bundle** يبني حزمة التوزيع ويحدث فرع `release/221-generated-trial-bundle`.
4. بعد دمج فرع التوليد ستجد هنا:
   - `FOODEX-Update.zip`
   - `FOODEX-Update.json`
5. من Dashboard افتح **System Update**.
6. ارفع `FOODEX-Update.zip`.
7. افتح `FOODEX-Update.json` وانسخ القيم التالية كما هي إلى الشاشة:
   - `target_version`
   - `minimum_current_version`
   - `sha256`
   - `release_notes`
   - `contains_migrations`
8. اضغط **Validate and install update**.

الـUpdater يتحقق من SHA-256 والتوافق قبل أي تعديل، ثم يأخذ File backup وDatabase backup عند وجود migrations، ويدخل Maintenance Mode، ويطبق الملفات والمigrations، ويعيد بناء caches، ويجري Health Check، ثم يخرج من Maintenance Mode. في حالة الفشل يحاول Rollback.

## حدود الأمان

حزمة Dashboard Update لا تستهدف أبدًا:

- `.env`
- `vendor/`
- `storage/`
- `.git/`

إذا كان `FOODEX-Update.json` يحتوي:

`"requires_full_redeploy": true`

فلا تستخدم Dashboard Update. استخدم `../FOODEX-Laravel-Setup.zip` لعمل Full server deployment لأن Composer dependencies تغيرت.

عند وجود migrations على PostgreSQL يجب أن يكون `pg_dump` و`pg_restore` متاحين للسيرفر حتى يستطيع النظام عمل Backup/Rollback آمن.
