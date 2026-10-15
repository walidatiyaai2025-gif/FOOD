<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChatbotSegmentationService
{
    /** @return list<string> */
    public function codesFor(?int $platformCustomerId): array
    {
        if ($platformCustomerId === null || ! Schema::hasTable('whats_app_chatbot_segments')) {
            return [];
        }

        $customer = DB::table('platform_customers')
            ->where('id', $platformCustomerId)
            ->where('is_active', true)
            ->first();

        if ($customer === null) {
            return [];
        }

        $legacy = DB::table('customers')->where('id', $customer->legacy_customer_id)->first();
        $orderQuery = DB::table('orders')->where('customer_id', $customer->legacy_customer_id);
        $orderCount = (clone $orderQuery)->count();
        $lastOrderAt = (clone $orderQuery)->max('created_at');
        $customerType = strtolower((string) ($legacy->type ?? 'b2b'));
        $daysSinceOrder = $lastOrderAt === null
            ? PHP_INT_MAX
            : max(0, now()->diffInDays($lastOrderAt));

        $codes = [];
        foreach (DB::table('whats_app_chatbot_segments')
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get() as $segment) {
            $rules = json_decode((string) $segment->rules, true);
            $rules = is_array($rules) ? $rules : [];
            $requiredType = strtolower((string) ($rules['customer_type'] ?? 'any'));
            $minOrders = max(0, (int) ($rules['min_orders'] ?? 0));
            $dormantDays = max(0, (int) ($rules['dormant_days'] ?? 0));

            if ($requiredType !== 'any' && $requiredType !== $customerType) {
                continue;
            }
            if ($orderCount < $minOrders) {
                continue;
            }
            if ($dormantDays > 0 && $daysSinceOrder < $dormantDays) {
                continue;
            }

            $codes[] = (string) $segment->code;
        }

        return $codes;
    }
}
