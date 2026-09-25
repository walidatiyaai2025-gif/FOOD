<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class B2bReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        $orders = DB::table('orders')->where('customer_id', $customer->getKey())->where('channel', 'b2b');
        $openOrders = (clone $orders)->whereNotIn('status', ['delivered', 'cancelled'])->count();
        $purchased = (float) (clone $orders)->where('status', '!=', 'cancelled')->sum('grand_total');
        $invoiceTotal = (float) DB::table('invoices')->where('customer_id', $customer->getKey())->whereIn('status', ['issued', 'overdue'])->sum('total');
        $paidTotal = (float) DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->where('invoices.customer_id', $customer->getKey())->where('payments.status', 'paid')->sum('payments.amount');
        $outstanding = max(0.0, $invoiceTotal - $paidTotal);
        $top = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.customer_id', $customer->getKey())->where('orders.channel', 'b2b')->where('orders.status', '!=', 'cancelled')->groupBy('order_items.product_id', 'order_items.sku_snapshot', 'order_items.name_snapshot')->orderByDesc(DB::raw('SUM(order_items.quantity)'))->limit(5)->get(['order_items.product_id', 'order_items.sku_snapshot as sku', 'order_items.name_snapshot as name', DB::raw('SUM(order_items.quantity) as quantity'), DB::raw('SUM(order_items.line_total) as total')]);

        return response()->json(['open_orders' => $openOrders, 'purchase_total' => round($purchased, 3), 'outstanding_balance' => round($outstanding, 3), 'currency' => 'KWD', 'top_products' => $top]);
    }

    public function purchases(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $query = DB::table('orders')->where('customer_id', $customer->getKey())->where('channel', 'b2b')->where('status', '!=', 'cancelled');
        if (isset($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }
        $rows = $query->selectRaw('DATE(created_at) as period, COUNT(*) as orders_count, SUM(grand_total) as purchase_total')->groupByRaw('DATE(created_at)')->orderBy('period')->get();

        return response()->json(['data' => $rows, 'currency' => 'KWD']);
    }

    private function approvedCustomer(Request $request): Customer
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $customer = Customer::query()->where('user_id', $user->getKey())->where('type', 'b2b')->first();
        abort_unless($customer instanceof Customer, 403, 'B2B customer profile is required.');
        abort_unless(B2bAccount::query()->where('customer_id', $customer->getKey())->where('status', 'active')->exists(), 403, 'Approved B2B account is required.');

        return $customer;
    }
}
