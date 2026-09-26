# FOODEX Trial Distribution

هذا المجلد هو نقطة التسليم العملية لنسخة التجربة من FOODEX.

بعد نجاح **FOODEX Trial Distribution Bundle** ثم دمج فرع التوليد، سيحتوي المجلد على:

- `FOODEX-Customer.apk` — تطبيق العملاء Android للتجربة.
- `FOODEX-Driver.apk` — تطبيق السائقين Android للتجربة.
- `FOODEX-Laravel-Setup.zip` — حزمة أول Setup للموقع.
- `BUILD_INFO.json` — الإصدار، commit، endpoint و SHA-256 لكل ملف.
- `Updates/` — حزمة التحديث اللاحقة التي ترفع من Dashboard > System Update.

## Android tester builds

الـ APKs مبنية Release mode ومربوطة افتراضيًا على:

`https://foodex.50sols.com`

الهويات الأصلية:

- Customer: `com.fiftysolution.foodex.customer`
- Driver: `com.fiftysolution.foodex.driver`

حتى يتم توفير Android production keystore، النسخ الموجودة هنا **للتجربة/القبول على الأجهزة وليست للنشر على Google Play**.

إذا كانت متغيرات Firebase العامة غير مضبوطة في GitHub Repository Variables، يعمل التطبيق عاديًا لكن FCM يبقى غير مفعّل في ذلك الـbuild. لا يتم وضع أي Firebase service-account secret داخل هذه الحزمة.

## أول Setup للموقع

1. فك `FOODEX-Laravel-Setup.zip` على السيرفر.
2. يجب أن يكون `VERSION` و`backend/` في نفس Deployment Root.
3. اجعل Document Root للدومين يشير إلى `backend/public`.
4. المطلوب PHP 8.2+ مع `pdo_pgsql`, `mbstring`, `openssl`, `tokenizer`, `json`, `zip`.
5. استخدم PostgreSQL. القيم المعتمدة لأول تركيب:
   - Database: `solscool_foodex`
   - Username: `solscool_foodex`
   - Password: يتم إدخاله على السيرفر ولا يُحفظ في Git.
6. اجعل `backend/storage` و`backend/bootstrap/cache` قابلين للكتابة بواسطة مستخدم PHP/Web.
7. افتح `https://foodex.50sols.com/install` وأكمل الـwizard حتى Finish.
8. بعد Finish يتم قفل `/install` تلقائيًا، وتتم الإدارة من الـDashboard.

الحزمة تتضمن Composer production dependencies لتسهيل أول Setup، لكنها لا تتضمن `.env` أو كلمات مرور أو مفاتيح signing.

## بعد أي تعديل مستقبلي

أي تغيير سيتم نشره يجب أن يصاحبه رفع `VERSION`، مثال `1.0.0 -> 1.0.1`. بعد الدمج إلى `main` يقوم Workflow ببناء نسخة جديدة وينشرها في فرع التوليد. يتم دمج فرع التوليد بعد CI.

للموقع المركب بالفعل استخدم الملفات داخل `Updates/` وفق التعليمات هناك. إذا كان التغيير يضيف/يغير Composer dependencies فسيتم إيقاف Dashboard ZIP عمدًا، ويكون المطلوب Full Laravel redeploy باستخدام Setup ZIP الجديد.
