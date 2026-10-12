<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FoodexResetService;
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
    public function index(Request $request, FoodexResetService $reset): View
    {
        $this->authorizeOperator($request);
        $preview = $reset->preview();
        $request->session()->put('launch_reset_preview', [
            'fingerprint' => $preview['fingerprint'],
            'expires_at' => now()->addMinutes(5)->timestamp,
            'operation' => (string) Str::uuid(),
        ]);

        return view('admin.foodex-reset', ['preview' => $preview]);
    }

    public function store(Request $request, FoodexResetService $reset): RedirectResponse
    {
        $actor = $this->authorizeOperator($request);
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
        if (app()->environment('production')) {
            $rules['production_confirmation'] = ['required', 'in:'.FoodexResetService::PRODUCTION_CONFIRMATION];
            $messages['production_confirmation.required'] = __('foodex_reset.production_confirmation_error');
            $messages['production_confirmation.in'] = __('foodex_reset.production_confirmation_error');
        }
        $request->validate($rules, $messages);
        if (! Hash::check((string) $request->input('current_password'), $actor->password)) {
            throw ValidationException::withMessages(['current_password' => __('foodex_reset.password_error')]);
        }

        // One preview authorizes one attempt. A timeout/error requires a fresh impact preview,
        // never an unsafe blind retry. A double submission cannot consume the same preview.
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

    private function authorizeOperator(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active && $actor->hasRole('SUPER_ADMIN'), 403);
        app()->setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        return $actor;
    }
}
