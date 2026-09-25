<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
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
        $data = $request->validate(['quantity_delta' => ['required', 'numeric', 'not_in:0'], 'reason' => ['required', 'string', 'max:255']]);
        $before = $inventory->toArray();
        DB::transaction(function () use ($inventory, $data, $user): void {
            $inventory->refresh();
            $next = (float) $inventory->quantity + (float) $data['quantity_delta'];
            if ($next < (float) $inventory->reserved_quantity) {
                throw ValidationException::withMessages(['quantity_delta' => ['Stock cannot be reduced below reserved quantity.']]);
            }

            $inventory->update(['quantity' => $next]);
            StockMovement::query()->create(['inventory_id' => $inventory->id, 'user_id' => $user->id, 'type' => 'adjustment', 'quantity' => $data['quantity_delta'], 'reference_type' => 'inventory_adjustment', 'reference_id' => $inventory->id, 'reason' => $data['reason']]);
        });

        $audit->record('inventory.adjusted', $user, $inventory, $before, $inventory->fresh()->toArray(), $request);

        return response()->json(['data' => $inventory->fresh()]);
    }
}
