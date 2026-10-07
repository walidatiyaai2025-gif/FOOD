# FOODEX Trial Distribution

هذا المجلد هو نقطة التسليم العملية لنسخة التجربة من FOODEX.

بعد نجاح **FOODEX Trial Distribution Bundle** ثم دمج فرع التوليد، سيحتوي المجلد على:

- `FOODEX-Customer-<VERSION>.apk` — تطبيق العملاء Android باسم واضح يحتوي رقم الإصدار.
- `FOODEX-Driver-<VERSION>.apk` — تطبيق السائقين Android باسم واضح يحتوي رقم الإصدار.
- `FOODEX-Van-<VERSION>.apk` — تطبيق الفان Android باسم واضح يحتوي رقم الإصدار.
- `FOODEX-Customer.apk` / `FOODEX-Driver.apk` / `FOODEX-Van.apk` — Latest aliases للتوافق، وتطابق ملفات الإصدار الحالي.
- `LATEST_RELEASE.json` — فهرس مباشر لآخر Release: الإصدار، build، commit، أسماء الـAPKs، الحجم وSHA-256.
- `FOODEX-Laravel-Setup.zip` — حزمة أول Setup للموقع.
- `BUILD_INFO.json` — الإصدار، commit، endpoint و SHA-256 لكل ملف.
- `Updates/` — حزمة تحديث الـDashboard التي ترفع من Dashboard > System Update.

## Android tester builds

الـ APKs مبنية Release mode ومربوطة افتراضيًا على:

`https://vanfoodex.50sols.com`

الهويات الأصلية:

- Customer: `com.fiftysolution.foodex.customer`
- Driver: `com.fiftysolution.foodex.driver`
- Van: `com.foodex.van`

حتى يتم توفير Android production keystore، النسخ الموجودة هنا **للتجربة/القبول على الأجهزة وليست للنشر على Google Play**.

إذا كانت متغيرات Firebase العامة غير مضبوطة في GitHub Repository Variables، يعمل التطبيق عاديًا لكن FCM يبقى غير مفعّل في ذلك الـbuild. لا يتم وضع أي Firebase service-account secret داخل هذه الحزمة.

## أول Setup للموقع

1. فك `FOODEX-Laravel-Setup.zip` على السيرفر.
2. يجب أن يكون `VERSION` و`backend/` في نفس Deployment Root.
3. اجعل Document Root للدومين يشير إلى `backend/public`.
4. المطلوب PHP 8.2+ مع `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `json`, `zip`.
5. استخدم MySQL/MariaDB (المنفذ الافتراضي `3306`). القيم المعتمدة لأول تركيب:
   - Database: `solscool_vanfoodex`
   - Username: `solscool_vanfoodex`
   - Password: يتم إدخاله على السيرفر ولا يُحفظ في Git.
6. اجعل `backend/storage` و`backend/bootstrap/cache` قابلين للكتابة بواسطة مستخدم PHP/Web.
7. افتح `https://vanfoodex.50sols.com/install` وأكمل الـwizard حتى Finish.
8. بعد Finish يتم قفل `/install` تلقائيًا، وتتم الإدارة من الـDashboard.

الحزمة تتضمن Composer production dependencies لتسهيل أول Setup، لكنها لا تتضمن `.env` أو كلمات مرور أو مفاتيح signing.

## بعد أي تعديل مستقبلي

أي تغيير سيتم نشره يجب أن يصاحبه رفع `VERSION`، مثال `1.0.0 -> 1.0.1`. عند Release intent يقوم Workflow المركزي تلقائيًا ببناء **Customer + Driver + Van** وتحديث Dashboard package. لا يعتبر الـRelease مكتملًا إذا غاب أي APK من الثلاثة أو لم يتحدث `Release/Updates`.

أسماء الـAPKs القابلة للتحميل تكون Versioned دائمًا، مثال `FOODEX-Driver-1.0.59.apk`، ويظل alias مثل `FOODEX-Driver.apk` للإشارة إلى آخر نسخة متزامنة.

المرجع الإلزامي الكامل: `docs/release/RELEASE_ARTIFACT_CONTRACT.md`.

للموقع المركب بالفعل استخدم الملفات داخل `Updates/` وفق التعليمات هناك. إذا كان التغيير يضيف/يغير Composer dependencies فسيتم إيقاف Dashboard ZIP عمدًا، ويكون المطلوب Full Laravel redeploy باستخدام Setup ZIP الجديد.

## Refresh 1.0.34

تمت إعادة توليد حزمة FOODEX 1.0.34 من أحدث `main` بعد اكتمال بوابة Platform Customer Commerce E2E، لضمان تطابق APKs وLaravel Setup وBUILD_INFO مع آخر كود مدموج.
