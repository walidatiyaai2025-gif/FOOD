<?php

use App\Http\Controllers\Admin\SmartOrderingAnalyticsController;
use App\Http\Controllers\Admin\SmartOrderingGuestServiceController;
use App\Http\Controllers\Admin\SmartOrderingKitchenController;
use App\Http\Controllers\Admin\SmartOrderingMerchandisingAdminController;
use App\Http\Controllers\Admin\SmartOrderingOperationsController;
use App\Http\Controllers\Admin\SmartOrderingTableController;
use App\Http\Controllers\Admin\SmartOrderingWorkforceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SmartOrderingOperationsController::class, 'index'])->name('operations.index');
Route::post('/branches', [SmartOrderingOperationsController::class, 'storeBranch'])->name('branches.store');
Route::put('/branches/{branch}', [SmartOrderingOperationsController::class, 'updateBranch'])
    ->whereUuid('branch')->name('branches.update');
Route::post('/branches/{branch}/delivery-zones', [SmartOrderingOperationsController::class, 'storeZone'])
    ->whereUuid('branch')->name('zones.store');
Route::put('/branches/{branch}/delivery-zones/{zone}', [SmartOrderingOperationsController::class, 'updateZone'])
    ->whereUuid('branch')->whereUuid('zone')->name('zones.update');
Route::delete('/branches/{branch}/delivery-zones/{zone}', [SmartOrderingOperationsController::class, 'destroyZone'])
    ->whereUuid('branch')->whereUuid('zone')->name('zones.destroy');

Route::middleware('restaurant.setup:qr')->group(function (): void {
    Route::post('/branches/{branch}/floors', [SmartOrderingTableController::class, 'storeFloor'])
        ->whereUuid('branch')->name('tables.floors.store');
    Route::put('/branches/{branch}/floors/{floor}', [SmartOrderingTableController::class, 'updateFloor'])
        ->whereUuid('branch')->whereUuid('floor')->name('tables.floors.update');
    Route::delete('/branches/{branch}/floors/{floor}', [SmartOrderingTableController::class, 'destroyFloor'])
        ->whereUuid('branch')->whereUuid('floor')->name('tables.floors.destroy');
    Route::post('/branches/{branch}/tables', [SmartOrderingTableController::class, 'storeTable'])
        ->whereUuid('branch')->name('tables.store');
    Route::put('/branches/{branch}/tables/{table}', [SmartOrderingTableController::class, 'updateTable'])
        ->whereUuid('branch')->whereUuid('table')->name('tables.update');
    Route::post('/branches/{branch}/tables/{table}/position', [SmartOrderingTableController::class, 'updatePosition'])
        ->whereUuid('branch')->whereUuid('table')->name('tables.position');
    Route::delete('/branches/{branch}/tables/{table}', [SmartOrderingTableController::class, 'destroyTable'])
        ->whereUuid('branch')->whereUuid('table')->name('tables.destroy');
    Route::post('/branches/{branch}/tables/{table}/qr-binding', [SmartOrderingTableController::class, 'bindQr'])
        ->whereUuid('branch')->whereUuid('table')->name('tables.qr.bind');
    Route::delete('/branches/{branch}/tables/{table}/qr-binding', [SmartOrderingTableController::class, 'unbindQr'])
        ->whereUuid('branch')->whereUuid('table')->name('tables.qr.unbind');
    Route::post('/branches/{branch}/tables/{table}/combine', [SmartOrderingTableController::class, 'combine'])
        ->whereUuid('branch')->whereUuid('table')->name('tables.combine');
    Route::delete('/branches/{branch}/table-combinations/{combination}', [SmartOrderingTableController::class, 'split'])
        ->whereUuid('branch')->whereUuid('combination')->name('tables.split');

});

Route::middleware('restaurant.setup:launched')->group(function (): void {
    Route::post('/branches/{branch}/kitchen/stations', [SmartOrderingKitchenController::class, 'storeStation'])
        ->whereUuid('branch')->name('kitchen.stations.store');
    Route::post('/branches/{branch}/kitchen/rules', [SmartOrderingKitchenController::class, 'storeRule'])
        ->whereUuid('branch')->name('kitchen.rules.store');
    Route::delete('/branches/{branch}/kitchen/rules/{rule}', [SmartOrderingKitchenController::class, 'destroyRule'])
        ->whereUuid('branch')->whereUuid('rule')->name('kitchen.rules.destroy');
    Route::post('/branches/{branch}/kitchen/tickets/{reference}/action', [SmartOrderingKitchenController::class, 'ticketAction'])
        ->whereUuid('branch')->whereUuid('reference')->name('kitchen.ticket-action');
    Route::post('/branches/{branch}/kitchen/tickets/{reference}/items/{line}/action', [SmartOrderingKitchenController::class, 'itemAction'])
        ->whereUuid('branch')->whereUuid('reference')->whereNumber('line')->name('kitchen.item-action');
    Route::post('/branches/{branch}/workforce/staff', [SmartOrderingWorkforceController::class, 'storeAssignment'])
        ->whereUuid('branch')->name('workforce.staff.store');
    Route::delete('/branches/{branch}/workforce/staff/{user}', [SmartOrderingWorkforceController::class, 'destroyAssignment'])
        ->whereUuid('branch')->whereNumber('user')->name('workforce.staff.destroy');
    Route::post('/branches/{branch}/workforce/shifts', [SmartOrderingWorkforceController::class, 'storeShift'])
        ->whereUuid('branch')->name('workforce.shifts.store');
    Route::put('/branches/{branch}/workforce/shifts/{shift}', [SmartOrderingWorkforceController::class, 'updateShift'])
        ->whereUuid('branch')->whereUuid('shift')->name('workforce.shifts.update');
    Route::post('/branches/{branch}/workforce/shifts/{shift}/transition', [SmartOrderingWorkforceController::class, 'transitionShift'])
        ->whereUuid('branch')->whereUuid('shift')->name('workforce.shifts.transition');
    Route::post('/branches/{branch}/workforce/time-clock', [SmartOrderingWorkforceController::class, 'clock'])
        ->whereUuid('branch')->name('workforce.clock');
    Route::put('/branches/{branch}/workforce/time-clock/{session}/correction', [SmartOrderingWorkforceController::class, 'correctSession'])
        ->whereUuid('branch')->whereUuid('session')->name('workforce.sessions.correct');

    Route::post('/branches/{branch}/reservations', [SmartOrderingGuestServiceController::class, 'storeReservation'])
        ->whereUuid('branch')->name('reservations.store');
    Route::put('/branches/{branch}/reservations/{reservation}', [SmartOrderingGuestServiceController::class, 'updateReservation'])
        ->whereUuid('branch')->whereUuid('reservation')->name('reservations.update');
    Route::post('/branches/{branch}/reservations/{reservation}/transition', [SmartOrderingGuestServiceController::class, 'transitionReservation'])
        ->whereUuid('branch')->whereUuid('reservation')->name('reservations.transition');
    Route::post('/branches/{branch}/waitlist', [SmartOrderingGuestServiceController::class, 'storeWaitlist'])
        ->whereUuid('branch')->name('waitlist.store');
    Route::put('/branches/{branch}/waitlist/{entry}', [SmartOrderingGuestServiceController::class, 'updateWaitlist'])
        ->whereUuid('branch')->whereUuid('entry')->name('waitlist.update');
    Route::post('/branches/{branch}/waitlist/{entry}/transition', [SmartOrderingGuestServiceController::class, 'transitionWaitlist'])
        ->whereUuid('branch')->whereUuid('entry')->name('waitlist.transition');
    Route::post('/branches/{branch}/waitlist/{entry}/convert', [SmartOrderingGuestServiceController::class, 'convertWaitlist'])
        ->whereUuid('branch')->whereUuid('entry')->name('waitlist.convert');
    Route::post('/orders/{reference}/status', [SmartOrderingOperationsController::class, 'transition'])
        ->whereUuid('reference')->name('orders.transition');

    Route::post('/payments/{payment}/{operation}', [SmartOrderingOperationsController::class, 'paymentOperation'])
        ->whereNumber('payment')
        ->where('operation', 'authorize|capture|void|refund')
        ->name('payments.operation');

    Route::get('/sites/{site}/merchandising', [SmartOrderingMerchandisingAdminController::class, 'index'])
        ->name('merchandising.index');
    Route::post('/sites/{site}/merchandising/offers', [SmartOrderingMerchandisingAdminController::class, 'storeOffer'])
        ->name('merchandising.offers.store');
    Route::put('/sites/{site}/merchandising/offers/{offer}', [SmartOrderingMerchandisingAdminController::class, 'updateOffer'])
        ->whereUuid('offer')->name('merchandising.offers.update');
    Route::post('/sites/{site}/merchandising/recommendations', [SmartOrderingMerchandisingAdminController::class, 'storeRecommendation'])
        ->name('merchandising.recommendations.store');
    Route::put('/sites/{site}/merchandising/recommendations/{recommendation}', [SmartOrderingMerchandisingAdminController::class, 'updateRecommendation'])
        ->whereUuid('recommendation')->name('merchandising.recommendations.update');

    Route::get('/analytics', [SmartOrderingAnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/export', [SmartOrderingAnalyticsController::class, 'export'])->name('analytics.export');

});
