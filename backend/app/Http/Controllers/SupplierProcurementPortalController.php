<?php

namespace App\Http\Controllers;

use App\Enterprise\Procurement\SupplierCollaborationManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

final class SupplierProcurementPortalController extends Controller
{
    public function __construct(private readonly SupplierCollaborationManager $collaboration) {}

    public function index(Request $request, string $token): View
    {
        $this->locale($request);
        $data = $this->collaboration->portalData($token);

        return view('supplier-procurement-portal', [
            ...$data,
            'plainToken' => $token,
        ]);
    }

    public function quote(Request $request, string $token, int $rfq): RedirectResponse
    {
        $this->locale($request);
        $this->collaboration->submitQuote($token, $rfq, $request->only([
            'supplier_reference', 'lead_time_days', 'valid_until', 'notes', 'lines',
        ]));

        return $this->back($request, $token)
            ->with('status', __('supplier_procurement_portal.flash.quote_submitted'));
    }

    public function decline(Request $request, string $token, int $rfq): RedirectResponse
    {
        $this->locale($request);
        $this->collaboration->declineRfq($token, $rfq);

        return $this->back($request, $token)
            ->with('status', __('supplier_procurement_portal.flash.rfq_declined'));
    }

    public function document(Request $request, string $token): RedirectResponse
    {
        $this->locale($request);
        $this->collaboration->registerPortalDocument($token, $request->only([
            'category', 'title', 'reference', 'expires_at',
        ]));

        return $this->back($request, $token)
            ->with('status', __('supplier_procurement_portal.flash.document_registered'));
    }

    private function locale(Request $request): void
    {
        $locale = (string) $request->input('locale', $request->query('locale', App::getLocale()));
        if (in_array($locale, ['ar', 'en'], true)) {
            App::setLocale($locale);
        }
    }

    private function back(Request $request, string $token): RedirectResponse
    {
        return redirect()->route('supplier.procurement.index', [
            'token' => $token,
            'locale' => App::getLocale(),
        ]);
    }
}
