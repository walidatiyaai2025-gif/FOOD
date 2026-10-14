<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProductionLaunchPreflight;
use App\Services\ProductionLaunchReport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ProductionLaunchController extends Controller
{
    public function index(Request $request, ProductionLaunchPreflight $preflight): View
    {
        $actor = $this->authorizeOwner($request);

        return view('admin.production-launch', [
            'report' => $preflight->runtimeReport(),
            'release' => $preflight->releaseMetadata(),
            'actor' => $actor,
        ]);
    }

    public function report(
        Request $request,
        ProductionLaunchPreflight $preflight,
        ProductionLaunchReport $report,
    ): JsonResponse {
        $this->authorizeOwner($request);
        $payload = $report->build($preflight);
        $source = is_string($payload['source_sha'] ?? null)
            ? substr($payload['source_sha'], 0, 12)
            : 'unbound';

        return response()->json(
            $payload,
            200,
            [
                'Content-Disposition' => 'attachment; filename="FOODEX-1.0.77-launch-report-'.$source.'.json"',
                'Cache-Control' => 'no-store, private',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }

    public function store(
        Request $request,
        ProductionLaunchPreflight $preflight,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->authorizeOwner($request);

        $data = $request->validate([
            'confirmation' => ['required', 'string'],
            'current_password' => ['required', 'string'],
        ], [
            'confirmation.required' => __('production_launch.errors.confirmation'),
            'current_password.required' => __('production_launch.errors.password_required'),
        ]);

        if (! Hash::check($data['current_password'], $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('production_launch.errors.password'),
            ]);
        }

        $report = $preflight->runtimeReport();
        $preflight->approve(
            $actor,
            $request,
            $report,
            $data['confirmation'],
            $audit,
        );
        $preflight->publishResetPreflightEvidence($report);

        return redirect('/admin/settings/production-launch')
            ->with('status', __('production_launch.approved'));
    }

    private function authorizeOwner(Request $request): User
    {
        $actor = $request->user();
        abort_unless(
            $actor instanceof User
            && $actor->is_active
            && $actor->hasRole('SUPER_ADMIN'),
            403,
        );

        app()->setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        return $actor;
    }
}
