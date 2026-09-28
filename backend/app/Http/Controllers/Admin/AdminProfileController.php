<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AdminProfileController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->actor($request);
        $storeAssignments = $user->storeRoleAssignments()
            ->with(['store:id,name,code,is_active', 'role:id,code,name,scope,is_active'])
            ->orderBy('store_id')
            ->get();

        $storePermissions = [];
        foreach ($storeAssignments->pluck('store')->filter()->unique('id') as $store) {
            $storePermissions[(int) $store->id] = $user->effectivePermissionCodes((int) $store->id);
        }

        return view('admin.profile', [
            'user' => $user,
            'navGroups' => app(AdminNavigation::class)->groupsFor($user),
            'navContext' => 'profile',
            'globalRoles' => $user->roles()->where('roles.is_active', true)->orderBy('roles.name')->get(['roles.id', 'roles.code', 'roles.name', 'roles.scope']),
            'storeAssignments' => $storeAssignments,
            'globalPermissions' => $user->effectivePermissionCodes(),
            'storePermissions' => $storePermissions,
        ]);
    }

    public function updatePassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        if (! Hash::check((string) $data['current_password'], (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => [$this->msg('كلمة المرور الحالية غير صحيحة.', 'The current password is incorrect.')],
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()->delete();

        $audit->record('profile.password.changed', $user, $user, null, ['api_tokens_revoked' => true], $request);

        return back()->with('status', $this->msg(
            'تم تغيير كلمة المرور وإلغاء جلسات API القديمة بنجاح.',
            'Password changed and previous API sessions were revoked successfully.',
        ));
    }

    public function updateLocale(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $this->actor($request);
        $data = $request->validate(['locale' => ['required', 'in:ar,en']]);
        $before = ['locale' => $user->locale];

        $user->forceFill(['locale' => $data['locale']])->save();
        $request->session()->put('admin_login_locale', $data['locale']);

        $audit->record('profile.locale.changed', $user, $user, $before, ['locale' => $data['locale']], $request);

        return back()->with('status', $data['locale'] === 'ar'
            ? 'تم تغيير لغة لوحة الإدارة إلى العربية.'
            : 'Administration language changed to English.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
