<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StateManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly RuntimePolicy $policy) {}

    public function transition(User $actor, Lifecycle $lifecycle, int $revision): void
    {
        $this->authorize($actor, 'whatsapp.lifecycle', true);
        if (! in_array($lifecycle, [Lifecycle::DISABLED, Lifecycle::INTERNAL_TEST], true)) {
            throw ValidationException::withMessages(['lifecycle' => __('whatsapp.certification_required')]);
        }
        $this->mutate($actor, $revision, 'whatsapp.lifecycle_changed', ['lifecycle' => $lifecycle->value]);
    }

    /** @param array<string, bool> $capabilities
     * @param  list<string>  $testNumbers
     */
    public function configure(User $actor, array $capabilities, array $testNumbers, int $revision): void
    {
        $this->authorize($actor, 'whatsapp.settings');
        foreach ($capabilities as $key => $value) {
            if (Capability::tryFrom($key) === null || ! is_bool($value)) {
                throw ValidationException::withMessages(['capabilities' => __('whatsapp.invalid_capability')]);
            }
        }
        if (count($testNumbers) > 100) {
            throw ValidationException::withMessages(['test_numbers' => __('whatsapp.invalid_numbers')]);
        }
        foreach ($testNumbers as $number) {
            if (! is_string($number) || ! ModuleState::validNumber($number)) {
                throw ValidationException::withMessages(['test_numbers' => __('whatsapp.invalid_numbers')]);
            }
        }
        $this->mutate($actor, $revision, 'whatsapp.capabilities_changed', [
            'capabilities' => $capabilities,
            'test_numbers' => array_values(array_unique($testNumbers)),
        ]);
    }

    public function setEmergencyStop(User $actor, bool $stop, int $revision): void
    {
        $this->authorize($actor, 'whatsapp.emergency', true);
        // Engaging a stop is always safe, even from an older screen. Clearing it
        // requires the exact current revision so stale forms cannot resume sending.
        $this->mutate($actor, $stop ? null : $revision, $stop ? 'whatsapp.emergency_enabled' : 'whatsapp.emergency_disabled', ['emergency_stop' => $stop]);
    }

    public function activate(User $actor, int $revision): void
    {
        // Audit denials too; do not throw inside the transaction that stores them.
        $allowedActor = $actor->is_active && $actor->hasRole('SUPER_ADMIN') && $actor->hasPermission('whatsapp.activate');
        $accepted = DB::transaction(function () use ($actor, $revision, $allowedActor): bool {
            $row = $this->lockedRow();
            $before = $row->attributesToArray();
            $this->audit->record('whatsapp.activation_attempted', $actor, $row, $before, ['requested_lifecycle' => 'ENABLED']);
            if (! $allowedActor || (int) $row->getAttribute('revision') !== $revision || ! $this->policy->permitsActivation($actor, ModuleState::fromModel($row))) {
                $this->audit->record('whatsapp.activation_rejected', $actor, $row, $before, ['reason' => $allowedActor ? 'readiness_or_revision' : 'authorization']);

                return false;
            }
            $row->forceFill(['lifecycle' => Lifecycle::ENABLED->value, 'revision' => $revision + 1])->save();
            $this->audit->record('whatsapp.lifecycle_changed', $actor, $row, $before, $row->attributesToArray());

            return true;
        });
        abort_unless($allowedActor, 403);
        if (! $accepted) {
            throw ValidationException::withMessages(['activation' => __('whatsapp.certification_required')]);
        }
    }

    private function authorize(User $actor, string $permission, bool $superAdmin = false): void
    {
        abort_unless($actor->is_active && $actor->hasPermission($permission) && (! $superAdmin || $actor->hasRole('SUPER_ADMIN')), 403);
    }

    private function lockedRow(): WhatsAppModuleState
    {
        $row = WhatsAppModuleState::query()->lockForUpdate()->find(1);
        abort_if($row === null, 503, __('whatsapp.unavailable'));

        return $row;
    }

    /** @param array<string, mixed> $changes */
    private function mutate(User $actor, ?int $revision, string $event, array $changes): void
    {
        DB::transaction(function () use ($actor, $revision, $event, $changes): void {
            $row = $this->lockedRow();
            if ($revision !== null && (int) $row->getAttribute('revision') !== $revision) {
                throw ValidationException::withMessages(['revision' => __('whatsapp.stale')]);
            }
            $before = $row->attributesToArray();
            $row->forceFill($changes);
            if (! $row->isDirty()) {
                return;
            }
            $row->setAttribute('revision', (int) $row->getAttribute('revision') + 1);
            $row->save();
            $this->audit->record($event, $actor, $row, $before, $row->attributesToArray());
        });
    }
}
