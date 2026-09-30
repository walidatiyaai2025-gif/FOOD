<?php

namespace App\Http\Middleware;

use App\Models\AppPreviewSession;
use App\Models\User;
use App\Services\AppPreviewSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveCustomerPreviewSession
{
    public function __construct(
        private readonly AppPreviewSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next, string $channel): Response
    {
        abort_unless(in_array($request->method(), ['GET', 'HEAD'], true), 405);

        $expectedChannel = strtolower(trim($channel));
        abort_unless(in_array($expectedChannel, ['b2b', 'b2c'], true), 500);

        $credential = $request->header('X-Foodex-Preview-Token');
        abort_unless(
            is_string($credential) && trim($credential) !== '',
            401,
            'Preview credential is required.',
        );

        $session = $this->sessions->resolve($credential, $request);
        abort_unless(
            $session instanceof AppPreviewSession
                && (string) $session->target_type === 'customer'
                && (string) $session->mode === 'read_only',
            403,
            'Customer read-only preview session is required.',
        );
        abort_unless(
            strtolower((string) $session->channel) === $expectedChannel,
            403,
            'Preview channel does not match this read contract.',
        );

        $storeId = (int) ($session->store_id ?? 0);
        abort_unless($storeId > 0, 401, 'Preview store context is invalid.');

        $this->assertRequestedStore($request, $storeId);

        $target = User::query()
            ->whereKey($session->target_user_id)
            ->where('is_active', true)
            ->first();
        abort_unless($target instanceof User, 401, 'Preview target is no longer active.');

        // The preview credential is never allowed to become or coexist with
        // a normal Customer/guest/support credential inside this bridge.
        $request->headers->remove('Authorization');
        $request->headers->remove('X-Guest-Token');
        $request->headers->remove('X-FOODEX-Retail-Store-ID');
        $request->headers->remove('X-FOODEX-Support-Access');

        $request->attributes->set('app_preview_session', $session);
        $request->attributes->set('app_preview_store_id', $storeId);
        $request->attributes->set('app_preview_channel', $expectedChannel);
        $request->attributes->set('app_preview_read_only', true);
        $request->headers->set('X-FOODEX-Customer-Domain', $expectedChannel);
        $request->headers->set('X-FOODEX-Store-ID', (string) $storeId);

        $request->setUserResolver(static fn (): User => $target);

        return $next($request);
    }

    private function assertRequestedStore(Request $request, int $storeId): void
    {
        $candidates = [
            $request->route('store'),
            $request->query('store_id'),
            $request->query('store'),
            $request->header('X-FOODEX-Store-ID'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            abort_unless(
                is_numeric($candidate) && (int) $candidate === $storeId,
                404,
                'Preview store context does not match this request.',
            );
        }
    }
}
