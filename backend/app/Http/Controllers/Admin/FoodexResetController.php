<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FoodexResetService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

final class FoodexResetController extends Controller
{
    public function __construct(
        private readonly FoodexResetService $reset,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);
        $this->useActorLocale($actor);

        return view('admin.foodex-reset', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'preview' => $this->reset->preview(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);
        $this->useActorLocale($actor);

        $validated = $request->validate([
            'confirmation' => ['required', 'string', Rule::in([FoodexResetService::CONFIRMATION])],
            'production_confirmation' => ['nullable', 'string', Rule::in([FoodexResetService::PRODUCTION_CONFIRMATION])],
            'backup_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $this->reset->execute($actor, $request, $validated);

        return redirect()
            ->route('admin.foodex-reset.index')
            ->with('status', __('foodex_reset.completed'));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function useActorLocale(User $actor): void
    {
        App::setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');
    }
}
