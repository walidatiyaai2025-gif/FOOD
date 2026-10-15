<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasPermission('inventory.manage') || $user->storeRoleAssignments()->whereHas('role.permissions', fn ($query) => $query->where('permissions.code', 'inventory.manage'))->exists(), 403);
        $rows = Inventory::query()->accessibleTo($user)->orderBy('id')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total()]]);
    }

    public function adjust(Request $request, Inventory $inventory, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $warehouse = Warehouse::query()->findOrFail($inventory->warehouse_id);
        abort_unless(Inventory::query()->accessibleTo($user)->whereKey($inventory->getKey())->exists(), 404);
        abort_unless($user->hasPermission('inventory.manage', (int) $warehouse->store_id), 403);
        app(OperationalTenantScope::class)->assertStore($user, (int) $warehouse->store_id, 'inventory.manage');
        app(OperationalTenantScope::class)->assertProductOwnedByStore((int) $inventory->product_id, (int) $warehouse->store_id);
        $data = $request->validate([
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
            'expected_quantity' => ['nullable', 'numeric'],
            'expected_reserved_quantity' => ['nullable', 'numeric'],
        ]);
        $before = $inventory->toArray();
        DB::transaction(function () use ($inventory, $data, $user, $warehouse): void {
            $locked = Inventory::query()->whereKey($inventory->getKey())->lockForUpdate()->firstOrFail();
            if (array_key_exists('expected_quantity', $data)
                && $data['expected_quantity'] !== null
                && abs((float) $locked->quantity - (float) $data['expected_quantity']) > 0.0001) {
                throw ValidationException::withMessages([
                    'quantity_delta' => [app()->getLocale() === 'ar'
                        ? 'تغيّر رصيد المخزون منذ المراجعة. راجع القيم الحالية وأعد المحاولة.'
                        : 'Inventory changed since review. Review the current values and try again.'],
                ]);
            }
            if (array_key_exists('expected_reserved_quantity', $data)
                && $data['expected_reserved_quantity'] !== null
                && abs((float) $locked->reserved_quantity - (float) $data['expected_reserved_quantity']) > 0.0001) {
                throw ValidationException::withMessages([
                    'quantity_delta' => [app()->getLocale() === 'ar'
                        ? 'تغيّرت الكمية المحجوزة منذ المراجعة. راجع القيم الحالية وأعد المحاولة.'
                        : 'Reserved stock changed since review. Review the current values and try again.'],
                ]);
            }
            $next = (float) $locked->quantity + (float) $data['quantity_delta'];
            if ($next < (float) $locked->reserved_quantity) {
                throw ValidationException::withMessages(['quantity_delta' => ['Stock cannot be reduced below reserved quantity.']]);
            }

            $locked->update(['quantity' => $next]);
            StockMovement::query()->create(['inventory_id' => $inventory->id, 'store_id' => (int) $warehouse->store_id, 'user_id' => $user->id, 'type' => 'adjustment', 'quantity' => $data['quantity_delta'], 'reference_type' => 'inventory_adjustment', 'reference_id' => $inventory->id, 'reason' => $data['reason']]);
        });

        $audit->record('inventory.adjusted', $user, $inventory, $before, $inventory->fresh()->toArray(), $request);

        return response()->json(['data' => $inventory->fresh()]);
    }
}
