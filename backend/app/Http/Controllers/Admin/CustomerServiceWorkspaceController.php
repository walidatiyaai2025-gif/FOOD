<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class CustomerServiceWorkspaceController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        $isSuper = $actor->hasRole('SUPER_ADMIN');
        $canCustomers = $isSuper
            || $actor->hasPermission('customers.view')
            || $actor->hasPermission('b2b.accounts.view');
        $canOrders = $isSuper || $actor->hasPermission('orders.view');
        $canFinance = $isSuper || $actor->hasPermission('finance.view');
        $canDelivery = $isSuper
            || $actor->hasPermission('field_ops.manage')
            || $actor->hasPermission('drivers.b2b.view')
            || $actor->hasPermission('drivers.tracking.view');
        $canWhatsApp = $isSuper || $actor->hasPermission('whatsapp.inbox.view');

        abort_unless($canCustomers || $canOrders || $canFinance || $canDelivery || $canWhatsApp, 403);

        $capabilities = collect([
            [
                'key' => 'customer',
                'visible' => $canCustomers,
                'route' => 'admin.customer-360.index',
                'icon' => '◎',
            ],
            [
                'key' => 'orders',
                'visible' => $canOrders,
                'route' => 'admin.operations.orders.index',
                'icon' => '↻',
            ],
            [
                'key' => 'finance',
                'visible' => $canCustomers && $canFinance,
                'route' => 'admin.customer-360.index',
                'icon' => '¤',
            ],
            [
                'key' => 'delivery',
                'visible' => $canDelivery,
                'route' => 'admin.field-operations.overview',
                'icon' => '⌖',
            ],
            [
                'key' => 'conversations',
                'visible' => $canWhatsApp,
                'route' => 'admin.whatsapp-inbox.index',
                'icon' => '✦',
            ],
            [
                'key' => 'activity',
                'visible' => $canCustomers,
                'route' => 'admin.customer-360.index',
                'icon' => '≡',
            ],
        ])->filter(
            fn (array $item): bool => $item['visible'] === true && Route::has($item['route'])
        )->map(
            fn (array $item): array => $item + ['url' => route($item['route'])]
        )->values();

        return view('admin.customer-service-workspace', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'customer_360',
            'capabilities' => $capabilities,
            'canCustomers' => $canCustomers && Route::has('admin.customer-360.index'),
        ]);
    }
}
