<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Actions\Models\AssistantActionApproval;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AssistantActionApprovalService
{
    public const ACTION_PURCHASE_REQUEST_DRAFT = 'procurement.purchase_request.create_draft';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_EXPIRED = 'expired';

    private const PERMISSION = 'procurement.purchase_requests.manage';

    public function __construct(
        private readonly TenantContextResolver $tenants,
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly PurchaseRequestManager $purchaseRequests,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $input */
    public function create(User $actor, array $input, string $locale = 'en', ?string $correlationId = null): AssistantActionApproval
    {
        $data = Validator::make($input, [
            'action_key' => ['required', Rule::in([self::ACTION_PURCHASE_REQUEST_DRAFT])],
            'store_id' => ['required', 'integer', 'min:1'],
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'payload' => ['required', 'array'],
            'payload.supplier_id' => ['nullable', 'integer', 'min:1'],
            'payload.currency_code' => ['required', 'string', 'size:3'],
            'payload.needed_by' => ['nullable', 'date'],
            'payload.reason' => ['nullable', 'string', 'max:2000'],
            'payload.notes' => ['nullable', 'string', 'max:4000'],
            'payload.lines' => ['required', 'array', 'min:1', 'max:100'],
            'payload.lines.*.item_code' => ['nullable', 'string', 'max:96'],
            'payload.lines.*.description' => ['required', 'string', 'max:255'],
            'payload.lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'payload.lines.*.unit' => ['required', 'string', 'max:32'],
            'payload.lines.*.estimated_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ])->validate();

        abort_unless((bool) $actor->is_active, 403);

        $context = $this->resolveContext(
            $actor,
            (string) $data['channel'],
            (int) $data['store_id'],
        );
        $this->authorize($actor, $context);

        $payload = $this->canonicalPayload((array) $data['payload']);
        $payloadHash = $this->hash($payload);
        $contextHash = $this->contextHash($actor, $context);
        $idempotencyKey = hash('sha256', implode('|', [
            (string) $actor->getKey(),
            self::ACTION_PURCHASE_REQUEST_DRAFT,
            $payloadHash,
            $contextHash,
        ]));

        $existing = AssistantActionApproval::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($existing instanceof AssistantActionApproval) {
            return $existing;
        }

        $expiresAt = now()->addMinutes(15);
        $language = strtolower($locale) === 'ar' ? 'ar' : 'en';
        $preview = [
            'action_key' => self::ACTION_PURCHASE_REQUEST_DRAFT,
            'target' => [
                'type' => 'purchase_request',
                'store_id' => (int) $data['store_id'],
                'channel' => (string) $data['channel'],
            ],
            'payload' => $payload,
            'side_effects' => [
                'en' => [
                    'Creates exactly one enterprise purchase request in draft status.',
                    'Does not submit, approve, issue, receive, post, or pay anything.',
                ],
                'ar' => [
                    'ينشئ طلب شراء مؤسسي واحد فقط بحالة مسودة.',
                    'لا يرسل أو يعتمد أو يصدر أو يستلم أو يرحّل أو يدفع أي شيء.',
                ],
            ],
            'summary' => $language === 'ar'
                ? 'إنشاء مسودة طلب شراء بعد موافقة صريحة وإعادة التحقق من الصلاحيات.'
                : 'Create one draft purchase request after explicit approval and server re-authorization.',
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $approval = AssistantActionApproval::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $actor->getKey(),
            'action_key' => self::ACTION_PURCHASE_REQUEST_DRAFT,
            'action_version' => '1.0',
            'status' => self::STATUS_DRAFT,
            'channel' => (string) $data['channel'],
            'store_id' => (int) $data['store_id'],
            'permission_code' => self::PERMISSION,
            'payload' => $payload,
            'payload_hash' => $payloadHash,
            'context_hash' => $contextHash,
            'idempotency_key' => $idempotencyKey,
            'preview' => $preview,
            'correlation_id' => $correlationId,
            'expires_at' => $expiresAt,
        ]);

        $this->audit->record(
            'assistant.action.drafted',
            $actor,
            $approval,
            null,
            $this->auditPayload($approval),
        );

        return $approval->refresh();
    }

    public function findOwned(User $actor, string $publicId): AssistantActionApproval
    {
        return AssistantActionApproval::query()
            ->where('public_id', $publicId)
            ->where('user_id', $actor->getKey())
            ->firstOrFail();
    }

    public function approve(
        User $actor,
        AssistantActionApproval $approval,
        string $expectedPayloadHash,
        bool $confirmed,
    ): AssistantActionApproval {
        abort_unless($confirmed, 422, 'Explicit approval confirmation is required.');

        return DB::transaction(function () use ($actor, $approval, $expectedPayloadHash): AssistantActionApproval {
            $locked = $this->lockOwned($actor, $approval);
            if ($locked->status === self::STATUS_EXECUTED) {
                return $locked;
            }
            if ($locked->status === self::STATUS_APPROVED) {
                return $locked;
            }

            $this->assertFresh($locked);
            abort_unless($locked->status === self::STATUS_DRAFT, 409, 'Action is not awaiting approval.');

            $context = $this->resolveContext($actor, (string) $locked->channel, (int) $locked->store_id);
            $this->authorize($actor, $context);
            $this->assertPayloadIntegrity($locked, $expectedPayloadHash);
            abort_unless(
                hash_equals((string) $locked->context_hash, $this->contextHash($actor, $context)),
                409,
                'Assistant action context changed after the preview was created.',
            );

            $before = $this->auditPayload($locked);
            $locked->forceFill([
                'status' => self::STATUS_APPROVED,
                'approved_payload_hash' => $locked->payload_hash,
                'approved_at' => now(),
                'failure_code' => null,
            ])->save();

            $this->audit->record(
                'assistant.action.approved',
                $actor,
                $locked,
                $before,
                $this->auditPayload($locked->fresh()),
            );

            return $locked->refresh();
        }, 3);
    }

    public function execute(User $actor, AssistantActionApproval $approval): AssistantActionApproval
    {
        abort_unless((bool) config('assistant.security.write_actions_enabled', false), 503, 'Assistant write actions are disabled.');

        return DB::transaction(function () use ($actor, $approval): AssistantActionApproval {
            $locked = $this->lockOwned($actor, $approval);

            if ($locked->status === self::STATUS_EXECUTED) {
                return $locked;
            }

            $this->assertFresh($locked);
            abort_unless($locked->status === self::STATUS_APPROVED, 409, 'Explicit approval is required before execution.');
            abort_unless(
                is_string($locked->approved_payload_hash)
                && hash_equals((string) $locked->payload_hash, $locked->approved_payload_hash),
                409,
                'Approved action payload no longer matches the reviewed payload.',
            );

            $context = $this->resolveContext($actor, (string) $locked->channel, (int) $locked->store_id);
            $this->authorize($actor, $context);
            $this->assertPayloadIntegrity($locked, (string) $locked->approved_payload_hash);
            abort_unless(
                hash_equals((string) $locked->context_hash, $this->contextHash($actor, $context)),
                409,
                'Assistant action context changed after approval.',
            );

            $before = $this->auditPayload($locked);
            $result = match ((string) $locked->action_key) {
                self::ACTION_PURCHASE_REQUEST_DRAFT => $this->executePurchaseRequestDraft(
                    $actor,
                    $context,
                    (array) $locked->payload,
                ),
                default => throw ValidationException::withMessages([
                    'action_key' => ['Unregistered Assistant mutation is prohibited.'],
                ]),
            };

            $locked->forceFill([
                'status' => self::STATUS_EXECUTED,
                'executed_at' => now(),
                'result' => $result,
                'failure_code' => null,
            ])->save();

            $this->audit->record(
                'assistant.action.executed',
                $actor,
                $locked,
                $before,
                $this->auditPayload($locked->fresh()),
            );

            return $locked->refresh();
        }, 3);
    }

    /** @return array<string,mixed> */
    private function executePurchaseRequestDraft(
        User $actor,
        ResolvedTenantContext $context,
        array $payload,
    ): array {
        $purchaseRequest = $this->purchaseRequests->create($actor, $context, $payload);

        return [
            'entity_type' => 'enterprise_purchase_request',
            'entity_id' => (int) $purchaseRequest->getKey(),
            'request_number' => (string) $purchaseRequest->request_number,
            'status' => (string) $purchaseRequest->status,
            'store_id' => (int) $purchaseRequest->store_id,
        ];
    }

    private function resolveContext(User $actor, string $channel, int $storeId): ResolvedTenantContext
    {
        return $channel === 'b2c'
            ? $this->tenants->retail($actor, $storeId, $actor->hasRole('SUPER_ADMIN'))
            : $this->tenants->wholesale($actor, $storeId);
    }

    private function authorize(User $actor, ResolvedTenantContext $context): void
    {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $this->authorizer->authorize(
            $actor,
            $context,
            self::PERMISSION,
            'company',
            $context->enterpriseScope->companyId,
        );
    }

    private function assertFresh(AssistantActionApproval $approval): void
    {
        if ($approval->expires_at !== null && $approval->expires_at->isFuture()) {
            return;
        }

        $approval->forceFill([
            'status' => self::STATUS_EXPIRED,
            'failure_code' => 'APPROVAL_EXPIRED',
        ])->save();

        abort(409, 'Assistant action approval has expired.');
    }

    private function assertPayloadIntegrity(
        AssistantActionApproval $approval,
        string $expectedPayloadHash,
    ): void {
        $currentHash = $this->hash($this->canonicalPayload((array) $approval->payload));

        abort_unless(
            hash_equals((string) $approval->payload_hash, $currentHash)
            && hash_equals((string) $approval->payload_hash, $expectedPayloadHash),
            409,
            'Assistant action payload changed after preview.',
        );
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function canonicalPayload(array $payload): array
    {
        $clean = [
            'supplier_id' => isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : null,
            'currency_code' => strtoupper(trim((string) ($payload['currency_code'] ?? ''))),
            'needed_by' => isset($payload['needed_by']) && $payload['needed_by'] !== ''
                ? (string) $payload['needed_by']
                : null,
            'reason' => isset($payload['reason']) ? trim((string) $payload['reason']) : null,
            'notes' => isset($payload['notes']) ? trim((string) $payload['notes']) : null,
            'lines' => [],
        ];

        foreach ((array) ($payload['lines'] ?? []) as $line) {
            $clean['lines'][] = [
                'item_code' => isset($line['item_code']) ? trim((string) $line['item_code']) : null,
                'description' => trim((string) ($line['description'] ?? '')),
                'quantity' => $line['quantity'] ?? null,
                'unit' => trim((string) ($line['unit'] ?? '')),
                'estimated_unit_cost' => $line['estimated_unit_cost'] ?? null,
            ];
        }

        return $clean;
    }

    private function contextHash(User $actor, ResolvedTenantContext $context): string
    {
        $permissions = $actor->effectivePermissionCodes($context->storeId);
        sort($permissions);

        return $this->hash([
            'actor_user_id' => (int) $actor->getKey(),
            'channel' => $context->channel,
            'store_id' => $context->storeId,
            'organization_id' => $context->enterpriseScope?->organizationId,
            'company_id' => $context->enterpriseScope?->companyId,
            'permissions' => $permissions,
        ]);
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode(
            $this->sortRecursive($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        return $value;
    }

    private function lockOwned(User $actor, AssistantActionApproval $approval): AssistantActionApproval
    {
        return AssistantActionApproval::query()
            ->whereKey($approval->getKey())
            ->where('user_id', $actor->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function auditPayload(AssistantActionApproval $approval): array
    {
        return [
            'public_id' => $approval->public_id,
            'action_key' => $approval->action_key,
            'status' => $approval->status,
            'channel' => $approval->channel,
            'store_id' => $approval->store_id,
            'payload_hash' => $approval->payload_hash,
            'context_hash' => $approval->context_hash,
            'idempotency_key' => $approval->idempotency_key,
            'expires_at' => $approval->expires_at?->toIso8601String(),
            'approved_at' => $approval->approved_at?->toIso8601String(),
            'executed_at' => $approval->executed_at?->toIso8601String(),
            'result' => $approval->result,
        ];
    }
}
