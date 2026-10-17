<?php

namespace App\Http\Controllers;

use App\Enterprise\Procurement\ProcurementCompletionManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $validated = $request->validate([
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.rfq_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,xlsx,xls,doc,docx', 'max:10240'],
        ]);
        $this->procurement->submitPortalQuote(
            $token,
            $validated,
            $request->file('attachment'),
        );

        return redirect()
            ->route('supplier.rfq.show', ['token' => $token])
            ->with('status', __('enterprise_procurement.portal.quote_saved'));
    }
}
