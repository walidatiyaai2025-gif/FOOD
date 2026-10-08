<?php

namespace Tests\Feature;

use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Services\OrderManualDispatchService;
use App\Services\OrderTerritoryRoutingService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FinalIntegrationGate1103Test extends TestCase
{
    public function test_dispatch_and_fleet_runtime_contracts_are_registered_on_converged_main(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('admin.field-operations.fleet'));
        $this->assertNotNull(Route::getRoutes()->getByName('admin.field-operations.fleet.feed'));
        $this->assertNotNull(Route::getRoutes()->getByName('admin.operations.orders.dispatch'));
        $this->assertNotNull(Route::getRoutes()->getByName('admin.operations.orders.dispatch.clear'));

        $this->assertArrayHasKey('orders.dispatch', config('permissions.abilities'));

        $this->assertTrue(class_exists(OrderTerritoryRoutingService::class));
        $this->assertTrue(class_exists(OrderManualDispatchService::class));
        $this->assertTrue(class_exists(OrderDispatchState::class));
        $this->assertTrue(class_exists(OrderVanAssignment::class));
    }
}
