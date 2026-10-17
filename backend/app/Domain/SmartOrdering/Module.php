<?php

namespace App\Domain\SmartOrdering;

enum Module: string
{
    case PUBLIC_MENU = 'public_menu';
    case ORDERING = 'ordering';
    case DINE_IN = 'dine_in';
    case PICKUP = 'pickup';
    case DELIVERY = 'delivery';
    case WHATSAPP = 'whatsapp';
    case CHATBOT = 'chatbot';
    case OFFERS = 'offers';
    case CAMPAIGN_QR = 'campaign_qr';
    case ANALYTICS = 'analytics';
    case LOYALTY = 'loyalty';
    case REVIEWS = 'reviews';
    case RESERVATIONS = 'reservations';
    case ADVANCED_UPSELL = 'advanced_upsell';
    case MULTI_BRANCH = 'multi_branch';
    case CUSTOM_DOMAIN = 'custom_domain';
    case FOODEX_SUPPLY_INTELLIGENCE = 'foodex_supply_intelligence';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $module): string => $module->value, self::cases());
    }
}
