<?php

namespace App\Services;

use App\Models\AdminSecuritySession;
use App\Models\User;
use Illuminate\Http\Request;

final class AdminSessionRegistry
{
    public function ensure(Request $request, User $user): AdminSecuritySession
    {
        $hash = hash('sha256', $request->session()->getId());
        $boundPublicId = $request->session()->get('admin_security_session_public_id');
        $session = null;

        if (is_string($boundPublicId) && $boundPublicId !== '') {
            $session = AdminSecuritySession::query()
                ->where('public_id', $boundPublicId)
                ->first();

            if ($session !== null) {
                abort_unless((int) $session->user_id === (int) $user->id, 403);

                if ($session->revoked_at !== null) {
                    abort(403, 'This management session has been revoked.');
                }
            }
        }

        $session ??= AdminSecuritySession::query()->firstOrNew(['session_hash' => $hash]);

        if ($session->exists && $session->revoked_at !== null) {
            abort(403, 'This management session has been revoked.');
        }

        if (! $session->exists) {
            $session->user_id = $user->id;
        }

        abort_unless((int) $session->user_id === (int) $user->id, 403);

        if (! $session->exists || $session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinute())) {
            $session->forceFill([
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
                'last_seen_at' => now(),
            ])->save();
        }

        $request->session()->put('admin_security_session_public_id', $session->public_id);

        return $session;
    }

    public function markMfaVerified(Request $request, User $user): AdminSecuritySession
    {
        $session = $this->ensure($request, $user);
        $session->forceFill(['mfa_verified_at' => now()])->save();
        $request->session()->put('admin_mfa_verified_at', now()->toIso8601String());

        return $session;
    }

    public function revoke(AdminSecuritySession $session, User $actor): void
    {
        if ($session->revoked_at !== null) {
            return;
        }

        $session->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
        ])->save();
    }

    public function revokeForUser(User $user, User $actor): int
    {
        return AdminSecuritySession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'updated_at' => now(),
            ]);
    }
}
