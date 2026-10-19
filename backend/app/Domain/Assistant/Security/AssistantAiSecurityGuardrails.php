<?php

namespace App\Domain\Assistant\Security;

use App\Domain\Assistant\Data\AssistantProviderRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Tools\AssistantToolDefinition;
use App\Models\User;
use App\Services\AssistantProviderSettings;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class AssistantAiSecurityGuardrails
{
    private const REDACTED = '[REDACTED]';

    private const UNTRUSTED_INSTRUCTION = '[UNTRUSTED_INSTRUCTION_REMOVED]';

    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'secret',
        'client_secret',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'credentials',
        'session_id',
    ];

    private const BLOCKED_ARGUMENT_KEYS = [
        'actor_user_id',
        'permission_codes',
        'role_codes',
        'permitted_store_ids',
        'system_prompt',
        'developer_prompt',
        'hidden_prompt',
        'sql',
        'raw_sql',
        'query_sql',
        'database',
        'connection',
        'authorization',
        'api_key',
        'token',
        'secret',
        'password',
    ];

    private const INSTRUCTION_PATTERNS = [
        '/\bignore\s+(?:all\s+|any\s+|the\s+)?(?:previous|prior|above|earlier|system|developer)\s+(?:instructions?|rules?|prompts?)\b/iu',
        '/\b(?:reveal|show|print|dump|repeat|expose|leak)\b.{0,80}\b(?:system|developer|hidden|internal)\s+(?:prompt|instructions?|configuration|config)\b/iu',
        '/\b(?:reveal|show|print|dump|give|expose|leak)\b.{0,80}\b(?:api[_ -]?key|access[_ -]?token|secret|password|credential)\b/iu',
        '/\b(?:system|developer)\s*:\s*(?:ignore|override|follow|execute|call)\b/iu',
        '/\b(?:call|invoke|execute)\b.{0,40}\b(?:tool|function)\b.{0,60}\b(?:without|bypass|ignore|override)\b/iu',
        '/\b(?:bypass|override|disable)\b.{0,60}\b(?:authorization|permission|rbac|tenant|store|consent|approval)\b/iu',
        '/\b(?:exfiltrate|export|send)\b.{0,80}\b(?:all|every)\b.{0,40}\b(?:customer|retailer|tenant|secret|credential|token|key)\b/iu',
        '/(?:تجاهل|تخط(?:ى|ي)|جاوز).{0,50}(?:التعليمات|التوجيهات|الأوامر|الصلاحيات|التصاريح)/u',
        '/(?:اكشف|اظهر|أظهر|اعرض|سرّب|سرب).{0,70}(?:البرومبت|التعليمات\s+الداخلية|مفتاح\s*(?:api|API)|التوكن|الرمز\s+السري|كلمة\s+المرور)/u',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AssistantProviderSettings $providerSettings,
    ) {}

    public function providerEnabled(): bool
    {
        return (bool) config('assistant.security.provider_enabled', true);
    }

    public function toolsEnabled(): bool
    {
        return (bool) config('assistant.security.tools_enabled', true);
    }

    public function writeActionsEnabled(): bool
    {
        return (bool) config('assistant.security.write_actions_enabled', false);
    }

    public function isPromptInjection(string $value): bool
    {
        $value = $this->normalizeControls($value);

        return $this->containsInstructionPattern($value)
            || $this->containsEncodedInjection($value);
    }

    public function sanitizeProviderRequest(AssistantProviderRequest $request): AssistantProviderRequest
    {
        $redacted = 0;
        $context = $this->sanitizeArray($request->context, $redacted);
        $maxChars = min(32000, max(1000, (int) config('assistant.security.max_user_chars', 8000)));
        $userContent = mb_substr($this->normalizeControls($request->userContent), 0, $maxChars);

        return new AssistantProviderRequest(
            systemInstructions: $request->systemInstructions,
            taskInstructions: $request->taskInstructions,
            userContent: $userContent,
            context: $context,
            allowedTools: $this->toolsEnabled() ? $request->allowedTools : [],
            responseSchema: $request->responseSchema,
            temperature: $request->temperature,
            maxTokens: $request->maxTokens,
            timeoutSeconds: $request->timeoutSeconds,
            correlationId: $request->correlationId,
            useCase: $request->useCase,
        );
    }

    public function sanitizeProviderOutput(
        ?string $text,
        AssistantProviderRequest $request,
    ): ?string {
        if ($text === null) {
            return null;
        }

        $clean = $this->redactSecrets($text);
        $system = trim($request->systemInstructions);
        $task = trim($request->taskInstructions);

        foreach ([$system, $task] as $hiddenInstruction) {
            if (mb_strlen($hiddenInstruction) >= 24 && str_contains($clean, $hiddenInstruction)) {
                $clean = str_replace($hiddenInstruction, self::REDACTED, $clean);
            }
        }

        if ($clean !== $text) {
            $this->auditSecurityEvent('assistant.security.provider_output_redacted', null, [
                'correlation_id' => $request->correlationId,
                'use_case' => $request->useCase,
            ]);
        }

        return $clean;
    }

    public function recordPromptBlock(AssistantProviderRequest $request): void
    {
        $this->auditSecurityEvent('assistant.security.prompt_blocked', null, [
            'correlation_id' => $request->correlationId,
            'use_case' => $request->useCase,
            'reason' => 'PROMPT_INJECTION',
        ]);
    }

    public function assertToolAllowed(
        AssistantToolDefinition $definition,
        User $actor,
        string $correlationId,
    ): void {
        if (! $this->toolsEnabled()) {
            $this->auditSecurityEvent('assistant.security.tool_blocked', $actor, [
                'tool' => $definition->key,
                'correlation_id' => $correlationId,
                'reason' => 'TOOLS_KILL_SWITCH',
            ]);

            abort(503);
        }

        if (strtoupper($definition->mode) !== 'READ' && ! $this->writeActionsEnabled()) {
            $this->auditSecurityEvent('assistant.security.tool_blocked', $actor, [
                'tool' => $definition->key,
                'correlation_id' => $correlationId,
                'reason' => 'WRITE_KILL_SWITCH',
            ]);

            abort(403);
        }
    }

    /**
     * @param  array<string,mixed>  $arguments
     */
    public function assertToolArgumentsSafe(
        array $arguments,
        User $actor,
        string $toolKey,
        string $correlationId,
    ): void {
        if (! $this->unsafeArgument($arguments)) {
            return;
        }

        $this->auditSecurityEvent('assistant.security.tool_argument_blocked', $actor, [
            'tool' => $toolKey,
            'correlation_id' => $correlationId,
            'reason' => 'TOOL_ARGUMENT_INJECTION',
        ]);

        throw ValidationException::withMessages([
            'tool_arguments' => ['Unsafe Assistant tool arguments were rejected.'],
        ]);
    }

    public function sanitizeToolResult(
        AssistantToolResult $result,
        User $actor,
        string $toolKey,
        string $correlationId,
    ): AssistantToolResult {
        $redacted = 0;
        $data = $this->sanitizeArray($result->data, $redacted);
        $data = $this->enforceCohortPrivacy($data, $actor, $toolKey, $correlationId);

        if (($data['answerable'] ?? null) === false
            && in_array($data['reason'] ?? null, ['COHORT_TOO_SMALL', 'DIFFERENCING_RISK'], true)) {
            return new AssistantToolResult(data: $data);
        }

        $cards = $this->sanitizeList($result->cards, $redacted);
        $references = $this->sanitizeList($result->references, $redacted);

        if ($redacted > 0) {
            $this->auditSecurityEvent('assistant.security.result_redacted', $actor, [
                'tool' => $toolKey,
                'correlation_id' => $correlationId,
                'redacted_fields' => $redacted,
            ]);
        }

        return new AssistantToolResult(
            data: $data,
            cards: $cards,
            actions: $result->actions,
            references: $references,
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function enforceCohortPrivacy(
        array $data,
        User $actor,
        string $toolKey,
        string $correlationId,
    ): array {
        $privacy = is_array($data['_privacy'] ?? null)
            ? $data['_privacy']
            : (is_array($data['privacy'] ?? null) ? $data['privacy'] : []);

        $cohortSize = filter_var(
            $privacy['cohort_size'] ?? $data['cohort_size'] ?? null,
            FILTER_VALIDATE_INT,
        );

        if (! is_int($cohortSize) || $cohortSize < 0) {
            return $data;
        }

        $minimum = min(100, max(2, (int) config('assistant.security.cohort_minimum', 5)));
        if ($cohortSize < $minimum) {
            return $this->blockedCohort(
                $actor,
                $toolKey,
                $correlationId,
                'COHORT_TOO_SMALL',
                $minimum,
            );
        }

        $cohortKey = trim((string) ($privacy['cohort_key'] ?? ''));
        $metricKey = trim((string) ($privacy['metric_key'] ?? ''));
        if ($cohortKey === '' || $metricKey === '') {
            return $data;
        }

        $members = $this->positiveIds($privacy['member_ids'] ?? []);
        $fingerprint = $members === []
            ? null
            : hash('sha256', implode(',', $members));

        foreach (['_privacy', 'privacy'] as $privacyKey) {
            if (is_array($data[$privacyKey] ?? null)) {
                unset($data[$privacyKey]['member_ids']);
            }
        }

        $cacheKey = 'assistant:privacy:difference:'.hash(
            'sha256',
            $actor->getKey().'|'.$toolKey.'|'.$metricKey.'|'.$cohortKey,
        );
        $previous = Cache::get($cacheKey);
        $minimumDelta = min(20, max(2, (int) config('assistant.security.differencing_min_delta', 2)));

        if (is_array($previous)) {
            $previousSize = (int) ($previous['cohort_size'] ?? 0);
            $sizeDelta = abs($previousSize - $cohortSize);
            $fingerprintChanged = $fingerprint !== null
                && is_string($previous['fingerprint'] ?? null)
                && $previous['fingerprint'] !== $fingerprint;

            if (($sizeDelta > 0 && $sizeDelta < $minimumDelta)
                || ($fingerprintChanged && $sizeDelta < $minimumDelta)) {
                return $this->blockedCohort(
                    $actor,
                    $toolKey,
                    $correlationId,
                    'DIFFERENCING_RISK',
                    $minimum,
                );
            }
        }

        $ttl = min(86400, max(60, (int) config('assistant.security.differencing_ttl_seconds', 900)));
        Cache::put($cacheKey, [
            'cohort_size' => $cohortSize,
            'fingerprint' => $fingerprint,
        ], now()->addSeconds($ttl));

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedCohort(
        User $actor,
        string $toolKey,
        string $correlationId,
        string $reason,
        int $minimum,
    ): array {
        $this->auditSecurityEvent('assistant.security.cohort_blocked', $actor, [
            'tool' => $toolKey,
            'correlation_id' => $correlationId,
            'reason' => $reason,
            'minimum_cohort_size' => $minimum,
        ]);

        return [
            'found' => false,
            'answerable' => false,
            'reason' => $reason,
            'privacy' => [
                'minimum_cohort_size' => $minimum,
                'differencing_protected' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function sanitizeList(array $items, int &$redacted): array
    {
        $limit = min(250, max(10, (int) config('assistant.security.max_collection_items', 100)));
        $items = array_slice($items, 0, $limit);

        return array_map(
            fn (array $item): array => $this->sanitizeArray($item, $redacted),
            $items,
        );
    }

    /**
     * @param  array<array-key,mixed>  $values
     * @return array<array-key,mixed>
     */
    private function sanitizeArray(array $values, int &$redacted, int $depth = 0, bool $untrusted = false): array
    {
        $maxDepth = min(12, max(2, (int) config('assistant.security.max_result_depth', 6)));
        if ($depth >= $maxDepth) {
            $redacted++;

            return ['_truncated' => true];
        }

        $limit = min(250, max(10, (int) config('assistant.security.max_collection_items', 100)));
        $clean = [];
        $isList = array_is_list($values);
        $values = array_slice($values, 0, $limit, true);
        $documentUntrusted = $untrusted || (($values['untrusted_document_text'] ?? false) === true);

        foreach ($values as $key => $value) {
            $normalizedKey = is_string($key) ? $this->normalizeKey($key) : '';

            if (! $isList && $this->isSensitiveKey($normalizedKey)) {
                $redacted++;

                continue;
            }

            $childUntrusted = $documentUntrusted
                || in_array($normalizedKey, ['excerpt', 'content', 'document_text', 'retrieved_text'], true);

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeArray($value, $redacted, $depth + 1, $childUntrusted);

                continue;
            }

            if (is_string($value)) {
                $sanitized = $this->redactSecrets($value);
                if ($childUntrusted) {
                    $sanitized = $this->neutralizeUntrustedText($sanitized);
                }

                $maxChars = min(20000, max(256, (int) config('assistant.security.max_result_string_chars', 4000)));
                if (mb_strlen($sanitized) > $maxChars) {
                    $sanitized = mb_substr($sanitized, 0, $maxChars);
                    $redacted++;
                }

                if ($sanitized !== $value) {
                    $redacted++;
                }

                $clean[$key] = $sanitized;

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function unsafeArgument(array $values): bool
    {
        foreach ($values as $key => $value) {
            $normalized = is_string($key) ? $this->normalizeKey($key) : '';

            if ($normalized !== '' && (
                in_array($normalized, self::BLOCKED_ARGUMENT_KEYS, true)
                || $this->isSensitiveKey($normalized)
            )) {
                return true;
            }

            if (is_array($value) && $this->unsafeArgument($value)) {
                return true;
            }

            if (is_string($value) && (
                $this->isPromptInjection($value)
                || preg_match('/\b(?:union\s+select|select\s+.+\s+from|insert\s+into|update\s+.+\s+set|delete\s+from|drop\s+(?:table|database))\b/iu', $value) === 1
            )) {
                return true;
            }
        }

        return false;
    }

    private function redactSecrets(string $value): string
    {
        $clean = $value;
        $apiKey = $this->providerSettings->apiKey();

        if (is_string($apiKey) && mb_strlen($apiKey) >= 8) {
            $clean = str_replace($apiKey, self::REDACTED, $clean);
        }

        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}\b/i',
            '/\bsk-[A-Za-z0-9_-]{12,}\b/i',
            '/\b(?:api[_ -]?key|access[_ -]?token|refresh[_ -]?token|client[_ -]?secret|password|secret)\s*[:=]\s*[\"\']?[^\s,;\"\']{6,}/iu',
        ];

        foreach ($patterns as $pattern) {
            $clean = preg_replace($pattern, self::REDACTED, $clean) ?? $clean;
        }

        return $clean;
    }

    private function neutralizeUntrustedText(string $value): string
    {
        $lines = preg_split('/\R/u', $value) ?: [$value];
        $safe = [];

        foreach ($lines as $line) {
            if ($this->containsInstructionPattern($line) || $this->containsEncodedInjection($line)) {
                $safe[] = self::UNTRUSTED_INSTRUCTION;

                continue;
            }

            $safe[] = $line;
        }

        return implode("\n", $safe);
    }

    private function containsInstructionPattern(string $value): bool
    {
        foreach (self::INSTRUCTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function containsEncodedInjection(string $value): bool
    {
        $count = preg_match_all(
            '/(?<![A-Za-z0-9+\/=])[A-Za-z0-9+\/]{24,}={0,2}(?![A-Za-z0-9+\/=])/',
            $value,
            $matches,
        );
        if (! is_int($count) || $count < 1) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            $decoded = base64_decode($candidate, true);
            if (! is_string($decoded) || $decoded === '') {
                continue;
            }

            if ($this->containsInstructionPattern($decoded)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeControls(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return trim($value);
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', ' ', '.'], '_', trim($key)));
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($key === $sensitive || str_ends_with($key, '_'.$sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function positiveIds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $ids = [];

        foreach ($values as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function auditSecurityEvent(
        string $event,
        ?User $actor,
        array $metadata,
    ): void {
        $this->audit->record(
            $event,
            $actor,
            null,
            null,
            $metadata,
        );
    }
}
