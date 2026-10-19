# FOODEX Restaurant Menu Template Library — Production Design Contract

Status: implementation-ready design authority for #2099 / parent #2098  
Audience: Restaurant SaaS template runtime, gallery, editor, public publishing and visual-QA workers  
Product boundary: customer-facing public restaurant menus; **not** Dashboard/Admin UI and **not** an in-product AI designer

## 1. Placement and ownership

- **domain:** Restaurant SaaS / Public Menu
- **entity:** restaurant menu presentation
- **role:** restaurant manager/content editor for selection; public customer for consumption
- **user goal:** choose a professional built-in presentation, understand it before entering data, then publish the same authoritative menu content through it
- **workflow type:** low-frequency template selection + high-frequency public browsing
- **existing parent:** SmartOrdering / Restaurant Management appearance surface
- **placement:** extend/compose the existing Restaurant Management appearance experience; do not create a new global Sidebar domain
- **screen pattern:** gallery + live preview for operators; mobile-first content browser for customers
- **navigation impact:** contextual Restaurant Management entry only
- **permissions:** selection/publish actions require the canonical restaurant appearance/menu management authority; public reads expose only published safe data
- **states:** demo, draft preview, published, empty, sold-out/unavailable, loading, error, offline/stale-safe, unpublished
- **locales:** Arabic RTL and English LTR are first-class, not translations applied after layout design

The runtime may introduce a dedicated public route because the public menu has an independent customer goal and anonymous access model. That route belongs to the restaurant/public-menu domain and must not duplicate admin navigation.

## 2. Shared design system for public menus

Public menus intentionally do not reuse the visual language of FOODEX Admin. They may reuse technical primitives, accessibility behavior, localization helpers and media infrastructure, but their visual composition is hospitality/customer-facing.

### 2.1 Shared semantic tokens

Implement templates through semantic variables so content is portable:

- `--rm-bg`, `--rm-surface`, `--rm-surface-alt`
- `--rm-text`, `--rm-muted`, `--rm-border`
- `--rm-accent`, `--rm-accent-contrast`, `--rm-accent-soft`
- `--rm-danger`, `--rm-success`
- `--rm-font-display`, `--rm-font-body`
- `--rm-space-1..8`
- `--rm-radius-sm/md/lg/pill`
- `--rm-shadow-soft/raised`
- `--rm-content-max`
- `--rm-image-ratio-hero`, `--rm-image-ratio-dish`

Templates supply values for semantic tokens. Runtime components consume token names, never template-specific hard-coded colors or duplicated business content.

### 2.2 Shared content components

Every template must be able to render the same canonical content model through reusable sections:

1. restaurant identity: logo, name, short promise/tagline;
2. hero/media;
3. category navigation;
4. category heading + optional description;
5. dish item: name, description, price, image, dietary/allergen badges, availability;
6. signature/featured items;
7. optional chef note;
8. optional tasting-menu/course grouping;
9. beverages;
10. desserts;
11. hours;
12. location/contact;
13. reservation/contact CTA;
14. legal/allergen note.

Missing optional content collapses cleanly; no empty decorative box is shown.

### 2.3 Common interaction behavior

- Category navigation remains reachable after the hero without consuming excessive vertical space.
- Category selection scrolls/focuses to the exact category and updates active state.
- Dish photos support graceful absence, deterministic crop, alt text and non-blocking loading.
- Sold-out/unavailable items remain recognizable but cannot appear falsely purchasable/orderable.
- Dietary/allergen indicators combine icon + localized label; color alone is never the meaning.
- Prices use locale-aware number/currency formatting from authoritative restaurant data.
- Long names/descriptions wrap naturally; text is never clipped merely to preserve card height.
- No horizontal page overflow at 320 px.
- Touch targets are at least 44x44 CSS px when interactive.
- Focus state is visible against every theme.
- Motion is subtle and disabled/reduced when `prefers-reduced-motion` requests it.

### 2.4 Responsive contract

**Mobile 320–430 px**
- one primary content column;
- sticky/scrollable category rail where appropriate;
- hero never pushes the first useful menu content below an excessive fold;
- dish cards favor reading order: name → short description → badges → price → image/action as applicable;
- media may move above/beside text only where the template remains legible.

**Tablet 768 px**
- one or two content columns according to restaurant template identity; retail/grocery commerce templates may use up to four compact product columns when image, title, price and add target remain legible;
- category rail can become horizontal sticky or compact side rail;
- sections gain breathing room without changing information order.

**Desktop 1024/1280+ px**
- template-specific editorial composition is allowed;
- content column remains bounded for readability;
- restaurant dish grids normally use two or three columns; dedicated retail/grocery commerce templates may use four compact product columns when card readability and 44x44 actions are preserved.

RTL mirrors directional composition intentionally. It does not merely apply `direction: rtl`: alignment, icon direction, category progression, image/text editorial balance and focus/scroll behavior must be checked in Arabic.

## 3. Built-in template catalog

Stable template identifiers are implementation API and must not be renamed casually.

### 3.1 `fine-dining-elegant-light` — Fine Dining · Elegant Light

**Positioning:** quiet luxury, white/ivory editorial presentation, premium restaurant with restrained photography.

**Visual language**
- warm ivory background, near-black text, muted stone dividers, restrained metallic/warm accent;
- high-contrast serif display face paired with highly readable sans-serif body;
- generous whitespace, hairline dividers, low-radius or square editorial surfaces;
- photography is large, sparse and art-directed rather than attached to every item.

**Composition**
- compact wordmark/logo above a full-width editorial hero;
- category navigation appears as understated text tabs;
- desktop category sections alternate text-led and featured-image moments;
- ordinary dishes render as elegant list rows, not dashboard cards;
- signature dishes may use a 60/40 media-text split;
- price aligns to the logical end of the dish row while preserving name/description flow.

**Mobile**
- hero becomes a restrained 4:3 or 3:2 crop;
- list rows stack price beneath/alongside name depending on width;
- signature split becomes media above content.

**RTL**
- editorial media alternation mirrors;
- Arabic display typography must have an Arabic serif/Naskh-compatible fallback with no Latin-only assumptions.

### 3.2 `fine-dining-dark-luxury` — Fine Dining · Dark Luxury

**Positioning:** evening tasting room / steakhouse / premium lounge.

**Visual language**
- charcoal/ink background, elevated dark surfaces, cream text and a restrained warm-gold accent;
- cinematic photography with controlled contrast;
- thin luminous separators, subtle raised surfaces, no neon/dashboard effects.

**Composition**
- immersive dark hero with restaurant mark and short statement;
- category rail uses compact pill/underline hybrid with strong focus state;
- featured dishes render as large media cards;
- standard dishes use two-column desktop editorial list with clear prices;
- chef/tasting note may appear as a bordered pull-quote section.

**Accessibility**
- every muted text/token pairing must satisfy production contrast checks;
- gold accent is never used as body text where contrast fails.

**Mobile/RTL**
- one column, image first for featured dishes;
- RTL mirrors text/image splits and category-scroll start position.

### 3.3 `contemporary-bistro` — Contemporary Bistro

**Positioning:** energetic all-day bistro/café, approachable rather than formal.

**Visual language**
- bright neutral base, one confident restaurant accent, friendly sans display/body pairing;
- medium radii, light borders, occasional playful section labels;
- more frequent dish photography than fine-dining templates.

**Composition**
- hero can include image + short operating-hours/contact strip;
- sticky category chips optimized for fast scanning;
- desktop dish grid uses two columns with image thumbnail, name, short description and price;
- featured items use larger landscape cards;
- beverage/dessert sections may use compact list treatment for density.

**Mobile**
- category chips scroll horizontally;
- dish row is compact: square image, content, price; image disappears gracefully when absent.

**RTL**
- thumbnail and price placement mirror while retaining the same semantic reading order.

### 3.4 `premium-arabic-dining` — Premium Arabic Dining

**Positioning:** premium Arabic hospitality; Arabic is a native design target rather than an adapted Latin template.

**Visual language**
- warm parchment/sand base, deep date/ink text, emerald or burgundy semantic accent with restrained brass detail;
- Arabic display face with strong Naskh/Kufi-inspired hierarchy paired with a readable Arabic body face;
- geometric separators/pattern accents may be used lightly; never behind body text or in a way that harms contrast.

**Composition**
- RTL-first hero with restaurant identity and optional short Arabic hospitality message;
- category rail starts from RTL logical start and supports bilingual labels without truncation;
- section intros support longer Arabic descriptions;
- dish lists use generous Arabic line-height and explicit allergen/dietary labels;
- featured platters can use wide media with caption/content beneath.

**English LTR**
- same template identity is preserved; do not reorder business sections simply because locale changes.

**Mobile**
- avoid ornamental chrome around every card; keep reading/content dominant;
- at 320 px, Arabic dish names may wrap to 2–3 lines without collision with price.

### 3.5 `modern-minimal` — Modern Minimal Restaurant

**Positioning:** modern fast-casual / design-led restaurant seeking maximum content clarity.

**Visual language**
- pure/near-white background, black/graphite type, one restrained accent;
- sans-serif typography, tight but comfortable spacing, minimal borders/shadows;
- imagery optional and secondary to menu information.

**Composition**
- very compact identity header;
- category navigation becomes a sticky text rail;
- desktop menu is a highly readable two-column list;
- signature items use a single clean media band;
- no decorative container unless it improves grouping.

**Performance objective**
- this is the lowest visual-weight template and serves as the safe fallback when richer media is missing or constrained.

**Mobile/RTL**
- single list with stable price alignment;
- all spacing uses logical properties so LTR/RTL are symmetric.

### 3.6 `chef-tasting` — Chef / Tasting Menu

**Positioning:** prix-fixe, chef’s table and course-led experiences where chronology matters more than category grid browsing.

**Visual language**
- editorial, quiet, text-forward;
- restrained neutral palette with seasonal accent;
- display serif + readable body, or locale-equivalent typography.

**Composition**
- hero introduces tasting-menu name, short chef note and optional price/person;
- primary navigation switches from category-first to course/set-menu anchors;
- each course is a numbered/titled editorial section;
- dish/item within a course emphasizes name, provenance/description and dietary notes;
- optional wine/beverage pairing is visually related but clearly distinct;
- standalone à-la-carte categories may follow after tasting sections.

**Mobile**
- chronological one-column reading experience;
- progress/anchor navigation must not obscure course titles.

**RTL**
- course numbering and directional decorative rules render correctly with Arabic text and localized numerals according to application conventions.

### 3.7 `fresh-market-commerce` — Fresh Market · Modern Commerce

**Positioning:** grocery, supermarket and restaurant-supply storefront optimized for rapid visual shopping on tablet and mobile.

**Visual language**
- bright white canvas with deep navy text, FOODEX green commerce actions and soft cool-gray boundaries;
- image-led hero with a white gradient transition into restaurant/merchant copy;
- compact white category tiles with icon/image treatment and a green “all products” anchor;
- dense photographic product cards with availability badge, concise pack/description line, visible price and a dedicated green add button.

**Composition**
- RTL header keeps merchant identity/logo at the logical start and menu/cart/language actions opposite;
- hero remains a two-column image/copy composition on tablet and mobile rather than collapsing into a generic stacked restaurant hero;
- full-width rounded search follows the hero;
- six-up category strip is the primary discovery control for the common grocery taxonomy, with overflow-safe behavior when more categories exist;
- featured products render four-up on tablet/desktop and two-up on mobile;
- remaining categories reuse the same product-card system below featured products so canonical menu data is never duplicated or re-entered;
- checkout/item dialog continues to use the canonical Smart Ordering runtime and ordering APIs.

**Mobile / RTL**
- preserve the two-column hero balance from the approved reference;
- product cards remain two-up down to 320 px with compact typography and >=44 px actionable controls wherever ordering is available;
- directional CTA arrow and grid progression mirror correctly under RTL;
- no device-frame artwork is rendered by the storefront itself; phone/tablet hardware shown in reference images is presentation context only.

## 4. State design contract

Every template must implement identical business states with template-native styling:

- **demo:** clearly identified preview sample, no implication it is live restaurant data;
- **draft preview:** private/operator context only, visibly marked outside the public design surface;
- **published:** no admin chrome;
- **unpublished:** public route returns the canonical unavailable/not-published experience without leaking draft content;
- **loading:** layout-stable skeletons/placeholders sized from real components;
- **empty restaurant/menu:** friendly localized empty state; no fabricated dishes;
- **empty category:** either hide it when appropriate or show explicit localized empty copy in editor preview;
- **sold out/unavailable item:** semantic state label and muted visual treatment; item identity remains readable;
- **media missing:** deterministic placeholder/typographic layout; never broken-image chrome;
- **error:** localized retry-safe message for recoverable public fetch failure;
- **stale/offline-safe:** last safe published content may be shown only where platform caching policy authorizes it, with no draft leakage;
- **permission denied:** operator-side selection/publish controls are hidden/blocked server-side; public page does not expose permissions.

## 5. Demo content fixture

Implementation workers must provide one stable bilingual demo fixture used by gallery previews and visual regression. It is illustrative only and never persisted as restaurant production content.

### Restaurant identity

- EN name: **Saffron & Stone**
- AR name: **زعفران وستون**
- EN tagline: **Seasonal plates, fire and generous hospitality**
- AR tagline: **أطباق موسمية ونكهات النار وضيافة دافئة**

### Categories and dishes

**Starters / المقبلات**
- Charred Eggplant / باذنجان مشوي — smoked tahini, tomato, herbs / طحينة مدخنة، طماطم، أعشاب — KWD 3.750 — Vegetarian
- Citrus Sea Bass / قاروص بالحمضيات — citrus, fennel, olive oil / حمضيات، شمر، زيت زيتون — KWD 5.500 — Contains fish

**From the Fire / من النار**
- Date-Glazed Short Rib / ضلع بقري بدبس التمر — slow cooked beef, date glaze, jus / لحم بقري مطهو ببطء، دبس تمر، صوص مركز — KWD 9.500 — Signature
- Sumac Chicken / دجاج بالسماق — grilled chicken, sumac, roasted onion / دجاج مشوي، سماق، بصل محمص — KWD 6.750
- Wild Mushroom Freekeh / فريكة بالفطر البري — freekeh, mushroom, herbs / فريكة، فطر بري، أعشاب — KWD 5.900 — Vegetarian

**Dessert / الحلويات**
- Pistachio Milk Cake / كيكة الحليب بالفستق — pistachio, cardamom cream / فستق، كريمة الهيل — KWD 3.250 — Contains nuts/dairy
- Date & Tahini Tart / تارت التمر والطحينة — date caramel, tahini, sesame / كراميل التمر، طحينة، سمسم — KWD 3.400 — Contains sesame

**Beverages / المشروبات**
- Saffron Lemonade / ليمونادة بالزعفران — KWD 1.950
- Arabic Coffee / قهوة عربية — KWD 1.500

### Tasting fixture

- Course 1 / الطبق الأول: Citrus Sea Bass / قاروص بالحمضيات
- Course 2 / الطبق الثاني: Charred Eggplant / باذنجان مشوي
- Course 3 / الطبق الثالث: Date-Glazed Short Rib / ضلع بقري بدبس التمر
- Course 4 / الطبق الرابع: Pistachio Milk Cake / كيكة الحليب بالفستق

### Auxiliary sections

- EN chef note: **Our menu follows the season and the market; some dishes may change during service.**
- AR chef note: **تتغير قائمتنا حسب الموسم والمتوفر في السوق، وقد تتبدل بعض الأطباق أثناء الخدمة.**
- Hours: 12:00–23:00
- Contact CTA: Reserve a table / احجز طاولة
- Allergen note EN: **Please tell our team about allergies before ordering.**
- Allergen note AR: **يرجى إبلاغ فريقنا بأي حساسية قبل الطلب.**

## 6. Gallery presentation metadata

Every gallery card must derive from registry metadata, not hard-coded per-view conditions:

- stable ID;
- localized name;
- localized one-line positioning;
- style tags;
- light/dark classification;
- hero preview asset/fixture reference;
- supported section capabilities;
- recommended use cases;
- default demo locale;
- version;
- availability/deprecation status.

Gallery preview uses the real template renderer with the demo fixture. Static screenshots may be used only as optional cached thumbnails, never as the functional preview or template implementation.

## 7. Runtime implementation handoff

#2100 owns:
- canonical registry/schema and versioning;
- shared renderer/components and semantic tokens;
- safe fallback;
- persistence of selected template/version;
- content/presentation separation.

#2101 owns:
- professional gallery cards;
- demo live preview in mobile/tablet/desktop modes;
- apply/switch/current-template state;
- restaurant-data preview without mutating published output.

#2102 owns:
- content-first editor bindings;
- restaurant identity/categories/dishes/media/pricing/dietary/availability/optional sections;
- polished validation/empty states.

#2107 owns:
- draft/published lifecycle;
- stable hosted public URL/slug;
- share/QR;
- public security/performance/unavailable behavior.

#2108 owns:
- final cross-template AR/EN, RTL/LTR, responsive, accessibility and visual-regression certification;
- final Designer Worker sign-off for #2099.

## 8. Designer QA checklist

A template is not designer-approved unless all are true:

- the seven production IDs render as visibly different compositions, not recolors;
- no template resembles an Admin dashboard;
- the same canonical content renders without duplication/re-entry;
- long Arabic and English strings do not overlap controls/prices/media;
- 320, 360, 390, 412, 430, 768, 1024 and 1280+ widths have no page overflow;
- RTL composition is intentionally mirrored where directional;
- no-content, no-image, sold-out, loading and error states are polished;
- keyboard focus, semantic headings and contrast meet accessibility requirements;
- gallery demo preview and real-content preview use the same renderer;
- switching templates does not mutate menu content;
- draft never leaks to public output;
- customer public pages contain no admin IDs/codes/secrets;
- visual regression captures exist for all seven templates in AR and EN at representative mobile + desktop widths.

Final sign-off is recorded on #2099 only after #2108 exact-head evidence passes.
