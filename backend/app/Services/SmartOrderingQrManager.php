<?php

namespace App\Services;

use App\Models\SmartOrderingQrCode;
use App\Models\SmartOrderingQrToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingQrManager
{
    public const TYPES = [
        'generic', 'branch', 'table', 'entrance', 'cashier',
        'delivery', 'flyer', 'social', 'campaign',
    ];

    /**
     * @param array<string, mixed> $data
     * @return array{qr:SmartOrderingQrCode,token:string}
     */
    public function create(array $data, ?int $actorId = null): array
    {
        $this->validateBusinessContext($data);

        return DB::transaction(function () use ($data, $actorId): array {
            $qr = SmartOrderingQrCode::query()->create([
                'public_id' => (string) Str::uuid(),
                'store_id' => (int) $data['store_id'],
                'promotion_id' => $data['promotion_id'] ?? null,
                'type' => (string) $data['type'],
                'label' => trim((string) $data['label']),
                'notes' => $this->nullableTrim($data['notes'] ?? null),
                'target_path' => $this->normalizeTargetPath((string) $data['target_path']),
                'source' => $this->nullableTrim($data['source'] ?? null),
                'medium' => $this->nullableTrim($data['medium'] ?? null),
                'campaign' => $this->nullableTrim($data['campaign'] ?? null),
                'branch_reference' => $this->nullableTrim($data['branch_reference'] ?? null),
                'table_reference' => $this->nullableTrim($data['table_reference'] ?? null),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $token = $this->createToken($qr, $actorId);

            return ['qr' => $qr->fresh(['activeToken', 'store', 'promotion']), 'token' => $token];
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SmartOrderingQrCode $qr, array $data, ?int $actorId = null): SmartOrderingQrCode
    {
        $payload = $data + ['store_id' => (int) $qr->store_id];
        $this->validateBusinessContext($payload);

        $qr->fill([
            'promotion_id' => $data['promotion_id'] ?? null,
            'type' => (string) $data['type'],
            'label' => trim((string) $data['label']),
            'notes' => $this->nullableTrim($data['notes'] ?? null),
            'target_path' => $this->normalizeTargetPath((string) $data['target_path']),
            'source' => $this->nullableTrim($data['source'] ?? null),
            'medium' => $this->nullableTrim($data['medium'] ?? null),
            'campaign' => $this->nullableTrim($data['campaign'] ?? null),
            'branch_reference' => $this->nullableTrim($data['branch_reference'] ?? null),
            'table_reference' => $this->nullableTrim($data['table_reference'] ?? null),
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'updated_by' => $actorId,
        ])->save();

        return $qr->fresh(['activeToken', 'store', 'promotion']);
    }

    public function setEnabled(SmartOrderingQrCode $qr, bool $enabled, ?int $actorId = null): SmartOrderingQrCode
    {
        $qr->forceFill(['is_enabled' => $enabled, 'updated_by' => $actorId])->save();

        return $qr->fresh(['activeToken', 'store', 'promotion']);
    }

    public function rotate(
        SmartOrderingQrCode $qr,
        ?int $actorId = null,
        bool $keepPrevious = true,
        ?Carbon $legacyExpiresAt = null,
    ): string {
        return DB::transaction(function () use ($qr, $actorId, $keepPrevious, $legacyExpiresAt): string {
            $locked = SmartOrderingQrCode::query()->lockForUpdate()->findOrFail($qr->getKey());

            SmartOrderingQrToken::query()
                ->where('qr_code_id', $locked->getKey())
                ->where('state', 'active')
                ->update([
                    'state' => $keepPrevious ? 'legacy' : 'revoked',
                    'legacy_expires_at' => $keepPrevious ? $legacyExpiresAt : null,
                    'revoked_at' => $keepPrevious ? null : now(),
                    'updated_at' => now(),
                ]);

            return $this->createToken($locked, $actorId);
        });
    }

    /** @return array{qr:SmartOrderingQrCode,token:SmartOrderingQrToken} */
    public function resolve(string $rawToken): array
    {
        $token = SmartOrderingQrToken::query()
            ->with('qrCode.store')
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        abort_unless($token instanceof SmartOrderingQrToken, 404);
        abort_if($token->state === 'revoked', 404);
        abort_if($token->state === 'legacy' && $token->legacy_expires_at?->isPast(), 404);

        $qr = $token->qrCode;
        abort_unless($qr instanceof SmartOrderingQrCode, 404);
        abort_unless($qr->is_enabled, 404);
        abort_if($qr->valid_from !== null && $qr->valid_from->isFuture(), 404);
        abort_if($qr->valid_until !== null && $qr->valid_until->isPast(), 404);
        abort_unless($qr->store?->is_active === true, 404);

        return ['qr' => $qr, 'token' => $token];
    }

    public function publicUrlFor(SmartOrderingQrCode $qr): ?string
    {
        $token = $qr->relationLoaded('activeToken') ? $qr->activeToken : $qr->activeToken()->first();

        if (! $token instanceof SmartOrderingQrToken || ! is_string($token->token_value) || $token->token_value === '') {
            return null;
        }

        return route('smart-ordering.qr.resolve', ['token' => $token->token_value], true);
    }

    public function publicUrlFromRaw(string $rawToken): string
    {
        return route('smart-ordering.qr.resolve', ['token' => $rawToken], true);
    }

    /** @param array<string, mixed> $data */
    private function validateBusinessContext(array $data): void
    {
        $type = (string) ($data['type'] ?? '');
        if (in_array($type, self::TYPES, true) === false) {
            throw ValidationException::withMessages(['type' => [__('smart_ordering_qr.validation.invalid_type')]]);
        }

        if ($type === 'table' && $this->nullableTrim($data['table_reference'] ?? null) === null) {
            throw ValidationException::withMessages([
                'table_reference' => [__('smart_ordering_qr.validation.table_required')],
            ]);
        }

        if ($type === 'campaign'
            && $this->nullableTrim($data['campaign'] ?? null) === null
            && empty($data['promotion_id'])) {
            throw ValidationException::withMessages([
                'campaign' => [__('smart_ordering_qr.validation.campaign_required')],
            ]);
        }

        if (empty($data['promotion_id']) === false) {
            $owned = DB::table('promotions')
                ->where('id', (int) $data['promotion_id'])
                ->where(function ($query) use ($data): void {
                    $query->where('store_id', (int) $data['store_id'])->orWhereNull('store_id');
                })
                ->exists();

            if ($owned === false) {
                throw ValidationException::withMessages([
                    'promotion_id' => [__('smart_ordering_qr.validation.promotion_scope')],
                ]);
            }
        }

        if (! empty($data['valid_from']) && ! empty($data['valid_until'])
            && Carbon::parse((string) $data['valid_until'])->lte(Carbon::parse((string) $data['valid_from']))) {
            throw ValidationException::withMessages([
                'valid_until' => [__('smart_ordering_qr.validation.invalid_window')],
            ]);
        }
    }

    private function normalizeTargetPath(string $target): string
    {
        $target = trim($target);
        if ($target === ''
            || str_starts_with($target, '/') === false
            || str_starts_with($target, '//')
            || str_contains($target, '://')
            || str_contains($target, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            throw ValidationException::withMessages([
                'target_path' => [__('smart_ordering_qr.validation.relative_target')],
            ]);
        }

        return $target;
    }

    private function createToken(SmartOrderingQrCode $qr, ?int $actorId): string
    {
        do {
            $raw = Str::random(56);
            $hash = hash('sha256', $raw);
        } while (SmartOrderingQrToken::query()->where('token_hash', $hash)->exists());

        SmartOrderingQrToken::query()->create([
            'qr_code_id' => $qr->getKey(),
            'token_hash' => $hash,
            'token_value' => $raw,
            'state' => 'active',
            'created_by' => $actorId,
        ]);

        return $raw;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (is_string($value) === false) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
