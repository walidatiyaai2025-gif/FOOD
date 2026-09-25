<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppTranslation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TranslationCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class TranslationController extends Controller
{
    public function index(Request $request, TranslationCatalog $catalog): View
    {
        Gate::authorize('translations.manage');
        $catalog->syncDefaults();

        $search = trim((string) $request->query('q', ''));
        $surface = trim((string) $request->query('surface', ''));

        $translations = AppTranslation::query()
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('key', 'like', '%'.$search.'%')
                    ->orWhere('ar', 'like', '%'.$search.'%')
                    ->orWhere('en', 'like', '%'.$search.'%');
            }))
            ->when($surface !== '', fn ($query) => $query->where('surface', $surface))
            ->orderBy('surface')
            ->orderBy('key')
            ->paginate(25)
            ->withQueryString();

        return view('admin.translations', [
            'translations' => $translations,
            'search' => $search,
            'surface' => $surface,
            'surfaces' => AppTranslation::query()->distinct()->orderBy('surface')->pluck('surface'),
        ]);
    }

    public function update(Request $request, AppTranslation $translation, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('translations.manage');

        $validated = $request->validate([
            'ar' => ['required', 'string', 'max:5000'],
            'en' => ['required', 'string', 'max:5000'],
        ]);

        $before = $translation->toArray();
        $translation->update([
            ...$validated,
            'updated_by' => $request->user()?->id,
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $audit->record('translation.updated', $actor, $translation, $before, $translation->fresh()->toArray(), $request);

        return back()->with('status', __('admin.translations.saved'));
    }

    public function reset(
        Request $request,
        AppTranslation $translation,
        TranslationCatalog $catalog,
        AuditLogger $audit,
    ): RedirectResponse {
        Gate::authorize('translations.manage');

        $defaults = $catalog->defaultsFor($translation->key);
        abort_if($defaults === null, 404);

        $before = $translation->toArray();
        $translation->update([
            'ar' => $defaults['ar'],
            'en' => $defaults['en'],
            'updated_by' => $request->user()?->id,
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $audit->record('translation.reset', $actor, $translation, $before, $translation->fresh()->toArray(), $request);

        return back()->with('status', __('admin.translations.reset_done'));
    }
}
