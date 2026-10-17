<?php

namespace App\Http\Controllers;

use App\Enterprise\Procurement\ProcurementCompletionManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

final class SupplierRfqPortalController extends Controller
{
    public function __construct(
        private readonly ProcurementCompletionManager $procurement,
    ) {}

    public function show(string $token): View
    {
        return view('supplier.rfq-portal', [
            ...$this->procurement->portal($token),
            'token' => $token,
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $this->procurement->submitPortalQuote($token, $request->all());

        return redirect()
            ->route('supplier.rfq.show', ['token' => $token])
            ->with('status', __('enterprise_procurement.portal.quote_saved'));
    }
}
