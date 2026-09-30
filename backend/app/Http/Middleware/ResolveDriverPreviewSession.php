<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use App\Models\User;
use App\Services\AppPreviewSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveDriverPreviewSession
{
    public function __construct(
        private readonly AppPreviewSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(in_array($request->method(), ['GET', 'HEAD'], true), 405);

        abort_if(
            $request->bearerToken() !== null,
            401,
            'Production bearer credentials are not accepted by the preview bridge.',
        );

        $credential = $request->header('X-Foodex-Preview-Token');
        abort_unless(
            is_string($credential) && trim($credential) !== '',
            401,
            'Preview credential is required.',
        );

        $session = $this->sessions->resolve($credential, $request);
        abort_unless(
            (string) $session->target_type === 'driver'
                && (string) $session->mode === 'read_only',
            403,
            'Driver read-only preview session is required.',
        );

        $storeId = (int) ($session->store_id ?? 0);
        abort_unless($storeId > 0, 401, 'Preview store context is invalid.');

        $target = User::query()
            ->whereKey($session->target_user_id)
            ->where('is_active', true)
            ->first();
        abort_unless($target instanceof User, 401, 'Preview target is no longer active.');

        $driver = Driver::query()
            ->where('user_id', $target->getKey())
            ->where('is_active', true)
            ->first();
        abort_unless($driver instanceof Driver, 401, 'Preview driver is no longer active.');
        abort_unless(
            strtolower((string) $driver->driver_type) === strtolower((string) $session->channel),
            403,
            'Preview driver channel changed.',
        );
        abort_unless(
            (int) ($driver->store_id ?? 0) === $storeId,
            403,
            'Preview driver store changed.',
        );

        $request->headers->remove('Authorization');
        $request->headers->remove('X-Guest-Token');
        $request->headers->remove('X-FOODEX-Retail-Store-ID');
        $request->headers->remove('X-FOODEX-Store-ID');
        $request->headers->remove('X-FOODEX-Support-Access');

        $request->attributes->set('app_preview_session', $session);
        $request->attributes->set('app_preview_store_id', $storeId);
        $request->attributes->set('app_preview_channel', (string) $session->channel);
        $request->attributes->set('app_preview_read_only', true);
        $request->setUserResolver(static fn (): User => $target);

        return $next($request);
    }
}
