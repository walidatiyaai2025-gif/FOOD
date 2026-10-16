<?php

namespace App\Enterprise\Treasury;

use App\Enterprise\Events\VersionedDomainEvent;
use App\Enterprise\Outbox\TransactionalOutbox;
use App\Models\EnterpriseTreasuryCashClosing;
use App\Models\EnterpriseTreasuryIntegrationEvent;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\EnterpriseTreasuryReconciliation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TreasuryIntegrationEventPublisher
{
    public const RECEIPT_POSTED = 'TreasuryReceiptPosted';

    public const PAYMENT_POSTED = 'TreasuryPaymentPosted';

    public const RECONCILIATION_CLOSED = 'TreasuryReconciliationClosed';

    public const CASH_CLOSING_CLOSED = 'TreasuryCashClosingClosed';

    private const SOURCE_RECEIPT = 'receipt';

    private const SOURCE_PAYMENT = 'payment';

    private const SOURCE_RECONCILIATION = 'reconciliation';

    private const SOURCE_CASH_CLOSING = 'cash_closing';

    private const EVENT_VERSION = 1;

    private const DEFAULT_DIAGNOSTIC_WINDOW_DAYS = 90;

    private const MAX_DIAGNOSTIC_WINDOW_DAYS = 366;

    public function __construct(
        private readonly TransactionalOutbox $outbox,
        private readonly AuditLogger $audit,
    ) {}

    public function publishReceiptPosted(
        User $actor,
        EnterpriseDataScope $scope,
        EnterpriseTreasuryReceipt $receipt,
    ): EnterpriseTreasuryIntegrationEvent {
        return $this->publish(
            actor: $actor,
            scope: $scope,
            sourceType: self::SOURCE_RECEIPT,
            sourceId: (int) $receipt->id,
            sourceReference: (string) $receipt->receipt_number,
            eventType: self::RECEIPT_POSTED,
            currencyCode: (string) $receipt->currency_code,
            consumerHints: ['finance', 'ar'],
            payload: [
                'treasury_receipt_id' => (int) $receipt->id,
                'receipt_number' => (string) $receipt->receipt_number,
                'target_type' => (string) $receipt->target_type,
                'target_id' => (int) $receipt->target_id,
                'amount' => (string) $receipt->amount,
                'currency_code' => (string) $receipt->currency_code,
                'value_date' => (string) $receipt->getRawOriginal('value_date'),
                'reference' => (string) $receipt->reference,
                'description' => $receipt->description,
                'posted_by' => $receipt->posted_by === null ? null : (int) $receipt->posted_by,
                'posted_at' => CarbonImmutable::parse((string) $receipt->getRawOriginal('posted_at'))
                    ->utc()
                    ->toIso8601String(),
            ],
        );
    }

    public function publishPaymentPosted(
        User $actor,
        EnterpriseDataScope $scope,
        EnterpriseTreasuryPayment $payment,
    ): EnterpriseTreasuryIntegrationEvent {
        return $this->publish(
            actor: $actor,
            scope: $scope,
            sourceType: self::SOURCE_PAYMENT,
            sourceId: (int) $payment->id,
            sourceReference: (string) $payment->payment_number,
            eventType: self::PAYMENT_POSTED,
            currencyCode: (string) $payment->currency_code,
            consumerHints: ['finance', 'ap'],
            payload: [
                'treasury_payment_id' => (int) $payment->id,
                'payment_number' => (string) $payment->payment_number,
                'target_type' => (string) $payment->target_type,
                'target_id' => (int) $payment->target_id,
                'amount' => (string) $payment->amount,
                'currency_code' => (string) $payment->currency_code,
                'value_date' => (string) $payment->getRawOriginal('value_date'),
                'reference' => (string) $payment->reference,
                'beneficiary' => $payment->beneficiary,
                'description' => $payment->description,
                'workflow_request_id' => $payment->workflow_request_id === null
                    ? null
                    : (int) $payment->workflow_request_id,
                'posted_by' => $payment->posted_by === null ? null : (int) $payment->posted_by,
                'posted_at' => CarbonImmutable::parse((string) $payment->getRawOriginal('posted_at'))
                    ->utc()
                    ->toIso8601String(),
            ],
        );
    }

    public function publishReconciliationClosed(
        User $actor,
        EnterpriseDataScope $scope,
        EnterpriseTreasuryReconciliation $reconciliation,
    ): EnterpriseTreasuryIntegrationEvent {
        return $this->publish(
            actor: $actor,
            scope: $scope,
            sourceType: self::SOURCE_RECONCILIATION,
            sourceId: (int) $reconciliation->id,
            sourceReference: (string) $reconciliation->reconciliation_number,
            eventType: self::RECONCILIATION_CLOSED,
            currencyCode: (string) $reconciliation->currency_code,
            consumerHints: ['finance'],
            payload: [
                'treasury_reconciliation_id' => (int) $reconciliation->id,
                'reconciliation_number' => (string) $reconciliation->reconciliation_number,
                'bank_account_id' => (int) $reconciliation->bank_account_id,
                'period_from' => (string) $reconciliation->getRawOriginal('period_from'),
                'period_to' => (string) $reconciliation->getRawOriginal('period_to'),
                'statement_receipts_total' => (string) $reconciliation->statement_receipts_total,
                'statement_payments_total' => (string) $reconciliation->statement_payments_total,
                'currency_code' => (string) $reconciliation->currency_code,
                'closed_by' => $reconciliation->closed_by === null ? null : (int) $reconciliation->closed_by,
                'closed_at' => CarbonImmutable::parse((string) $reconciliation->getRawOriginal('closed_at'))
                    ->utc()
                    ->toIso8601String(),
            ],
        );
    }

    public function publishCashClosingClosed(
        User $actor,
        EnterpriseDataScope $scope,
        EnterpriseTreasuryCashClosing $closing,
    ): EnterpriseTreasuryIntegrationEvent {
        return $this->publish(
            actor: $actor,
            scope: $scope,
            sourceType: self::SOURCE_CASH_CLOSING,
            sourceId: (int) $closing->id,
            sourceReference: (string) $closing->closing_number,
            eventType: self::CASH_CLOSING_CLOSED,
            currencyCode: (string) $closing->currency_code,
            consumerHints: ['finance'],
            payload: [
                'treasury_cash_closing_id' => (int) $closing->id,
                'closing_number' => (string) $closing->closing_number,
                'cashbox_id' => (int) $closing->cashbox_id,
                'business_date' => (string) $closing->getRawOriginal('business_date'),
                'opening_amount' => (string) $closing->opening_amount,
                'receipt_total' => (string) $closing->receipt_total,
                'payment_total' => (string) $closing->payment_total,
                'expected_closing_amount' => (string) $closing->expected_closing_amount,
                'declared_closing_amount' => (string) $closing->declared_closing_amount,
                'currency_code' => (string) $closing->currency_code,
                'closed_by' => $closing->closed_by === null ? null : (int) $closing->closed_by,
                'closed_at' => CarbonImmutable::parse((string) $closing->getRawOriginal('closed_at'))
                    ->utc()
                    ->toIso8601String(),
            ],
        );
    }

    /**
     * @param  array{source_type?:string,event_type?:string,date_from?:string,date_to?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseTreasuryIntegrationEvent>
     */
    public function paginateDiagnostics(
        EnterpriseDataScope $scope,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $validated = Validator::make($filters, [
            'source_type' => ['nullable', Rule::in([
                self::SOURCE_RECEIPT,
                self::SOURCE_PAYMENT,
                self::SOURCE_RECONCILIATION,
                self::SOURCE_CASH_CLOSING,
            ])],
            'event_type' => ['nullable', 'string', 'max:96'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ])->validate();

        $window = $this->boundedDiagnosticWindow($validated);
        $perPage = max(10, min(100, $perPage));

        return EnterpriseTreasuryIntegrationEvent::query()
            ->with('outboxMessage:id,event_id,status,attempt_count,available_at,dispatched_at,failed_at')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId)
            ->when(
                $validated['source_type'] ?? null,
                static fn (Builder $query, string $sourceType): Builder => $query->where('source_type', $sourceType),
            )
            ->when(
                $validated['event_type'] ?? null,
                static fn (Builder $query, string $eventType): Builder => $query->where('event_type', $eventType),
            )
            ->where('occurred_at', '>=', $window['from'])
            ->where('occurred_at', '<=', $window['to'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  list<string>  $consumerHints
     * @param  array<string, mixed>  $payload
     */
    private function publish(
        User $actor,
        EnterpriseDataScope $scope,
        string $sourceType,
        int $sourceId,
        string $sourceReference,
        string $eventType,
        string $currencyCode,
        array $consumerHints,
        array $payload,
    ): EnterpriseTreasuryIntegrationEvent {
        $existing = EnterpriseTreasuryIntegrationEvent::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('event_type', $eventType)
            ->where('event_version', self::EVENT_VERSION)
            ->first();

        if ($existing instanceof EnterpriseTreasuryIntegrationEvent) {
            return $existing;
        }

        $event = VersionedDomainEvent::create(
            type: $eventType,
            version: self::EVENT_VERSION,
            scope: [
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
                'currency_code' => strtoupper($currencyCode),
            ],
            payload: [
                'source' => [
                    'domain' => 'treasury',
                    'type' => $sourceType,
                    'id' => $sourceId,
                    'reference' => $sourceReference,
                ],
                'consumer_hints' => $consumerHints,
                'data' => $payload,
            ],
        );

        $this->outbox->enqueue($event);

        $integration = EnterpriseTreasuryIntegrationEvent::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'store_id' => $scope->storeId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_reference' => $sourceReference,
            'event_id' => $event->id,
            'event_type' => $event->type,
            'event_version' => $event->version,
            'currency_code' => strtoupper($currencyCode),
            'consumer_hints' => $consumerHints,
            'occurred_at' => $event->occurredAt,
        ]);

        $this->audit->record(
            'enterprise.treasury.integration_event_published',
            $actor,
            $integration,
            null,
            [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'event_version' => $event->version,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_reference' => $sourceReference,
                'consumer_hints' => $consumerHints,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
            ],
        );

        return $integration;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{from:CarbonImmutable,to:CarbonImmutable}
     */
    private function boundedDiagnosticWindow(array $validated): array
    {
        $to = isset($validated['date_to'])
            ? CarbonImmutable::parse((string) $validated['date_to'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();
        $from = isset($validated['date_from'])
            ? CarbonImmutable::parse((string) $validated['date_from'])->startOfDay()
            : $to->subDays(self::DEFAULT_DIAGNOSTIC_WINDOW_DAYS - 1)->startOfDay();

        if ($from->lt($to->subDays(self::MAX_DIAGNOSTIC_WINDOW_DAYS - 1)->startOfDay())) {
            throw ValidationException::withMessages([
                'date_from' => [
                    sprintf(
                        'Treasury integration diagnostics are limited to %d days per query.',
                        self::MAX_DIAGNOSTIC_WINDOW_DAYS,
                    ),
                ],
            ]);
        }

        return ['from' => $from, 'to' => $to];
    }
}
