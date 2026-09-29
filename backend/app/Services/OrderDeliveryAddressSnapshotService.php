<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Order;

final class OrderDeliveryAddressSnapshotService
{
    /** @return array<string, mixed> */
    public function snapshot(Address $address): array
    {
        return [
            'version' => 1,
            'address_id' => (int) $address->getKey(),
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'delivery_phone' => $address->delivery_phone,
            'line1' => (string) $address->line1,
            'line2' => $address->line2,
            'city' => (string) $address->city,
            'area' => $address->area,
            'country_code' => (string) $address->country_code,
            'country' => $address->country,
            'governorate' => $address->governorate,
            'block' => $address->block,
            'street' => $address->street ?: $address->line1,
            'avenue' => $address->avenue,
            'building' => $address->building,
            'floor' => $address->floor,
            'apartment' => $address->apartment,
            'landmark' => $address->landmark,
            'delivery_notes' => $address->delivery_notes,
            'latitude' => $address->latitude === null ? null : (float) $address->latitude,
            'longitude' => $address->longitude === null ? null : (float) $address->longitude,
            'location_accuracy_meters' => $address->location_accuracy_meters === null
                ? null
                : (float) $address->location_accuracy_meters,
            'location_source' => (string) ($address->location_source ?: 'manual'),
        ];
    }

    /** @return array<string, mixed> */
    public function attributes(?Address $address): array
    {
        if (! $address instanceof Address) {
            return [
                'delivery_address_snapshot' => null,
                'delivery_latitude' => null,
                'delivery_longitude' => null,
            ];
        }

        $snapshot = $this->snapshot($address);

        return [
            'delivery_address_snapshot' => $snapshot,
            'delivery_latitude' => $snapshot['latitude'],
            'delivery_longitude' => $snapshot['longitude'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function payload(Order $order): ?array
    {
        $snapshot = $order->delivery_address_snapshot;

        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        $snapshot['latitude'] = $order->delivery_latitude === null
            ? ($snapshot['latitude'] ?? null)
            : (float) $order->delivery_latitude;
        $snapshot['longitude'] = $order->delivery_longitude === null
            ? ($snapshot['longitude'] ?? null)
            : (float) $order->delivery_longitude;
        $snapshot['formatted'] = $this->formatted($snapshot);
        $snapshot['has_coordinates'] = $this->hasCoordinates($snapshot);

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function hasCoordinates(array $snapshot): bool
    {
        return is_numeric($snapshot['latitude'] ?? null)
            && is_numeric($snapshot['longitude'] ?? null)
            && (float) $snapshot['latitude'] >= -90
            && (float) $snapshot['latitude'] <= 90
            && (float) $snapshot['longitude'] >= -180
            && (float) $snapshot['longitude'] <= 180;
    }

    /** @param array<string, mixed> $snapshot */
    public function formatted(array $snapshot): string
    {
        $parts = array_filter([
            $snapshot['label'] ?? null,
            $snapshot['building'] ?? null,
            $snapshot['street'] ?? $snapshot['line1'] ?? null,
            $snapshot['block'] ?? null,
            $snapshot['area'] ?? null,
            $snapshot['city'] ?? null,
            $snapshot['governorate'] ?? null,
            $snapshot['country'] ?? $snapshot['country_code'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '');

        return implode(', ', array_map(static fn (mixed $value): string => trim((string) $value), $parts));
    }
}
