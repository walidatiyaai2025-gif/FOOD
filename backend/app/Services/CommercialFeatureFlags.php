<?php

namespace App\Services;

use App\Models\Setting;

final class CommercialFeatureFlags
{
    public const COMMERCIAL_RULES = 'commercial_rules_enabled';

    public const FLASH_OFFERS = 'flash_offers_enabled';

    public const CUSTOMER_FLASH_POPUP = 'customer_flash_popup_enabled';

    public const VAN_OFFERS = 'van_offers_enabled';

    /** @var list<string> */
    private const KEYS = [
        self::COMMERCIAL_RULES,
        self::FLASH_OFFERS,
        self::CUSTOMER_FLASH_POPUP,
        self::VAN_OFFERS,
    ];

    public function commercialRulesEnabled(): bool
    {
        return $this->enabled(self::COMMERCIAL_RULES);
    }

    public function flashOffersEnabled(): bool
    {
        return $this->enabled(self::FLASH_OFFERS);
    }

    public function customerFlashPopupEnabled(): bool
    {
        return $this->enabled(self::CUSTOMER_FLASH_POPUP);
    }

    public function vanOffersEnabled(): bool
    {
        return $this->enabled(self::VAN_OFFERS);
    }

    public function enabled(string $key): bool
    {
        if (! in_array($key, self::KEYS, true)) {
            return false;
        }

        $value = Setting::query()
            ->whereNull('store_id')
            ->where('key', $key)
            ->orderByDesc('id')
            ->value('value');

        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) ((int) $value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return is_bool($decoded) ? $decoded : (bool) $decoded;
            }

            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return (bool) $value;
    }

    /** @return array<string,bool> */
    public function snapshot(): array
    {
        return [
            self::COMMERCIAL_RULES => $this->commercialRulesEnabled(),
            self::FLASH_OFFERS => $this->flashOffersEnabled(),
            self::CUSTOMER_FLASH_POPUP => $this->customerFlashPopupEnabled(),
            self::VAN_OFFERS => $this->vanOffersEnabled(),
        ];
    }

    /** @param array<string,bool> $flags
     *  @return array<string,bool>
     */
    public function persist(array $flags): array
    {
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $flags)) {
                continue;
            }

            $setting = Setting::query()
                ->whereNull('store_id')
                ->where('key', $key)
                ->orderBy('id')
                ->first();

            if (! $setting instanceof Setting) {
                $setting = new Setting([
                    'store_id' => null,
                    'key' => $key,
                ]);
            }

            $setting->forceFill([
                'value' => json_encode((bool) $flags[$key], JSON_THROW_ON_ERROR),
                'is_secret' => false,
            ])->save();
        }

        return $this->snapshot();
    }
}
