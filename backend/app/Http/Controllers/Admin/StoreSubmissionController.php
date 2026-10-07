<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobileStoreSubmission;
use App\Models\StoreReviewerAccount;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class StoreSubmissionController extends Controller
{
    public function updateSubmission(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorize($request);
        $data = $request->validate([
            'app' => ['required', 'in:customer,driver,van'],
            'platform' => ['required', 'in:android,ios'],
            'environment' => ['required', 'in:development,staging,production'],
            'package_identifier' => ['nullable', 'string', 'max:255'],
            'current_version' => ['nullable', 'string', 'max:64'],
            'current_build' => ['nullable', 'string', 'max:64'],
            'minimum_version' => ['nullable', 'string', 'max:64'],
            'recommended_version' => ['nullable', 'string', 'max:64'],
            'update_policy' => ['required', 'in:optional,recommended,required'],
            'store_url' => ['nullable', 'url', 'max:2048'],
            'privacy_url' => ['nullable', 'url', 'max:2048'],
            'terms_url' => ['nullable', 'url', 'max:2048'],
            'support_url' => ['nullable', 'url', 'max:2048'],
            'delete_account_url' => ['nullable', 'url', 'max:2048'],
            'release_notes_ar' => ['nullable', 'string', 'max:10000'],
            'release_notes_en' => ['nullable', 'string', 'max:10000'],
            'title' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'full_description' => ['nullable', 'string', 'max:30000'],
            'category' => ['nullable', 'string', 'max:255'],
            'keywords' => ['nullable', 'string', 'max:2000'],
            'reviewer_notes' => ['nullable', 'string', 'max:10000'],
            'asset_icon_master' => ['nullable', 'in:repository-controlled,external-manual,blocked'],
            'asset_splash_master' => ['nullable', 'in:repository-controlled,external-manual,blocked'],
            'asset_screenshots' => ['nullable', 'in:repository-controlled,external-manual-final-upload,external-manual-if-required,blocked'],
            'asset_promotional_assets' => ['nullable', 'in:repository-controlled,external-manual-final-upload,external-manual-if-required,blocked'],
            'permission_declarations_text' => ['nullable', 'string', 'max:10000'],
            'privacy_checklist_text' => ['nullable', 'string', 'max:10000'],
            'manual_gaps_text' => ['nullable', 'string', 'max:10000'],
            // Legacy compatibility only; Dashboard uses structured business controls.
            'asset_checklist_json' => ['nullable', 'json'],
            'permission_declarations_json' => ['nullable', 'json'],
            'privacy_checklist_json' => ['nullable', 'json'],
            'manual_gaps_json' => ['nullable', 'json'],
            'signing_readiness' => ['required', 'in:PASS,WARN,BLOCKED'],
            'firebase_readiness' => ['required', 'in:PASS,WARN,BLOCKED'],
            'apns_readiness' => ['required', 'in:PASS,WARN,BLOCKED'],
            'deep_link_readiness' => ['required', 'in:PASS,WARN,BLOCKED'],
            'production_environment_readiness' => ['required', 'in:PASS,WARN,BLOCKED'],
            'readiness_state' => ['required', 'in:PASS,WARN,BLOCKED'],
            'submission_status' => ['required', 'in:NOT_READY,READY,SUBMITTED,IN_REVIEW,APPROVED,REJECTED,PUBLISHED'],
        ]);

        $scope = [
            'app' => $data['app'],
            'platform' => $data['platform'],
            'environment' => $data['environment'],
        ];
        $before = MobileStoreSubmission::query()->where($scope)->first();

        $values = collect($data)->except([
            'app', 'platform', 'environment',
            'asset_icon_master', 'asset_splash_master', 'asset_screenshots', 'asset_promotional_assets',
            'permission_declarations_text', 'privacy_checklist_text', 'manual_gaps_text',
            'asset_checklist_json', 'permission_declarations_json',
            'privacy_checklist_json', 'manual_gaps_json',
        ])->all();

        if (array_key_exists('asset_icon_master', $data)
            || array_key_exists('asset_splash_master', $data)
            || array_key_exists('asset_screenshots', $data)
            || array_key_exists('asset_promotional_assets', $data)) {
            $assets = is_array($before?->asset_checklist) ? $before->asset_checklist : [];
            foreach ([
                'asset_icon_master' => 'icon_master',
                'asset_splash_master' => 'splash_master',
                'asset_screenshots' => 'screenshots',
                'asset_promotional_assets' => 'promotional_assets',
            ] as $input => $key) {
                if (! array_key_exists($input, $data)) {
                    continue;
                }

                $value = trim((string) ($data[$input] ?? ''));
                if ($value === '') {
                    unset($assets[$key]);
                } else {
                    $assets[$key] = $value;
                }
            }
            $values['asset_checklist'] = $assets;
        } elseif (array_key_exists('asset_checklist_json', $data)) {
            $values['asset_checklist'] = $this->decode($data['asset_checklist_json'] ?? null);
        }

        foreach ([
            'permission_declarations_text' => ['column' => 'permission_declarations', 'legacy' => 'permission_declarations_json'],
            'privacy_checklist_text' => ['column' => 'privacy_checklist', 'legacy' => 'privacy_checklist_json'],
            'manual_gaps_text' => ['column' => 'manual_gaps', 'legacy' => 'manual_gaps_json'],
        ] as $textInput => $mapping) {
            if (array_key_exists($textInput, $data)) {
                $values[$mapping['column']] = $this->lines($data[$textInput] ?? null);
            } elseif (array_key_exists($mapping['legacy'], $data)) {
                $values[$mapping['column']] = $this->decode($data[$mapping['legacy']] ?? null);
            }
        }

        $submission = MobileStoreSubmission::query()->updateOrCreate($scope, $values);

        $audit->record(
            'mobile_store_submission.updated',
            $actor,
            $submission,
            $before?->toArray(),
            $submission->toArray(),
            $request,
        );

        return $this->backToSettings($data['app'], $data['environment'])
            ->with('status', 'Store submission metadata saved.');
    }

    public function upsertReviewer(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->authorize($request);
        $data = $request->validate([
            'app' => ['required', 'in:customer,driver,van'],
            'platform' => ['required', 'in:android,ios'],
            'environment' => ['required', 'in:development,staging,production'],
            'persona' => ['required', 'string', 'max:64'],
            'identifier_type' => ['required', 'in:email,username,phone'],
            'identifier' => ['required', 'string', 'max:255'],
            'reviewer_secret' => ['nullable', 'string', 'min:8', 'max:255'],
            'reviewer_channel' => ['nullable', 'in:b2b,b2c'],
            'reviewer_store_id' => ['nullable', 'integer', 'exists:stores,id'],
            // Legacy compatibility only; Dashboard uses structured context controls.
            'context_json' => ['nullable', 'json'],
            'reviewer_instructions' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $scope = collect($data)->only(['app', 'platform', 'environment', 'persona'])->all();
        $reviewer = StoreReviewerAccount::query()->where($scope)->first();
        $before = $reviewer?->toArray();

        if (! $reviewer instanceof StoreReviewerAccount && empty($data['reviewer_secret'])) {
            throw ValidationException::withMessages([
                'reviewer_secret' => ['A reviewer secret is required when creating the reviewer persona.'],
            ]);
        }

        $context = is_array($reviewer?->context) ? $reviewer->context : [];
        if (array_key_exists('reviewer_channel', $data) || array_key_exists('reviewer_store_id', $data)) {
            $channel = trim((string) ($data['reviewer_channel'] ?? ''));
            $storeId = isset($data['reviewer_store_id']) ? (int) $data['reviewer_store_id'] : 0;

            if ($channel === '') {
                unset($context['channel']);
            } else {
                $context['channel'] = $channel;
            }

            if ($storeId <= 0) {
                unset($context['store_id']);
            } else {
                $storeChannel = strtolower((string) DB::table('stores')
                    ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                    ->where('stores.id', $storeId)
                    ->value('store_types.code'));
                if ($channel !== '' && $storeChannel !== $channel) {
                    throw ValidationException::withMessages([
                        'reviewer_store_id' => ['The selected store does not belong to the selected business channel.'],
                    ]);
                }
                $context['store_id'] = $storeId;
            }
        } elseif (array_key_exists('context_json', $data)) {
            $context = $this->decode($data['context_json'] ?? null) ?? [];
        }

        $values = [
            'identifier_type' => $data['identifier_type'],
            'identifier' => trim($data['identifier']),
            'context' => $context,
            'reviewer_instructions' => $data['reviewer_instructions'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'readiness_status' => 'BLOCKED',
            'readiness_message' => 'Credential or context changed; readiness test required.',
        ];

        if (! empty($data['reviewer_secret'])) {
            $values['secret_encrypted'] = $data['reviewer_secret'];
            $values['secret_rotated_at'] = now();
        }

        $reviewer = StoreReviewerAccount::query()->updateOrCreate($scope, $values);

        $audit->record(
            'store_reviewer_account.updated',
            $actor,
            $reviewer,
            $before,
            [
                ...$reviewer->makeHidden(['secret_encrypted'])->toArray(),
                'secret' => $reviewer->maskedSecret(),
            ],
            $request,
        );

        return $this->backToSettings($data['app'], $data['environment'])
            ->with('status', 'Reviewer account configuration saved.');
    }

    public function rotateReviewer(
        Request $request,
        StoreReviewerAccount $reviewer,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->authorize($request);
        $data = $request->validate([
            'reviewer_secret' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $reviewer->forceFill([
            'secret_encrypted' => $data['reviewer_secret'],
            'secret_rotated_at' => now(),
            'readiness_status' => 'BLOCKED',
            'readiness_message' => 'Secret rotated; readiness test required.',
            'last_tested_at' => null,
        ])->save();

        $audit->record(
            'store_reviewer_account.secret_rotated',
            $actor,
            $reviewer,
            null,
            ['secret' => '[ROTATED]'],
            $request,
        );

        return $this->backToSettings($reviewer->app, $reviewer->environment)
            ->with('status', 'Reviewer secret rotated.');
    }

    public function testReviewer(
        Request $request,
        StoreReviewerAccount $reviewer,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->authorize($request);
        $secret = $reviewer->secret_encrypted;

        if (! is_string($secret) || $secret === '') {
            throw ValidationException::withMessages([
                'reviewer' => ['Reviewer secret is not configured.'],
            ]);
        }

        $user = $this->resolveReviewerUser($reviewer);
        $ready = $user instanceof User
            && $user->is_active
            && Hash::check($secret, (string) $user->password)
            && $this->matchesApp($reviewer, $user);

        $reviewer->forceFill([
            'readiness_status' => $ready ? 'PASS' : 'BLOCKED',
            'readiness_message' => $ready
                ? 'Authentication and application persona verified.'
                : 'Authentication failed or the account does not match the configured app persona.',
            'last_tested_at' => now(),
        ])->save();

        $audit->record(
            'store_reviewer_account.readiness_tested',
            $actor,
            $reviewer,
            null,
            [
                'status' => $reviewer->readiness_status,
                'identifier_type' => $reviewer->identifier_type,
                'app' => $reviewer->app,
                'platform' => $reviewer->platform,
                'environment' => $reviewer->environment,
            ],
            $request,
        );

        return $this->backToSettings($reviewer->app, $reviewer->environment)
            ->with($ready ? 'status' : 'error', $reviewer->readiness_message);
    }

    private function resolveReviewerUser(StoreReviewerAccount $reviewer): ?User
    {
        $identifier = trim((string) $reviewer->identifier);

        if ($reviewer->identifier_type === 'email') {
            return User::query()->whereRaw('LOWER(email) = ?', [strtolower($identifier)])->first();
        }

        if ($reviewer->identifier_type === 'username') {
            return User::query()->whereRaw('LOWER(username) = ?', [strtolower($identifier)])->first();
        }

        foreach (['b2c_customers', 'b2b_customers', 'customers'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)
                || ! DB::getSchemaBuilder()->hasColumn($table, 'phone')
                || ! DB::getSchemaBuilder()->hasColumn($table, 'user_id')) {
                continue;
            }

            $userId = DB::table($table)->where('phone', $identifier)->value('user_id');
            if ($userId !== null) {
                return User::query()->find((int) $userId);
            }
        }

        return null;
    }

    private function matchesApp(StoreReviewerAccount $reviewer, User $user): bool
    {
        if ($reviewer->app === 'van') {
            return $user->hasPermission('van.login');
        }

        if ($reviewer->app === 'driver') {
            return DB::table('drivers')
                ->where('user_id', $user->getKey())
                ->where('is_active', true)
                ->exists();
        }

        foreach (['b2c_customers', 'b2b_customers', 'customers', 'platform_customers'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)
                && DB::getSchemaBuilder()->hasColumn($table, 'user_id')
                && DB::table($table)->where('user_id', $user->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    private function authorize(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->hasPermission('mobile_settings.manage'), 403);

        return $actor;
    }

    private function decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private function lines(?string $value): array
    {
        return collect(preg_split('/\R/u', (string) $value) ?: [])
            ->map(static fn ($line): string => trim((string) $line))
            ->filter(static fn (string $line): bool => $line !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function backToSettings(string $app, string $environment): RedirectResponse
    {
        return redirect()->route('admin.mobile-settings.index', compact('app', 'environment'));
    }
}
