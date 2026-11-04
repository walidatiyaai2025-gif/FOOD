<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DocumentBrandingService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DocumentBrandingController extends Controller
{
    public function index(Request $request, DocumentBrandingService $branding): View
    {
        $this->authorizeSettings($request, false);

        $user = $request->user();

        return view('admin.document-branding', [
            'user' => $user,
            'navGroups' => app(AdminNavigation::class)->groupsFor($user),
            'navContext' => 'administration_hub',
            'identity' => $branding->identity(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeSettings($request, true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'address' => ['required', 'string', 'min:3', 'max:300'],
            'phone_primary' => ['required', 'string', 'min:3', 'max:40'],
            'phone_secondary' => ['nullable', 'string', 'max:40'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:1024'],
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('document-branding', 'public');
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Could not save document logo.');
            }
            $validated['logo_path'] = $path;
        }

        DB::transaction(static function () use ($validated): void {
            foreach (['name', 'address', 'phone_primary', 'phone_secondary', 'logo_path'] as $key) {
                if (! array_key_exists($key, $validated)) {
                    continue;
                }
                DB::table('settings')->updateOrInsert(
                    ['store_id' => null, 'key' => 'documents.'.$key],
                    [
                        'value' => json_encode($validated[$key] ?? '', JSON_THROW_ON_ERROR),
                        'is_secret' => false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        });

        return redirect()->route('admin.documents.branding.index')
            ->with('status', __('document_branding.saved'));
    }

    private function authorizeSettings(Request $request, bool $write): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $canRead = $user->hasRole('SUPER_ADMIN')
            || $user->hasPermission('settings.view')
            || $user->hasPermission('settings.manage');
        // Global printed identity is platform-scoped and must never be editable
        // by a tenant-scoped STORE_ADMIN with operational settings access.
        $canWrite = $user->hasRole('SUPER_ADMIN');

        abort_unless($write ? $canWrite : $canRead, 403);
    }
}
