<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\OperationalTenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class InvoiceController extends Controller
{
    public function show(Request $request, int $invoice, InvoiceService $invoices): View
    {
        $user = $this->actor($request);
        $model = Invoice::query()->findOrFail($invoice);
        $this->authorizeInvoice($user, $model, 'finance.view');

        return view('admin.invoice', [
            'invoice' => $invoices->payload($model, true),
            'model' => $model,
            'canManage' => $this->can($user, $model, 'finance.manage'),
        ]);
    }

    public function download(Request $request, int $invoice, InvoiceService $invoices): Response
    {
        $validated = $request->validate(['locale' => ['nullable', Rule::in(['ar', 'en'])]]);
        $user = $this->actor($request);
        $model = Invoice::query()->findOrFail($invoice);
        $this->authorizeInvoice($user, $model, 'finance.view');
        $locale = (string) ($validated['locale'] ?? $user->locale ?? 'en');

        try {
            $content = $invoices->renderPdf($model, $locale);
        } catch (\RuntimeException) {
            abort(503, 'PDF generation is temporarily unavailable.');
        }

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$model->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function reissue(Request $request, int $invoice, InvoiceService $invoices): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $user = $this->actor($request);
        $model = Invoice::query()->findOrFail($invoice);
        $this->authorizeInvoice($user, $model, 'finance.manage');

        $replacement = $invoices->voidAndReissue($model, $user, (string) $validated['reason']);

        return redirect()
            ->route('admin.invoices.show', ['invoice' => $replacement->getKey()])
            ->with('status', app()->getLocale() === 'ar' ? 'تم إلغاء الفاتورة وإصدار مراجعة جديدة.' : 'Invoice voided and reissued as a new revision.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function authorizeInvoice(User $user, Invoice $invoice, string $permission): void
    {
        abort_unless($this->can($user, $invoice, $permission), 404);
    }

    private function can(User $user, Invoice $invoice, string $permission): bool
    {
        [$storeId, $channel] = $this->scope($invoice);

        if ($storeId === null || $channel === null) {
            return $user->hasRole('SUPER_ADMIN') || $user->hasPermission($permission);
        }

        return in_array(
            $storeId,
            app(OperationalTenantScope::class)->allowedStoreIds($user, $permission, $channel),
            true,
        );
    }

    /** @return array{0:?int,1:?string} */
    private function scope(Invoice $invoice): array
    {
        if ($invoice->store_id !== null && in_array(strtolower((string) $invoice->channel), ['b2b', 'b2c'], true)) {
            return [(int) $invoice->store_id, strtolower((string) $invoice->channel)];
        }

        if ($invoice->order_id === null) {
            return [null, null];
        }

        $order = DB::table('orders')->where('id', $invoice->order_id)->first(['store_id', 'channel']);

        return $order === null
            ? [null, null]
            : [(int) $order->store_id, strtolower((string) $order->channel)];
    }
}
