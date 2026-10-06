<?php

namespace App\Support;

final class CommercialDashboardContract
{
    public const MODE_CONTRACT_PENDING = 'contract-pending';

    /** @var list<string> */
    public const REASON_CODES = [
        'PRODUCT_CLOSED',
        'CUSTOMER_NOT_ELIGIBLE',
        'CHANNEL_NOT_ALLOWED',
        'ORDER_LIMIT_EXCEEDED',
        'DAILY_LIMIT_REACHED',
        'WEEKLY_LIMIT_REACHED',
        'MONTHLY_LIMIT_REACHED',
        'LIFETIME_LIMIT_REACHED',
        'UNIT_NOT_ALLOWED',
        'INSUFFICIENT_STOCK',
        'FLASH_NOT_ACTIVE',
        'FLASH_SOLD_OUT',
        'FLASH_CUSTOMER_LIMIT_REACHED',
        'FLASH_RESERVATION_EXPIRED',
        'ONLINE_VALIDATION_REQUIRED',
        'OVERRIDE_REQUIRED',
    ];

    /** @return array<string,mixed> */
    public static function salesControlSurface(): array
    {
        return [
            'mode' => self::MODE_CONTRACT_PENDING,
            'title' => 'Product Sales Control',
            'contract' => 'canonical-commercial-policy',
            'sections' => [
                'availability',
                'selling_units',
                'quotas',
                'targeting',
                'channel_rules',
                'override_policy',
            ],
            'server_operations' => [
                'evaluate_policy',
                'resolve_selling_units',
                'effective_max',
                'remaining_quota',
            ],
            'reason_codes' => self::REASON_CODES,
        ];
    }

    /** @return array<string,mixed> */
    public static function flashOffersSurface(): array
    {
        return [
            'mode' => self::MODE_CONTRACT_PENDING,
            'title' => 'Flash Offers',
            'contract' => 'canonical-flash-offer',
            'sections' => [
                'lifecycle',
                'audience',
                'channels',
                'allocation',
                'popup_policy',
                'reservation_policy',
                'priority',
                'preflight',
                'analytics',
                'audit',
            ],
            'server_operations' => [
                'list_active_offers',
                'reserve_flash_offer',
                'confirm_reservation',
                'release_reservation',
                'server_time',
            ],
            'reason_codes' => self::REASON_CODES,
        ];
    }
}
