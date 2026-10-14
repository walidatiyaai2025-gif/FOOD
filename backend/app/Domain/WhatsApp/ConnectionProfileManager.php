<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use App\Models\WhatsAppModuleState;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ConnectionProfileManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ConnectionValidator $validator,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes, ?string $secret = null): WhatsAppConnectionProfile
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $attributes, $secret): WhatsAppConnectionProfile {
            $profile = new WhatsAppConnectionProfile;
            $profile->forceFill($this->configuration($attributes) + [
                'status' => ConnectionProfileStatus::DRAFT,
                'health_status' => ConnectionHealthStatus::NEVER_TESTED,
                'is_primary' => false,
                'receive_only' => false,
                'primary_slot' => null,
                'configuration_revision' => 0,
            ]);
            if (is_string($secret) && $secret !== '') {
                $profile->credential_secret = $secret;
            }
            $profile->save();

            $this->audit->record('whatsapp.connection_profile_created', $actor, $profile, null, $profile->safeSnapshot());
            if ($profile->secretConfigured()) {
                $this->audit->record('whatsapp.connection_credential_replaced', $actor, $profile, null, [
                    'profile_id' => $profile->getKey(),
                    'credential_configured' => true,
                ]);
            }

            return $profile->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, WhatsAppConnectionProfile $profile, array $attributes): WhatsAppConnectionProfile
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $profile, $attributes): WhatsAppConnectionProfile {
            $locked = WhatsAppConnectionProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            if ($locked->is_primary) {
                throw ValidationException::withMessages(['profile' => __('whatsapp.connections.active_profile_locked')]);
            }

            $before = $locked->safeSnapshot();
            $locked->forceFill($this->configuration($attributes));
            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => ConnectionProfileStatus::DRAFT,
                'health_status' => ConnectionHealthStatus::NEVER_TESTED,
                'last_checked_at' => null,
                'last_validated_at' => null,
                'last_provider_error' => null,
                'health_summary' => null,
                'validation_metadata' => null,
                'configuration_revision' => $locked->configuration_revision + 1,
            ])->save();

            $this->audit->record('whatsapp.connection_profile_updated', $actor, $locked, $before, $locked->safeSnapshot());

            return $locked->refresh();
        });
    }

    public function replaceCredential(User $actor, WhatsAppConnectionProfile $profile, string $secret): WhatsAppConnectionProfile
    {
        $this->authorize($actor, true);
        if (trim($secret) === '') {
            throw ValidationException::withMessages(['credential_secret' => __('whatsapp.connections.secret_required')]);
        }

        return DB::transaction(function () use ($actor, $profile, $secret): WhatsAppConnectionProfile {
            $locked = WhatsAppConnectionProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $before = $locked->safeSnapshot();
            $locked->credential_secret = $secret;
            $locked->forceFill([
                'health_status' => ConnectionHealthStatus::NEVER_TESTED,
                'last_checked_at' => null,
                'last_validated_at' => null,
                'last_provider_error' => null,
                'health_summary' => null,
                'validation_metadata' => null,
                'configuration_revision' => $locked->configuration_revision + 1,
            ]);
            $locked->status = $locked->is_primary
                ? ConnectionProfileStatus::DEGRADED
                : ConnectionProfileStatus::DRAFT;
            $locked->save();

            $this->audit->record('whatsapp.connection_credential_replaced', $actor, $locked, $before, [
                'profile_id' => $locked->getKey(),
                'credential_configured' => true,
                'configuration_revision' => $locked->configuration_revision,
            ]);

            return $locked->refresh();
        });
    }

    public function validate(User $actor, WhatsAppConnectionProfile $profile): bool
    {
        $this->authorize($actor);

        $revision = DB::transaction(function () use ($actor, $profile): int {
            $locked = WhatsAppConnectionProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            if (! $locked->is_primary) {
                $locked->status = ConnectionProfileStatus::VALIDATING;
                $locked->save();
            }
            $this->audit->record('whatsapp.connection_validation_requested', $actor, $locked, null, [
                'profile_id' => $locked->getKey(),
                'configuration_revision' => $locked->configuration_revision,
            ]);

            return $locked->configuration_revision;
        });

        try {
            $result = $this->validator->validate(WhatsAppConnectionProfile::query()->findOrFail($profile->getKey()));
        } catch (Throwable) {
            $result = new ConnectionValidationResult(
                valid: false,
                health: ConnectionHealthStatus::PROVIDER_UNAVAILABLE,
                accountIdentity: null,
                phoneIdentity: null,
                providerAvailable: false,
                capabilities: [],
                diagnosticCode: 'provider_validation_failed',
                message: 'Provider validation failed safely.',
                checkedAt: new \DateTimeImmutable,
            );
        }

        return DB::transaction(function () use ($actor, $profile, $revision, $result): bool {
            $locked = WhatsAppConnectionProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            if ($locked->configuration_revision !== $revision) {
                $this->audit->record('whatsapp.connection_validation_rejected', $actor, $locked, null, [
                    'profile_id' => $locked->getKey(),
                    'reason' => 'configuration_changed',
                ]);
                throw ValidationException::withMessages(['profile' => __('whatsapp.connections.validation_stale')]);
            }

            $valid = $result->valid && $result->providerAvailable;
            $message = $this->safeProviderText($result->message, $locked);
            $locked->forceFill([
                'status' => $valid
                    ? ($locked->is_primary ? ConnectionProfileStatus::ACTIVE : ConnectionProfileStatus::READY)
                    : ($locked->is_primary ? ConnectionProfileStatus::DEGRADED : ConnectionProfileStatus::INVALID),
                'health_status' => $valid ? ConnectionHealthStatus::READY : $result->health,
                'last_checked_at' => $result->checkedAt,
                'last_validated_at' => $valid ? $result->checkedAt : $locked->last_validated_at,
                'health_summary' => $message,
                'last_provider_error' => $valid ? null : $message,
                'validation_metadata' => [
                    'account_identity' => $this->safeProviderNullable($result->accountIdentity, $locked),
                    'phone_identity' => $this->safeProviderNullable($result->phoneIdentity, $locked),
                    'provider_available' => $result->providerAvailable,
                    'capabilities' => array_values(array_filter($result->capabilities, 'is_string')),
                    'diagnostic_code' => $this->safeProviderText($result->diagnosticCode, $locked, 120),
                ],
            ])->save();

            $this->audit->record('whatsapp.connection_validation_result', $actor, $locked, null, [
                'profile_id' => $locked->getKey(),
                'valid' => $valid,
                'health_status' => $locked->health_status->value,
                'diagnostic_code' => $this->safeProviderText($result->diagnosticCode, $locked, 120),
                'checked_at' => $result->checkedAt->format(DATE_ATOM),
            ]);

            return $valid;
        });
    }

    public function switchPrimary(
        User $actor,
        WhatsAppConnectionProfile $target,
        string $oldProfileAction = 'drain',
    ): WhatsAppConnectionProfile {
        $this->authorize($actor, true);
        if (! in_array($oldProfileAction, ['drain', 'inactive'], true)) {
            throw ValidationException::withMessages(['old_profile_action' => __('whatsapp.connections.invalid_switch_action')]);
        }

        if (! $this->validate($actor, $target)) {
            $fresh = $target->fresh() ?? $target;
            $this->audit->record('whatsapp.connection_primary_switch_rejected', $actor, $fresh, null, [
                'target_profile_id' => $fresh->getKey(),
                'reason' => 'validation_failed',
            ]);
            throw ValidationException::withMessages(['profile' => __('whatsapp.connections.validation_failed')]);
        }

        return DB::transaction(function () use ($actor, $target, $oldProfileAction): WhatsAppConnectionProfile {
            $module = WhatsAppModuleState::query()->lockForUpdate()->find(1);
            abort_if($module === null, 503, __('whatsapp.unavailable'));

            $lockedTarget = WhatsAppConnectionProfile::query()->lockForUpdate()->findOrFail($target->getKey());
            if ($lockedTarget->is_primary) {
                return $lockedTarget;
            }
            if ($lockedTarget->status !== ConnectionProfileStatus::READY
                || $lockedTarget->health_status !== ConnectionHealthStatus::READY
                || $lockedTarget->last_validated_at === null) {
                $this->audit->record('whatsapp.connection_primary_switch_rejected', $actor, $lockedTarget, null, [
                    'target_profile_id' => $lockedTarget->getKey(),
                    'reason' => 'not_ready',
                ]);
                throw ValidationException::withMessages(['profile' => __('whatsapp.connections.not_ready')]);
            }

            $current = WhatsAppConnectionProfile::query()->where('is_primary', true)->lockForUpdate()->first();
            $oldSnapshot = $current?->safeSnapshot();
            if ($current !== null) {
                $current->forceFill([
                    'is_primary' => false,
                    'primary_slot' => null,
                    'receive_only' => $oldProfileAction === 'drain',
                    'status' => $oldProfileAction === 'drain'
                        ? ConnectionProfileStatus::RECEIVE_ONLY
                        : ConnectionProfileStatus::INACTIVE,
                ])->save();
            }

            $beforeTarget = $lockedTarget->safeSnapshot();
            $lockedTarget->forceFill([
                'is_primary' => true,
                'primary_slot' => 1,
                'receive_only' => false,
                'status' => ConnectionProfileStatus::ACTIVE,
            ])->save();

            $this->audit->record('whatsapp.connection_primary_changed', $actor, $lockedTarget, $beforeTarget, [
                'new_primary' => $lockedTarget->safeSnapshot(),
                'old_primary' => $oldSnapshot,
            ]);
            if ($current !== null) {
                $this->audit->record(
                    $oldProfileAction === 'drain'
                        ? 'whatsapp.connection_profile_moved_to_receive_only'
                        : 'whatsapp.connection_profile_deactivated',
                    $actor,
                    $current,
                    $oldSnapshot,
                    $current->safeSnapshot(),
                );
            }

            return $lockedTarget->refresh();
        });
    }

    public function moveToReceiveOnly(User $actor, WhatsAppConnectionProfile $profile): WhatsAppConnectionProfile
    {
        $this->authorize($actor);

        return $this->setNonPrimaryState($actor, $profile, ConnectionProfileStatus::RECEIVE_ONLY);
    }

    public function deactivate(User $actor, WhatsAppConnectionProfile $profile): WhatsAppConnectionProfile
    {
        $this->authorize($actor);

        return $this->setNonPrimaryState($actor, $profile, ConnectionProfileStatus::INACTIVE);
    }

    private function setNonPrimaryState(
        User $actor,
        WhatsAppConnectionProfile $profile,
        ConnectionProfileStatus $status,
    ): WhatsAppConnectionProfile {
        return DB::transaction(function () use ($actor, $profile, $status): WhatsAppConnectionProfile {
            $locked = WhatsAppConnectionProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            if ($locked->is_primary) {
                throw ValidationException::withMessages(['profile' => __('whatsapp.connections.primary_requires_switch')]);
            }
            $before = $locked->safeSnapshot();
            $locked->forceFill([
                'status' => $status,
                'receive_only' => $status === ConnectionProfileStatus::RECEIVE_ONLY,
                'primary_slot' => null,
                'is_primary' => false,
            ])->save();
            $this->audit->record(
                $status === ConnectionProfileStatus::RECEIVE_ONLY
                    ? 'whatsapp.connection_profile_moved_to_receive_only'
                    : 'whatsapp.connection_profile_deactivated',
                $actor,
                $locked,
                $before,
                $locked->safeSnapshot(),
            );

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function configuration(array $attributes): array
    {
        return [
            'name' => trim((string) ($attributes['name'] ?? '')),
            'display_phone_number' => trim((string) ($attributes['display_phone_number'] ?? '')),
            'normalized_phone_number' => self::normalizePhone((string) ($attributes['normalized_phone_number'] ?? $attributes['display_phone_number'] ?? '')),
            'provider_type' => strtolower(trim((string) ($attributes['provider_type'] ?? ''))),
            'provider_phone_number_id' => $this->nullIfBlank($attributes['provider_phone_number_id'] ?? null),
            'provider_account_id' => $this->nullIfBlank($attributes['provider_account_id'] ?? null),
            'provider_app_id' => $this->nullIfBlank($attributes['provider_app_id'] ?? null),
            'provider_metadata' => $this->safeMetadata($attributes['provider_metadata'] ?? null),
            'webhook_metadata' => $this->safeMetadata($attributes['webhook_metadata'] ?? null),
        ];
    }

    public static function normalizePhone(string $number): string
    {
        $normalized = preg_replace('/[\s().-]+/', '', trim($number)) ?? '';
        if (! ModuleState::validNumber($normalized)) {
            throw ValidationException::withMessages(['normalized_phone_number' => __('whatsapp.connections.invalid_phone')]);
        }

        return $normalized;
    }

    private function authorize(User $actor, bool $superAdmin = false): void
    {
        abort_unless(
            $actor->is_active
            && $actor->hasPermission('whatsapp.settings')
            && (! $superAdmin || $actor->hasRole('SUPER_ADMIN')),
            403,
        );
    }

    private function safeProviderText(
        string $value,
        WhatsAppConnectionProfile $profile,
        int $limit = 500,
    ): string {
        try {
            $secret = $profile->credential_secret;
        } catch (Throwable) {
            $secret = null;
        }
        if (is_string($secret) && $secret !== '') {
            $value = str_replace($secret, '[REDACTED]', $value);
        }

        return $this->safeText($value, $limit);
    }

    private function safeProviderNullable(
        ?string $value,
        WhatsAppConnectionProfile $profile,
    ): ?string {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->safeProviderText($value, $profile, 255);
    }

    /** @return array<string, mixed> */
    private function safeMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $this->assertMetadataHasNoSecrets($value);

        return $value;
    }

    /** @param array<array-key, mixed> $metadata */
    private function assertMetadataHasNoSecrets(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key)) {
                $normalized = strtolower(str_replace(['-', ' '], '_', $key));
                if (preg_match('/(^|_)(password|token|secret|credential|authorization|api_key|client_secret|access_token|refresh_token)$/', $normalized) === 1) {
                    throw ValidationException::withMessages([
                        'provider_metadata' => __('whatsapp.connections.sensitive_metadata'),
                    ]);
                }
            }
            if (is_array($value)) {
                $this->assertMetadataHasNoSecrets($value);
            }
        }
    }

    private function safeText(string $value, int $limit = 500): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return mb_substr(trim($clean), 0, $limit);
    }

    private function safeNullable(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->safeText($value, 255);
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
