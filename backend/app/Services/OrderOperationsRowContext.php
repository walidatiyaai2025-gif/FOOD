<?php

namespace App\Services;

use App\Models\DriverAssignment;
use App\Models\OrderDispatchState;
use stdClass;

final readonly class OrderOperationsRowContext
{
    /**
     * @param  array<int,DriverAssignment>  $assignments
     * @param  array<int,stdClass>  $drivers
     * @param  array<int,OrderDispatchState>  $dispatchStates
     * @param  array<int,stdClass>  $vans
     * @param  array<int,stdClass>  $territories
     * @param  array<int,stdClass>  $customerServiceAssignments
     * @param  array<int,stdClass>  $customerServiceUsers
     * @param  array<int,string>  $customerNames
     * @param  array<int,stdClass>  $payments
     * @param  array<int,stdClass>  $invoices
     * @param  array<int,bool>  $issuedInvoiceOrders
     * @param  array<int,stdClass>  $stores
     * @param  array<int,string>  $sources
     * @param  array<int,array<string,mixed>>  $settlements
     */
    public function __construct(
        public array $assignments,
        public array $drivers,
        public array $dispatchStates,
        public array $vans,
        public array $territories,
        public array $customerServiceAssignments,
        public array $customerServiceUsers,
        public array $customerNames,
        public array $payments,
        public array $invoices,
        public array $issuedInvoiceOrders,
        public array $stores,
        public array $sources,
        public array $settlements,
    ) {}
}
