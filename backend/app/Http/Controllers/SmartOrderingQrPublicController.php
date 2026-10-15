<?php

namespace App\Http\Controllers;

use App\Services\SmartOrderingAttributionService;
use App\Services\SmartOrderingQrManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SmartOrderingQrPublicController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        SmartOrderingQrManager $manager,
        SmartOrderingAttributionService $attribution,
    ): RedirectResponse {
        $resolved = $manager->resolve($token);

        $visitorToken = $request->cookie('foodex_so_visitor');
        if (! is_string($visitorToken) || preg_match('/^[A-Za-z0-9_-]{32,96}$/', $visitorToken) !== 1) {
            $visitorToken = Str::random(48);
        }

        $visit = $attribution->startLanding(
            $resolved['qr'],
            $resolved['token'],
            $visitorToken,
            $request->headers->get('referer'),
        );
        $context = $attribution->signedContext($visit);
        $target = $this->targetWithContext((string) $resolved['qr']->target_path, $context);

        $response = redirect()->to($target);
        $secure = $request->isSecure();

        $response->withCookie(cookie('foodex_so_visitor', $visitorToken, 60 * 24 * 365, '/', null, $secure, true, false, 'Lax'));
        $response->withCookie(cookie('foodex_so_attribution', $context, 60 * 24, '/', null, $secure, true, false, 'Lax'));

        return $response;
    }

    private function targetWithContext(string $target, string $context): string
    {
        $fragment = '';
        if (str_contains($target, '#')) {
            [$target, $fragmentValue] = explode('#', $target, 2);
            $fragment = '#'.$fragmentValue;
        }

        $separator = str_contains($target, '?') ? '&' : '?';

        return $target.$separator.http_build_query(['so_ctx' => $context]).$fragment;
    }
}
