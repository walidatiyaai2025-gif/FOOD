# FOODEX Design Reference Index

Authoritative source archive: `Foodex_Design_Reference_Laravel_Flutter (1).zip`  
SHA-256: `bfc481ead664c3b683fb84be45fabc3108394154b9326d27ef3c58cee5171f45`

Inspected reference screens: **46** (25 Flutter mobile, 21 Laravel web). Screenshots are reference-only and must be rebuilt as responsive UI; Arabic RTL is primary.

## RC-03 evidence status

Reconciled on main baseline `c09910cfec643333e7ca721090fe0e54d659c2fb` by #123. "Functional verified" means a real route/runtime interaction is present and covered by current code/tests; it does **not** assert pixel parity. The original 46-screen ZIP named above is not stored in this repository and was not available from the accessible file library during the audit, so exact per-row visual comparison remains unverified except the premium B2C dashboard, which has its own checked-in PH-05 visual contract and #116 acceptance. Gaps/partial shells point to atomic remediation issues.

| # | Role | Platform | Screen | Source path | Size | Expected route | Module | Related API | Status |
|---:|---|---|---|---|---|---|---|---|---|
| 1 | B2B Customer | Flutter iOS/Android | شاشة_الدخول | 01_Mobile/B2B_Customer/01_شاشة_الدخول.png | 259x525 | /b2b/login | auth | POST /api/v1/auth/login | Functional login verified; visual source unavailable |
| 2 | B2B Customer | Flutter iOS/Android | الصفحة_الرئيسية_Dashboard | 01_Mobile/B2B_Customer/02_الصفحة_الرئيسية_Dashboard.png | 282x513 | /b2b/dashboard | b2b | GET /api/v1/b2b/dashboard | Partial data rendering → #151 |
| 3 | B2B Customer | Flutter iOS/Android | تقارير_المشتريات_والرسوم_البيانية | 01_Mobile/B2B_Customer/03_تقارير_المشتريات_والرسوم_البيانية.png | 260x513 | /b2b/reports/purchases | reports | GET /api/v1/b2b/reports/purchases | Partial data rendering → #151 |
| 4 | B2B Customer | Flutter iOS/Android | أكثر_المنتجات_طلبا | 01_Mobile/B2B_Customer/04_أكثر_المنتجات_طلبا.png | 259x513 | /b2b/products/top | catalog | GET /api/v1/b2b/products | Gap → #142 + #151 |
| 5 | B2B Customer | Flutter iOS/Android | آخر_الفواتير | 01_Mobile/B2B_Customer/05_آخر_الفواتير.png | 265x465 | /b2b/invoices | b2b | GET /api/v1/b2b/invoices | Partial data rendering → #151 |
| 6 | B2B Customer | Flutter iOS/Android | كشف_الحساب_والمعاملات | 01_Mobile/B2B_Customer/06_كشف_الحساب_والمعاملات.png | 271x513 | /b2b/account-statement | b2b | GET /api/v1/b2b/account-statement | Partial data rendering → #151 |
| 7 | B2B Customer | Flutter iOS/Android | طلباتي | 01_Mobile/B2B_Customer/07_طلباتي.png | 226x422 | /b2b/orders | orders | GET /api/v1/b2b/orders | Partial data rendering → #151 |
| 8 | B2B Customer | Flutter iOS/Android | تفاصيل_الطلب_وتتبع_الحالة | 01_Mobile/B2B_Customer/08_تفاصيل_الطلب_وتتبع_الحالة.png | 226x420 | /b2b/orders/:id | orders | GET /api/v1/b2b/orders/{order} | Partial data rendering → #151 |
| 9 | B2B Customer | Flutter iOS/Android | تفاصيل_الفاتورة | 01_Mobile/B2B_Customer/09_تفاصيل_الفاتورة.png | 279x440 | /b2b/invoices/:id | b2b | GET /api/v1/b2b/invoices/{invoice} | Partial data rendering → #151 |
| 10 | B2B Customer | Flutter iOS/Android | تصفح_المنتجات | 01_Mobile/B2B_Customer/10_تصفح_المنتجات.png | 227x420 | /b2b/products | catalog | GET /api/v1/b2b/products | Partial data rendering → #151 |
| 11 | B2B Customer | Flutter iOS/Android | تفاصيل_المنتج_وإضافة_للسلة | 01_Mobile/B2B_Customer/11_تفاصيل_المنتج_وإضافة_للسلة.png | 226x420 | /b2b/products/:id | catalog | GET /api/v1/products/{product} | Gap → #143 |
| 12 | B2B Customer | Flutter iOS/Android | سلة_المشتريات_وإتمام_الطلب | 01_Mobile/B2B_Customer/12_سلة_المشتريات_وإتمام_الطلب.png | 227x439 | /b2b/cart | cart | GET/POST /api/v1/cart | Partial data rendering → #151 |
| 13 | B2B Customer | Flutter iOS/Android | حسابي_والإعدادات | 01_Mobile/B2B_Customer/13_حسابي_والإعدادات.png | 209x439 | /b2b/profile | customers | GET /api/v1/profile | Partial data rendering → #151 |
| 14 | B2C Customer | Flutter iOS/Android | الشاشة_الافتتاحية | 01_Mobile/B2C_Customer/01_الشاشة_الافتتاحية.png | 218x506 | /splash | home | none | Functional verified; visual source unavailable |
| 15 | B2C Customer | Flutter iOS/Android | تصفح_كضيف_او_تسجيل_الدخول | 01_Mobile/B2C_Customer/02_تصفح_كضيف_او_تسجيل_الدخول.png | 235x487 | /entry | auth | POST /api/v1/auth/login (optional) | Functional verified; visual source unavailable |
| 16 | B2C Customer | Flutter iOS/Android | اختيار_المتجر | 01_Mobile/B2C_Customer/03_اختيار_المتجر.png | 236x486 | /stores | stores | GET /api/v1/stores | Gap → #140 |
| 17 | B2C Customer | Flutter iOS/Android | الصفحة_الرئيسية_للمتجر | 01_Mobile/B2C_Customer/04_الصفحة_الرئيسية_للمتجر.png | 272x551 | /home | home | GET /api/v1/stores/{store}/products | Gap → #140 |
| 18 | B2C Customer | Flutter iOS/Android | عروض_وتخفيضات | 01_Mobile/B2C_Customer/05_عروض_وتخفيضات.png | 238x553 | /offers | promotions | GET /api/v1/stores/{store}/offers | Gap → #140 |
| 19 | B2C Customer | Flutter iOS/Android | قائمة_المنتجات_والفلاتر | 01_Mobile/B2C_Customer/06_قائمة_المنتجات_والفلاتر.png | 237x533 | /products | catalog | GET /api/v1/stores/{store}/products | Gap → #140 |
| 20 | B2C Customer | Flutter iOS/Android | تفاصيل_المنتج | 01_Mobile/B2C_Customer/07_تفاصيل_المنتج.png | 244x432 | /products/:id | catalog | GET /api/v1/products/{product} | Gap → #140 |
| 21 | B2C Customer | Flutter iOS/Android | سلة_التسوق | 01_Mobile/B2C_Customer/08_سلة_التسوق.png | 264x436 | /cart | cart | GET/POST /api/v1/cart | Gap → #141 |
| 22 | B2C Customer | Flutter iOS/Android | تسجيل_الدخول_لإتمام_الطلب | 01_Mobile/B2C_Customer/09_تسجيل_الدخول_لإتمام_الطلب.png | 272x422 | /auth/checkout | auth | POST /api/v1/auth/login | Functional login verified; visual source unavailable |
| 23 | B2C Customer | Flutter iOS/Android | العنوان_والدفع | 01_Mobile/B2C_Customer/10_العنوان_والدفع.png | 244x435 | /checkout/address-payment | checkout | POST /api/v1/checkout | Functional checkout mutation verified; visual source unavailable |
| 24 | B2C Customer | Flutter iOS/Android | تتبع_الطلب | 01_Mobile/B2C_Customer/11_تتبع_الطلب.png | 224x430 | /orders/:id/track | orders | GET /api/v1/orders/{order} | Gap → #141 |
| 25 | B2C Customer | Flutter iOS/Android | الملف_الشخصي_والمفضلة | 01_Mobile/B2C_Customer/12_الملف_الشخصي_والمفضلة.png | 218x428 | /profile | customers | GET /api/v1/profile | Gap → #141 |
| 26 | B2B Super Admin | Laravel Web Admin | تسجيل_الدخول_B2B | 02_Web/B2B_SuperAdmin/01_تسجيل_الدخول_B2B.png | 1448x1086 | /admin/b2b/login | auth | POST /api/v1/auth/login | Gap → #139 |
| 27 | B2B Super Admin | Laravel Web Admin | لوحة_التحكم_الرئيسية | 02_Web/B2B_SuperAdmin/02_لوحة_التحكم_الرئيسية.png | 1448x1086 | /admin/b2b/dashboard | reports | GET /api/v1/admin/dashboard | Partial shell → #144 |
| 28 | B2B Super Admin | Laravel Web Admin | إدارة_المتاجر_وفروع_الجملة | 02_Web/B2B_SuperAdmin/03_إدارة_المتاجر_وفروع_الجملة.png | 1448x1086 | /admin/b2b/stores | stores | /api/v1/admin/stores | Partial shell → #144 |
| 29 | B2B Super Admin | Laravel Web Admin | إدارة_عملاء_الجملة_B2B | 02_Web/B2B_SuperAdmin/04_إدارة_عملاء_الجملة_B2B.png | 1448x1086 | /admin/b2b/clients | b2b | /api/v1/admin/b2b/accounts | Partial shell → #144 |
| 30 | B2B Super Admin | Laravel Web Admin | إدارة_المنتجات_والمخزون | 02_Web/B2B_SuperAdmin/05_إدارة_المنتجات_والمخزون.png | 1448x1086 | /admin/b2b/products | catalog/inventory | /api/v1/admin/products | Partial shell → #144 |
| 31 | B2B Super Admin | Laravel Web Admin | إدارة_الطلبات | 02_Web/B2B_SuperAdmin/06_إدارة_الطلبات.png | 1448x1086 | /admin/b2b/orders | orders | /api/v1/admin/orders | Partial shell → #145 |
| 32 | B2B Super Admin | Laravel Web Admin | إدارة_السائقين_والتوصيل | 02_Web/B2B_SuperAdmin/07_إدارة_السائقين_والتوصيل.png | 1448x1086 | /admin/b2b/drivers | drivers/delivery | /api/v1/admin/drivers | Partial shell → #145 |
| 33 | B2B Super Admin | Laravel Web Admin | التسعير_والموافقات | 02_Web/B2B_SuperAdmin/08_التسعير_والموافقات.png | 1448x1086 | /admin/b2b/pricing-approvals | pricing | /api/v1/admin/b2b/pricing | Partial/route mismatch → #145 |
| 34 | B2B Super Admin | Laravel Web Admin | التقارير_والتحليلات | 02_Web/B2B_SuperAdmin/09_التقارير_والتحليلات.png | 1448x1086 | /admin/b2b/reports | reports | /api/v1/admin/reports | Partial shell → #146 |
| 35 | B2B Super Admin | Laravel Web Admin | إعدادات_المنصة_والصلاحيات | 02_Web/B2B_SuperAdmin/10_إعدادات_المنصة_والصلاحيات.png | 1448x1086 | /admin/b2b/settings-permissions | settings/auth | /api/v1/admin/settings | Partial/route mismatch → #146 |
| 36 | B2C Admin | Laravel Web Admin | تسجيل_الدخول_B2C | 02_Web/B2C_Admin/01_تسجيل_الدخول_B2C.png | 316x433 | /admin/b2c/login | auth | POST /api/v1/auth/login | Gap → #139 |
| 37 | B2C Admin | Laravel Web Admin | لوحة_التحكم_الرئيسية_B2C | 02_Web/B2C_Admin/02_لوحة_التحكم_الرئيسية_B2C.png | 707x433 | /admin/b2c/dashboard | reports | GET /api/v1/admin/dashboard | Functional + PH-05 visual QA verified (#116) |
| 38 | B2C Admin | Laravel Web Admin | إدارة_المنتجات | 02_Web/B2C_Admin/03_إدارة_المنتجات.png | 401x433 | /admin/b2c/products | catalog | /api/v1/admin/products | Partial shell → #147 |
| 39 | B2C Admin | Laravel Web Admin | إدارة_المخزون | 02_Web/B2C_Admin/04_إدارة_المخزون.png | 347x245 | /admin/b2c/inventory | inventory | /api/v1/admin/inventory | Partial shell → #147 |
| 40 | B2C Admin | Laravel Web Admin | إدارة_الطلبات | 02_Web/B2C_Admin/05_إدارة_الطلبات.png | 356x245 | /admin/b2c/orders | orders | /api/v1/admin/orders | Partial shell → #147 |
| 41 | B2C Admin | Laravel Web Admin | إدارة_العملاء | 02_Web/B2C_Admin/06_إدارة_العملاء.png | 354x245 | /admin/b2c/customers | customers | /api/v1/admin/customers | Partial shell → #147 |
| 42 | B2C Admin | Laravel Web Admin | العروض_والخصومات | 02_Web/B2C_Admin/07_العروض_والخصومات.png | 358x245 | /admin/b2c/promotions | promotions | /api/v1/admin/promotions | Partial shell → #148 |
| 43 | B2C Admin | Laravel Web Admin | التوصيل_والسائقين | 02_Web/B2C_Admin/08_التوصيل_والسائقين.png | 347x291 | /admin/b2c/drivers | drivers/delivery | /api/v1/admin/drivers | Partial shell → #148 |
| 44 | B2C Admin | Laravel Web Admin | واجهة_المتجر_ومعاينة_المتجر | 02_Web/B2C_Admin/09_واجهة_المتجر_ومعاينة_المتجر.png | 358x291 | /admin/b2c/storefront-preview | stores | /api/v1/admin/stores | Partial/route mismatch → #148 |
| 45 | B2C Admin | Laravel Web Admin | المحتوى_والبانرات | 02_Web/B2C_Admin/10_المحتوى_والبانرات.png | 355x291 | /admin/b2c/content | promotions | /api/v1/admin/banners | Partial shell → #148 |
| 46 | B2C Admin | Laravel Web Admin | التقارير_والتحليلات_وإعدادات_المتجر | 02_Web/B2C_Admin/11_التقارير_والتحليلات_وإعدادات_المتجر.png | 362x291 | /admin/b2c/reports | reports/settings | /api/v1/admin/reports | Partial shell → #149 |
