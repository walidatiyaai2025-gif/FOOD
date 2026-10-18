<?php

namespace App\Domain\SmartOrdering\Themes;

final class RestaurantMenuTemplateDemoFixture
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'revision' => 'demo',
            'locale' => 'en',
            'is_open' => true,
            'closed_message' => [
                'en' => 'This demo restaurant is currently closed.',
                'ar' => 'هذا المطعم التجريبي مغلق حاليًا.',
            ],
            'site' => [
                'public_key' => 'template-demo',
                'name' => ['en' => 'Saffron & Stone', 'ar' => 'زعفران وستون'],
                'tagline' => [
                    'en' => 'Seasonal plates, fire and generous hospitality',
                    'ar' => 'أطباق موسمية ونكهات النار وضيافة دافئة',
                ],
                'description' => [
                    'en' => 'A production preview using the same menu renderer restaurants publish.',
                    'ar' => 'معاينة إنتاجية تستخدم نفس محرك عرض القائمة المنشورة للمطاعم.',
                ],
                'about' => [
                    'en' => 'Our menu follows the season and the market; some dishes may change during service.',
                    'ar' => 'تتغير قائمتنا حسب الموسم والمتوفر في السوق، وقد تتبدل بعض الأطباق أثناء الخدمة.',
                ],
                'logo_url' => null,
                'cover_url' => '/images/smart-ordering/menu-media-placeholder.svg',
                'phone' => '+965 0000 0000',
                'whatsapp_url' => null,
                'branches' => [[
                    'public_key' => 'demo-kuwait',
                    'name' => ['en' => 'Saffron & Stone', 'ar' => 'زعفران وستون'],
                    'address' => ['en' => 'Kuwait', 'ar' => 'الكويت'],
                    'hours' => '12:00–23:00',
                ]],
                'offers' => [],
                'seo' => [
                    'title' => ['en' => 'Saffron & Stone Menu Demo', 'ar' => 'معاينة قائمة زعفران وستون'],
                    'description' => ['en' => 'FOODEX template demo', 'ar' => 'معاينة قالب فودكس'],
                    'canonical' => null,
                ],
            ],
            'appearance' => [
                'template' => 'modern-minimal',
                'mode' => 'light',
                'customization' => [
                    'primary_color' => '#7A3E2B',
                    'secondary_color' => '#171717',
                    'font' => 'editorial',
                    'card_style' => 'minimal',
                    'category_style' => 'rail',
                    'button_style' => 'solid',
                    'show_offers' => true,
                    'show_best_sellers' => true,
                    'show_about' => true,
                    'show_branches' => true,
                ],
            ],
            'menu' => [
                'categories' => [
                    $this->category('starters', 'Starters', 'المقبلات', [
                        $this->item('charred-eggplant', 'Charred Eggplant', 'باذنجان مشوي', 'smoked tahini, tomato, herbs', 'طحينة مدخنة، طماطم، أعشاب', '3.750 KWD', ['Vegetarian', 'نباتي']),
                        $this->item('citrus-sea-bass', 'Citrus Sea Bass', 'قاروص بالحمضيات', 'citrus, fennel, olive oil', 'حمضيات، شمر، زيت زيتون', '5.500 KWD', ['Contains fish', 'يحتوي على سمك']),
                    ]),
                    $this->category('from-fire', 'From the Fire', 'من النار', [
                        $this->item('date-short-rib', 'Date-Glazed Short Rib', 'ضلع بقري بدبس التمر', 'slow cooked beef, date glaze, jus', 'لحم بقري مطهو ببطء، دبس تمر، صوص مركز', '9.500 KWD', ['Signature', 'طبق مميز'], true),
                        $this->item('sumac-chicken', 'Sumac Chicken', 'دجاج بالسماق', 'grilled chicken, sumac, roasted onion', 'دجاج مشوي، سماق، بصل محمص', '6.750 KWD'),
                        $this->item('mushroom-freekeh', 'Wild Mushroom Freekeh', 'فريكة بالفطر البري', 'freekeh, mushroom, herbs', 'فريكة، فطر بري، أعشاب', '5.900 KWD', ['Vegetarian', 'نباتي']),
                    ]),
                    $this->category('dessert', 'Dessert', 'الحلويات', [
                        $this->item('pistachio-milk-cake', 'Pistachio Milk Cake', 'كيكة الحليب بالفستق', 'pistachio, cardamom cream', 'فستق، كريمة الهيل', '3.250 KWD', ['Contains nuts/dairy', 'يحتوي على مكسرات وألبان'], true),
                        $this->item('date-tahini-tart', 'Date & Tahini Tart', 'تارت التمر والطحينة', 'date caramel, tahini, sesame', 'كراميل التمر، طحينة، سمسم', '3.400 KWD', ['Contains sesame', 'يحتوي على سمسم']),
                    ]),
                    $this->category('beverages', 'Beverages', 'المشروبات', [
                        $this->item('saffron-lemonade', 'Saffron Lemonade', 'ليمونادة بالزعفران', '', '', '1.950 KWD'),
                        $this->item('arabic-coffee', 'Arabic Coffee', 'قهوة عربية', '', '', '1.500 KWD'),
                    ]),
                ],
            ],
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private function category(string $key, string $en, string $ar, array $items): array
    {
        return [
            'public_key' => $key,
            'name' => ['en' => $en, 'ar' => $ar],
            'description' => ['en' => '', 'ar' => ''],
            'image_url' => null,
            'items' => $items,
        ];
    }

    /** @param list<string> $tags */
    private function item(
        string $key,
        string $en,
        string $ar,
        string $descriptionEn,
        string $descriptionAr,
        string $price,
        array $tags = [],
        bool $featured = false,
    ): array {
        return [
            'public_key' => $key,
            'name' => ['en' => $en, 'ar' => $ar],
            'description' => ['en' => $descriptionEn, 'ar' => $descriptionAr],
            'image_url' => null,
            'price_display' => $price,
            'sold_out' => false,
            'featured' => $featured,
            'best_seller' => false,
            'new' => false,
            'tags' => $tags,
            'modifiers' => [],
        ];
    }
}
