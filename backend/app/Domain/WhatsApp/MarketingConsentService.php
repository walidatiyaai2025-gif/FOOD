<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MarketingConsentService
{
    public const GRANTED = 'granted';

    public const DENIED = 'denied';

    public const OPTED_OUT = 'opted_out';

    /** @param array<string, mixed> $metadata */
    public function record(
        int $platformCustomerId,
        string $state,
        string $source,
        ?int $actorUserId = null,
        array $metadata = [],
    ): int {
        if (! in_array($state, [self::GRANTED, self::DENIED, self::OPTED_OUT], true)) {
            throw ValidationException::withMessages(['state' => ['Invalid marketing consent state.']]);
        }

        return DB::transaction(function () use (
            $platformCustomerId,
            $state,
            $source,
            $actorUserId,
            $metadata,
        ): int {
            $customer = DB::table('platform_customers')
                ->where('id', $platformCustomerId)
                ->lockForUpdate()
                ->first(['id', 'phone']);
            if ($customer === null) {
                throw ValidationException::withMessages(['platform_customer_id' => ['Customer not found.']]);
            }

            $phone = $this->normalizePhone($customer->phone);
            if ($phone === null) {
                throw ValidationException::withMessages(['platform_customer_id' => ['Customer has no valid phone number.']]);
            }

            $now = now();
            $id = (int) DB::table('whats_app_marketing_consents')->insertGetId([
                'platform_customer_id' => $platformCustomerId,
                'phone' => $phone,
                'state' => $state,
                'source' => mb_substr(trim($source), 0, 64),
                'actor_user_id' => $actorUserId,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $preference = DB::table('whats_app_marketing_preferences')
                ->where('platform_customer_id', $platformCustomerId)
                ->first();

            $values = [
                'phone' => $phone,
                'state' => $state,
                'consented_at' => $state === self::GRANTED ? $now : null,
                'opted_out_at' => $state === self::OPTED_OUT ? $now : null,
                'last_consent_id' => $id,
                'updated_at' => $now,
            ];

            if ($preference === null) {
                DB::table('whats_app_marketing_preferences')->insert($values + [
                    'platform_customer_id' => $platformCustomerId,
                    'created_at' => $now,
                ]);
            } else {
                DB::table('whats_app_marketing_preferences')
                    ->where('platform_customer_id', $platformCustomerId)
                    ->update($values);
            }

            return $id;
        }, 3);
    }

    public function optOutByPhone(string $phone, string $source = 'inbound_keyword'): ?int
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === null) {
            return null;
        }

        $digits = ltrim($normalized, '+');
        $customerId = DB::table('platform_customers')
            ->where('is_active', true)
            ->whereIn('phone', [$normalized, $digits])
            ->value('id');

        return $customerId === null
            ? null
            : $this->record((int) $customerId, self::OPTED_OUT, $source);
    }

    public function isEligible(int $platformCustomerId, string $phone): bool
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === null) {
            return false;
        }

        $row = DB::table('whats_app_marketing_preferences')
            ->where('platform_customer_id', $platformCustomerId)
            ->first(['phone', 'state']);

        return $row !== null
            && $row->state === self::GRANTED
            && $this->normalizePhone($row->phone) === $normalized;
    }

    public function state(int $platformCustomerId): string
    {
        return (string) (DB::table('whats_app_marketing_preferences')
            ->where('platform_customer_id', $platformCustomerId)
            ->value('state') ?? 'unknown');
    }

    public function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_numeric($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits === '' ? null : '+'.$digits;
    }

    public function isOptOutText(?string $body): bool
    {
        $text = mb_strtolower(trim((string) $body));

        return in_array($text, [
            'stop',
            'unsubscribe',
            'opt out',
            'opt-out',
            'cancel',
            'الغاء',
            'إلغاء',
            'ايقاف',
            'إيقاف',
        ], true);
    }
}
