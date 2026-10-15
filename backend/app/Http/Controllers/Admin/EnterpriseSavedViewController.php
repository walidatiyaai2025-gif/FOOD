<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseSavedView;
use App\Models\User;
use App\Services\EnterpriseSavedViewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EnterpriseSavedViewController extends Controller
{
    public function store(Request $request, EnterpriseSavedViewService $savedViews): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'surface_key' => ['required', 'string', 'max:120', 'regex:/^[a-z][a-z0-9._-]{0,119}$/'],
            'scope_type' => ['required', Rule::in([
                'platform',
                'organization',
                'company',
                'legal_entity',
                'business_unit',
                'store',
            ])],
            'scope_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:120'],
            'visibility' => ['required', Rule::in(['private', 'shared'])],
            'state_token' => ['required', 'string', 'max:12000'],
        ]);

        $savedViews->createFromToken(
            $actor,
            (string) $validated['surface_key'],
            (string) $validated['scope_type'],
            isset($validated['scope_id']) ? (int) $validated['scope_id'] : null,
            (string) $validated['name'],
            (string) $validated['visibility'],
            (string) $validated['state_token'],
        );

        return back()->with('status', __('enterprise_grid.saved'));
    }

    public function destroy(
        Request $request,
        EnterpriseSavedView $savedView,
        EnterpriseSavedViewService $savedViews,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $savedViews->delete($actor, $savedView);

        return back()->with('status', __('enterprise_grid.deleted'));
    }
}
