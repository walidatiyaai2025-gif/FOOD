<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AppPreviewInvalidationStream;
use App\Services\AppPreviewSessionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AppPreviewInvalidationController extends Controller
{
    public function __invoke(
        Request $request,
        AppPreviewSessionService $sessions,
        AppPreviewInvalidationStream $stream,
    ): StreamedResponse {
        $token = $request->header('X-Foodex-Preview-Token');
        abort_unless(
            is_string($token) && trim($token) !== '',
            401,
            'Preview token is required.',
        );

        $session = $sessions->resolve($token, $request);
        abort_unless($session->target_type === 'customer', 404);
        abort_unless((string) $session->mode === 'read_only', 403);
        abort_unless($session->store_id !== null, 401);

        return $stream->response(
            (int) $session->store_id,
            (string) $session->channel,
            $stream->lastEventId($request->header('Last-Event-ID')),
        );
    }
}
