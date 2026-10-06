<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;

final class CommercialDashboardController extends Controller
{
    private readonly AdminNavigation $navigation;

    public function __construct(AdminNavigation $navigation)
    {
        $this->navigation = $navigation;
    }

    public function salesControl(Request $request): View
    {
        [$user, $storeId] = $this->authorizedStore($request, 'catalog.view');

        $products = DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->where('store_products.store_id', $storeId)
            ->orderBy('products.name')
            ->limit(200)
            ->get([
                'products.id',
                'products.sku',
                'products.name',
                'store_products.is_active',
            ]);

        return $this->render($request, $user, $storeId, 'sales-control', [
            'products' => $products,
            'contractReady' => $this->hasApiContract('commercial'),
        ]);
    }

    public function flashOffers(Request $request): View
    {
        [$user, $storeId] = $this->authorizedStore($request, 'promotions.view');

        $existingPromotions = DB::table('promotions')
            ->where('store_id', $storeId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'name', 'type', 'value', 'starts_at', 'ends_at', 'is_active']);

        return $this->render($request, $user, $storeId, 'flash-offers', [
            'existingPromotions' => $existingPromotions,
            'contractReady' => $this->hasApiContract('flash'),
        ]);
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function authorizedStore(Request $request, string $permission): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $storeId = $request->integer('store_id');
        abort_unless($storeId > 0, 404);

        app(OperationalTenantScope::class)->assertStore($user, $storeId, $permission, 'b2c');

        return [$user, $storeId];
    }

    /**
     * This lane must not invent a second commercial-rules engine.
     * Until #984/#985 publish canonical endpoints, mutations stay disabled.
     */
    private function hasApiContract(string $needle): bool
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn (IlluminateRoute $route): bool => str_starts_with(strtolower($route->uri()), 'api/')
                && str_contains(strtolower($route->uri()), $needle));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function render(Request $request, User $user, int $storeId, string $section, array $payload): View
    {
        $supportAccess = $request->boolean('support_access');

        return view('admin.commercial-dashboard', [
            ...$payload,
            'user' => $user,
            'storeId' => $storeId,
            'section' => $section,
            'supportAccess' => $supportAccess,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => $section === 'sales-control' ? 'b2c_products' : 'b2c_promotions',
        ]);
    }
}
