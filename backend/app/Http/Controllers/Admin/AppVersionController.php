<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use App\Models\User;
use App\Services\AppVersionPolicy;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AppVersionController extends Controller
{
    public function index(): View
    {
        Gate::authorize('platform.manage');

        return view('admin.app-versions', [
            'policies' => AppVersion::query()->orderBy('app')->orderBy('platform')->get(),
        ]);
    }

    public function store(Request $request, AppVersionPolicy $evaluator, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('platform.manage');

        $validated = $request->validate([
            'app' => ['required', 'in:customer,driver'],
            'platform' => ['required', 'in:android,ios'],
            'latest_version' => ['required', 'string', 'max:64'],
            'minimum_supported_version' => ['required', 'string', 'max:64'],
            'force_update' => ['nullable', 'boolean'],
            'store_url' => ['required', 'url', 'max:2048'],
            'release_notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'required' => __('app_versions.validation.required'),
            'in' => __('app_versions.validation.in'),
            'string' => __('app_versions.validation.string'),
            'max' => __('app_versions.validation.max'),
            'boolean' => __('app_versions.validation.boolean'),
            'url' => __('app_versions.validation.url'),
        ], [
            'app' => __('app_versions.app'),
            'platform' => __('app_versions.platform'),
            'latest_version' => __('app_versions.latest_version'),
            'minimum_supported_version' => __('app_versions.minimum_supported_version'),
            'force_update' => __('app_versions.force_update'),
            'store_url' => __('app_versions.store_url'),
            'release_notes' => __('app_versions.release_notes'),
        ]);

        try {
            $evaluator->assertPolicyOrder($validated['minimum_supported_version'], $validated['latest_version']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['minimum_supported_version' => __('app_versions.invalid_policy')]);
        }

        $before = AppVersion::query()
            ->where('app', $validated['app'])
            ->where('platform', $validated['platform'])
            ->first();

        $policy = AppVersion::query()->updateOrCreate(
            ['app' => $validated['app'], 'platform' => $validated['platform']],
            [
                'latest_version' => $validated['latest_version'],
                'minimum_supported_version' => $validated['minimum_supported_version'],
                'force_update' => $request->boolean('force_update'),
                'store_url' => $validated['store_url'],
                'release_notes' => $validated['release_notes'] ?? null,
            ],
        );

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $audit->record('app_version_policy.updated', $actor, $policy, $before?->toArray(), $policy->toArray(), $request);

        return redirect()->route('admin.app-versions.index')->with('status', __('app_versions.saved'));
    }
}
