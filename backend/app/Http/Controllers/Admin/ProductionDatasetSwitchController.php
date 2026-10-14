<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Updater\UpdateRuntime;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FoodexResetService;
use App\Services\ProductionDatasetSnapshot;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProductionDatasetSwitchController extends Controller
{
    public const LAUNCH_CONFIRMATION = 'SWITCH TO FOODEX LAUNCH';
    public const LEGACY_CONFIRMATION = 'RESTORE LEGACY DATA';

    public function index(Request $request, ProductionDatasetSnapshot $snapshot, FoodexResetService $reset): View
    {
        $this->authorizeOwner($request);
        $status = $snapshot->status();
        $lastTransition = AuditLog::query()
            ->whereIn('event', ['system.production_dataset.switched_to_launch', 'system.production_dataset.switched_to_legacy'])
            ->latest('id')->first(['id', 'event', 'created_at']);

        return view('admin.production-dataset-switch', [
            'dataset' => $status,
            'preview' => $reset->preview(),
            'activeMode' => $this->activeMode($status),
            'lastTransition' => $lastTransition,
        ]);
    }

    public function launch(Request $request, FoodexResetService $reset, ProductionDatasetSnapshot $snapshot, UpdateRuntime $runtime, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorizeOwner($request);
        $this->validateConfirmation($request, self::LAUNCH_CONFIRMATION);
        $state = $snapshot->status()['state'] ?? ProductionDatasetSnapshot::NO_SNAPSHOT;

        if ($state === ProductionDatasetSnapshot::LAUNCH_ACTIVE) {
            return redirect()->route('admin.production-dataset-switch.index')->with('status', __('production_dataset_switch.already_launch'));
        }
        if (! in_array($state, [ProductionDatasetSnapshot::NO_SNAPSHOT, ProductionDatasetSnapshot::SNAPSHOT_READY, ProductionDatasetSnapshot::LEGACY_RESTORED], true)) {
            throw ValidationException::withMessages(['switch' => __('production_dataset_switch.errors.state')]);
        }

        $enteredMaintenance = false;
        try {
            if (! app()->isDownForMaintenance()) {
                $runtime->enterMaintenanceMode();
                $enteredMaintenance = true;
            }
            $preview = $reset->preview();
            if (! $preview['ready']) {
                throw ValidationException::withMessages(['switch' => __('production_dataset_switch.errors.not_ready')]);
            }
            $operation = (string) Str::uuid();
            $request->session()->put('launch_reset_operation', $operation);
            $result = $reset->execute($preview, $actor, $request);
            $audit->record('system.production_dataset.switched_to_launch', $actor, null, null, [
                'operation_id' => $operation,
                'removed_rows' => $result['removed'] ?? null,
                'snapshot_id' => $snapshot->status()['snapshot_id'] ?? null,
            ], $request);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['switch' => __('production_dataset_switch.errors.busy')]);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['switch' => __('production_dataset_switch.errors.failed')]);
        } finally {
            $request->session()->forget('launch_reset_operation');
            if ($enteredMaintenance && app()->isDownForMaintenance()) {
                try { $runtime->exitMaintenanceMode(); } catch (Throwable $exception) { report($exception); }
            }
        }

        return redirect()->route('admin.production-dataset-switch.index')->with('status', __('production_dataset_switch.launch_success'));
    }

    public function legacy(Request $request, ProductionDatasetSnapshot $snapshot, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorizeOwner($request);
        $this->validateConfirmation($request, self::LEGACY_CONFIRMATION);
        try {
            $before = $snapshot->status();
            $after = $snapshot->restore($actor, $request);
            $audit->record('system.production_dataset.switched_to_legacy', $actor, null, $before, $after, $request);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['switch' => __('production_dataset_switch.errors.busy')]);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['switch' => __('production_dataset_switch.errors.restore_failed')]);
        }
        return redirect()->route('admin.production-dataset-switch.index')->with('status', __('production_dataset_switch.legacy_success'));
    }

    private function validateConfirmation(Request $request, string $phrase): void
    {
        $data = $request->validate(['confirmation'=>['required','string'],'current_password'=>['required','string'],'understood'=>['accepted']]);
        if (! hash_equals($phrase, $data['confirmation'])) {
            throw ValidationException::withMessages(['confirmation' => __('production_dataset_switch.errors.confirmation')]);
        }
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => __('production_dataset_switch.errors.password')]);
        }
    }

    private function authorizeOwner(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active && $actor->hasRole('SUPER_ADMIN'), 403);
        app()->setLocale(in_array($actor->locale, ['ar','en'], true) ? $actor->locale : 'ar');
        return $actor;
    }

    private function activeMode(array $status): string
    {
        return match ($status['state'] ?? ProductionDatasetSnapshot::NO_SNAPSHOT) {
            ProductionDatasetSnapshot::LAUNCH_ACTIVE => 'launch',
            ProductionDatasetSnapshot::RESTORING => 'restoring',
            ProductionDatasetSnapshot::FAILED => 'failed',
            default => 'legacy',
        };
    }
}
