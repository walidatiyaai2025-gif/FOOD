<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReadModels\CommercialProfitabilityReadModel;
use App\Enterprise\Reporting\ReadModels\CustomerServiceOperationsReadModel;
use App\Enterprise\Reporting\ReadModels\FieldOperationsReadModel;
use App\Enterprise\Reporting\ReadModels\FinanceExposureReadModel;
use App\Enterprise\Reporting\ReadModels\OrdersSummaryReadModel;
use App\Enterprise\Reporting\ReadModels\ProcurementOperationsReadModel;
use App\Enterprise\Reporting\ReadModels\StockExposureReadModel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class ReportBuilderExecutor
{
    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly ReportAccessResolver $access,
        private readonly OrdersSummaryReadModel $ordersSummary,
        private readonly CommercialProfitabilityReadModel $commercialProfitability,
        private readonly CustomerServiceOperationsReadModel $customerServiceOperations,
        private readonly FinanceExposureReadModel $financeExposure,
        private readonly FieldOperationsReadModel $fieldOperations,
        private readonly StockExposureReadModel $stockExposure,
        private readonly ProcurementOperationsReadModel $procurementOperations,
        private readonly ReportExportManager $exports,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(
        User $actor,
        string $reportCode,
        array $input,
        EntitlementScope $scope,
    ): array {
        $report = $this->reports->require($reportCode);

        if (! $this->access->allowsView($actor, $report, $scope)) {
            throw new AuthorizationException('Report execution is not authorized for the requested scope.');
        }

        $query = ReportQuery::fromArray($report, $input);
        $result = $this->executeReadModel($query, $scope);
        $result['rows'] = array_map(
            fn (array $row): array => $this->projectRow($query, $row),
            $result['rows'],
        );

        if ($report->drillDownRoute !== null) {
            $result['rows'] = array_map(
                fn (array $row): array => [
                    ...$row,
                    'drill_down' => [
                        'route' => $report->drillDownRoute,
                        'filters' => $this->drillDownFilters($query, $row),
                    ],
                ],
                $result['rows'],
            );
        }

        return [
            'report' => $report->code,
            'plan' => $query->executionPlan(),
            ...$result,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   export_id: int,
     *   execution_mode: string,
     *   synchronous_request: bool,
     *   large_export: bool,
     *   plan: array<string, mixed>
     * }
     */
    public function requestExport(
        User $actor,
        string $reportCode,
        array $input,
        EntitlementScope $scope,
        int $rowCount,
        ?string $idempotencyKey = null,
    ): array {
        $report = $this->reports->require($reportCode);
        $query = ReportQuery::fromArray($report, $input);

        $exportId = $this->exports->request(
            $actor,
            $query,
            $scope,
            $rowCount,
            $idempotencyKey,
        );

        return [
            'export_id' => $exportId,
            'execution_mode' => 'queued_chunked',
            'synchronous_request' => false,
            'large_export' => $rowCount > ReportExportManager::LARGE_EXPORT_ROW_THRESHOLD,
            'plan' => $query->executionPlan(),
        ];
    }

    /** @return array<string, mixed> */
    private function executeReadModel(ReportQuery $query, EntitlementScope $scope): array
    {
        return match ($query->report->readModel) {
            'orders_summary_daily' => $this->ordersSummary->read($query, $scope),
            'commercial_profitability' => $this->commercialProfitability->read($query, $scope),
            'customer_service_operations' => $this->customerServiceOperations->read($query, $scope),
            'finance_ar_exposure', 'finance_ap_exposure' => $this->financeExposure->read($query, $scope),
            'wms_stock_exposure' => $this->stockExposure->read($query, $scope),
            'field_operations' => $this->fieldOperations->read($query, $scope),
            'procurement_operations' => $this->procurementOperations->read($query, $scope),
            default => throw ValidationException::withMessages([
                'read_model' => ["Unsupported registered read model [{$query->report->readModel}]."],
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function projectRow(ReportQuery $query, array $row): array
    {
        $projected = [];

        foreach ($query->dimensions as $dimension) {
            if (! array_key_exists($dimension, $row)) {
                throw ValidationException::withMessages([
                    'read_model' => ["Read model did not return dimension [{$dimension}]."],
                ]);
            }

            $projected[$dimension] = $row[$dimension];
        }

        foreach ($query->measures as $measure) {
            $source = $measure === 'orders' ? 'order_count' : $measure;
            if (! array_key_exists($source, $row)) {
                throw ValidationException::withMessages([
                    'read_model' => ["Read model did not return measure [{$measure}]."],
                ]);
            }

            $projected[$measure] = $row[$source];
        }

        if (array_key_exists('drill_down', $row) && is_array($row['drill_down'])) {
            $projected['drill_down'] = $row['drill_down'];
        }

        return $projected;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, scalar|null>
     */
    private function drillDownFilters(ReportQuery $query, array $row): array
    {
        $filters = $query->filters;

        foreach ($query->dimensions as $dimension) {
            if (array_key_exists($dimension, $row) && is_scalar($row[$dimension])) {
                $filters[$dimension] = $row[$dimension];
            }
        }

        return $filters;
    }
}
