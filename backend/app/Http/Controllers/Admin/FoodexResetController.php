<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
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

final class FoodexResetController extends Controller
{
    private const REVERT_CONFIRMATION = 'REVERT TO LEGACY';

    public function index(Request $request, FoodexResetService $reset, ProductionDatasetSnapshot $snapshot): View
    {
        $this->authorizeOperator($request);
        $preview = $reset->preview();
        $request->session()->put('launch_reset_preview', [
            'fingerprint' => $preview['fingerprint'],
            'expires_at' => now()->addMinutes(5)->timestamp,
            'operation' => (string) Str::uuid(),
        ]);

        $snapshotStatus = $snapshot->status();
        $lastTransition = AuditLog::query()
            ->whereIn('event', [
                'system.production_snapshot.launch_active',
                'system.production_snapshot.restored',
                'system.production_snapshot.restore_failed',
            ])
            ->latest('id')
            ->first(['id', 'event', 'created_at']);

        return view('admin.foodex-reset', [
            'preview' => $preview,
            'production' => $reset->isProduction(),
            'snapshotStatus' => $snapshotStatus,
            'lastTransition' => $lastTransition,
            'revertConfirmation' => self::REVERT_CONFIRMATION,
        ]);
    }

    public function store(Request $request, FoodexResetService $reset, ?ProductionDatasetSnapshot $snapshot = null): RedirectResponse
    {
        $actor = $this->authorizeOperator($request);
        $action = (string) $request->input('dataset_action', 'launch');

        if ($action === 'revert') {
            return $this->revert($request, $actor, $snapshot ?? app(ProductionDatasetSnapshot::class));
        }

        abort_unless($action === 'launch', 422);
        $rules = [
            'confirmation' => ['required', 'in:'.__('foodex_reset.phrase')],
            'understood' => ['accepted'],
            'current_password' => ['required', 'string'],
        ];
        $messages = [
            'confirmation.required' => __('foodex_reset.confirmation_error'),
            'confirmation.in' => __('foodex_reset.confirmation_error'),
            'understood.accepted' => __('foodex_reset.acknowledgment_error'),
            'current_password.required' => __('foodex_reset.password_required'),
        ];
        if ($reset->isProduction()) {
            $rules['production_confirmation'] = ['required', 'in:'.FoodexResetService::PRODUCTION_CONFIRMATION];
            $messages['production_confirmation.required'] = __('foodex_reset.production_confirmation_error');
            $messages['production_confirmation.in'] = __('foodex_reset.production_confirmation_error');
        }
        $request->validate($rules, $messages);
        $this->assertPassword($request, $actor);

        $preview = $request->session()->pull('launch_reset_preview');
        if (! is_array($preview) || ($preview['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['reset' => __('foodex_reset.stale')]);
        }
        $request->session()->put('launch_reset_operation', $preview['operation']);

        try {
            $reset->execute($preview, $actor, $request);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['reset' => __('foodex_reset.busy')]);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['reset' => __('foodex_reset.failed')]);
        } finally {
            $request->session()->forget('launch_reset_operation');
        }

        return redirect()->route('admin.foodex-reset.index')->with('status', __('foodex_reset.success'));
    }

    private function revert(Request $request, User $actor, ProductionDatasetSnapshot $snapshot): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'in:'.self::REVERT_CONFIRMATION],
            'understood' => ['accepted'],
            'current_password' => ['required', 'string'],
        ], [
            'confirmation.required' => __('foodex_reset.revert_confirmation_error'),
            'confirmation.in' => __('foodex_reset.revert_confirmation_error'),
            'understood.accepted' => __('foodex_reset.revert_acknowledgment_error'),
            'current_password.required' => __('foodex_reset.password_required'),
        ]);
        $this->assertPassword($request, $actor);

        try {
            $snapshot->restore($actor, $request);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['reset' => __('foodex_reset.busy')]);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['reset' => __('foodex_reset.revert_failed')]);
        }

        return redirect()->route('admin.foodex-reset.index')->with('status', __('foodex_reset.revert_success'));
    }

    private function assertPassword(Request $request, User $actor): void
    {
        if (! Hash::check((string) $request->input('current_password'), $actor->password)) {
            throw ValidationException::withMessages(['current_password' => __('foodex_reset.password_error')]);
        }
    }

    private function authorizeOperator(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active && $actor->hasRole('SUPER_ADMIN'), 403);
        app()->setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        return $actor;
    }
}
