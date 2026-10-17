<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\CampaignAudienceService;
use App\Domain\WhatsApp\CampaignContactImportService;
use App\Domain\WhatsApp\CampaignReportExporter;
use App\Domain\WhatsApp\CampaignReportService;
use App\Domain\WhatsApp\CampaignService;
use App\Domain\WhatsApp\MarketingConsentService;
use App\Domain\WhatsApp\MessagingOperationsOverviewService;
use App\Domain\WhatsApp\MetaTemplateService;
use App\Domain\WhatsApp\TemplateRegistryService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WhatsAppCampaignController extends Controller
{
    public function index(
        Request $request,
        CampaignService $campaigns,
        CampaignReportService $reports,
        MessagingOperationsOverviewService $operations,
        OperationalTenantScope $scope,
    ): View|JsonResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.view');

        $rows = DB::table('whats_app_campaigns')
            ->leftJoin('whats_app_templates', 'whats_app_templates.id', '=', 'whats_app_campaigns.template_id')
            ->orderByDesc('whats_app_campaigns.id')
            ->limit(100)
            ->get([
                'whats_app_campaigns.*',
                'whats_app_templates.name as template_name',
                'whats_app_templates.provider_status as template_status',
            ])
            ->filter(function (object $row) use ($reports, $actor): bool {
                try {
                    $reports->assertVisible($actor, (int) $row->id);

                    return true;
                } catch (HttpException) {
                    return false;
                }
            })
            ->map(function (object $row) use ($campaigns): object {
                $row->metrics = $campaigns->metrics((int) $row->id);

                return $row;
            })
            ->values();

        $templates = DB::table('whats_app_templates')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $operationsOverview = $operations->overview($actor);

        if ($request->expectsJson()) {
            return response()->json([
                'campaigns' => $rows,
                'templates' => $templates,
                'operations_overview' => $operationsOverview,
            ]);
        }

        $storeIds = $scope->allowedStoreIds($actor, 'whatsapp.campaigns.view');
        $stores = DB::table('stores as s')
            ->join('store_types as st', 'st.id', '=', 's.store_type_id')
            ->whereIn('s.id', $storeIds)
            ->whereIn('st.code', ['B2B', 'B2C'])
            ->orderBy('s.name')
            ->get(['s.id', 's.name', DB::raw('LOWER(st.code) as channel')]);

        $tags = DB::table('whats_app_tags')->orderBy('name')->limit(200)->get(['id', 'name']);
        $agents = DB::table('whats_app_customer_assignments as a')
            ->join('platform_customers as pc', 'pc.id', '=', 'a.platform_customer_id')
            ->join('users as u', 'u.id', '=', 'a.primary_agent_id')
            ->where('a.is_active', true)
            ->where('u.is_active', true)
            ->whereIn('pc.origin_store_id', $storeIds)
            ->distinct()
            ->orderBy('u.name')
            ->limit(200)
            ->get(['u.id', 'u.name']);
        $categories = DB::table('categories')->orderBy('name')->limit(300)->get(['id', 'name']);

        return view('admin.whatsapp-campaigns.index', [
            'campaigns' => $rows,
            'templates' => $templates,
            'operationsOverview' => $operationsOverview,
            'stores' => $stores,
            'tags' => $tags,
            'agents' => $agents,
            'categories' => $categories,
            'user' => $actor,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => 'whatsapp_campaigns',
            'canManage' => $actor->hasPermission('whatsapp.campaigns.manage'),
            'canSend' => $actor->hasPermission('whatsapp.campaigns.send'),
        ]);
    }

    public function customers(Request $request, CampaignAudienceService $audience): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.view');
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
        ]);

        return response()->json(['customers' => $audience->search($actor, $data)]);
    }

    public function preview(
        Request $request,
        CampaignService $campaigns,
        CampaignAudienceService $audience,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $this->campaignInput($request, false);
        $segment = $audience->scopedSegment($actor, $this->segmentInput($data));

        $template = null;
        $templateApproved = false;
        if (! empty($data['template_id'])) {
            $template = DB::table('whats_app_templates')
                ->where('id', (int) $data['template_id'])
                ->first(['id', 'name', 'category', 'locale', 'provider_status', 'is_active']);
            $templateApproved = $template !== null
                && (bool) $template->is_active
                && strtolower((string) $template->provider_status) === 'approved';
        }

        $preview = $campaigns->preview(
            $segment,
            (bool) ($data['test_mode'] ?? true),
            $this->allowlist($data['test_allowlist'] ?? []),
            (int) ($data['max_recipients'] ?? 1000),
            (int) ($data['batch_size'] ?? 50),
            $templateApproved,
        );

        return response()->json($preview + [
            'segment' => $segment,
            'template' => $template === null ? null : [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'category' => strtolower((string) $template->category),
                'locale' => strtolower((string) $template->locale),
                'provider_status' => strtolower((string) $template->provider_status),
                'approved' => $templateApproved,
            ],
        ]);
    }

    public function importPreview(
        Request $request,
        CampaignContactImportService $imports,
        OperationalTenantScope $scope,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,xlsx'],
            'store_id' => ['required', 'integer', 'min:1'],
        ]);
        $scope->assertStore($actor, (int) $data['store_id'], 'whatsapp.campaigns.manage');

        /** @var UploadedFile $file */
        $file = $request->file('file');

        return response()->json([
            'ok' => true,
            'preview' => $imports->preview($file, $actor, (int) $data['store_id']),
        ]);
    }

    public function importCommit(
        Request $request,
        int $batch,
        CampaignContactImportService $imports,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'mapping.name' => ['required', 'string', 'max:255'],
            'mapping.phone' => ['required', 'string', 'max:255'],
            'mapping.email' => ['nullable', 'string', 'max:255'],
            'mapping.consent_state' => ['nullable', 'string', 'max:255'],
            'mapping.consent_source' => ['nullable', 'string', 'max:255'],
            'mapping.consent_evidence' => ['nullable', 'string', 'max:255'],
            'mapping.consent_date' => ['nullable', 'string', 'max:255'],
            'consent_state' => ['nullable', Rule::in([
                MarketingConsentService::GRANTED,
                MarketingConsentService::DENIED,
                MarketingConsentService::OPTED_OUT,
            ])],
            'consent_source' => ['nullable', 'string', 'max:64'],
            'consent_evidence' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $imports->commit(
            $batch,
            $actor,
            (int) $data['store_id'],
            $data['mapping'],
            $data['consent_state'] ?? null,
            $data['consent_source'] ?? null,
            $data['consent_evidence'] ?? null,
        );

        return response()->json(['ok' => true] + $result);
    }

    public function manualContacts(
        Request $request,
        CampaignAudienceService $audience,
        OperationalTenantScope $scope,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'contacts' => ['nullable', 'array', 'max:500'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:64'],
            'contacts.*.email' => ['nullable', 'string', 'max:255'],
            'contacts.*.consent_state' => ['nullable', Rule::in([
                MarketingConsentService::GRANTED,
                MarketingConsentService::DENIED,
                MarketingConsentService::OPTED_OUT,
            ])],
            'contacts.*.consent_source' => ['nullable', 'string', 'max:64'],
            'contacts.*.consent_evidence' => ['nullable', 'string', 'max:1000'],
            'contacts.*.consent_date' => ['nullable', 'string', 'max:64'],
            'manual_lines' => ['nullable', 'string', 'max:100000'],
            'consent_state' => ['nullable', Rule::in([
                MarketingConsentService::GRANTED,
                MarketingConsentService::DENIED,
                MarketingConsentService::OPTED_OUT,
            ])],
            'consent_source' => ['nullable', 'string', 'max:64'],
            'consent_evidence' => ['nullable', 'string', 'max:1000'],
        ]);
        $scope->assertStore($actor, (int) $data['store_id'], 'whatsapp.campaigns.manage');

        $rows = array_values($data['contacts'] ?? []);
        if ($rows === [] && trim((string) ($data['manual_lines'] ?? '')) !== '') {
            $rows = $this->manualRows((string) $data['manual_lines']);
        }
        if ($rows === []) {
            return response()->json([
                'ok' => false,
                'message' => 'Add at least one contact.',
            ], 422);
        }

        [$batchId, $result] = DB::transaction(function () use ($actor, $rows, $data, $audience, $scope): array {
            $now = now();
            $batchId = (int) DB::table('whats_app_campaign_contact_imports')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'actor_user_id' => $actor->getKey(),
                'store_id' => (int) $data['store_id'],
                'channel' => $scope->storeChannel((int) $data['store_id']),
                'source_type' => 'manual',
                'original_filename' => null,
                'stored_path' => null,
                'status' => 'preview',
                'rows_total' => count($rows),
                'created_count' => 0,
                'matched_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'invalid_count' => 0,
                'summary' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $result = $audience->importRows(
                $actor,
                $rows,
                (int) $data['store_id'],
                'campaign_manual',
                $data['consent_state'] ?? null,
                $data['consent_source'] ?? null,
                $data['consent_evidence'] ?? null,
                $batchId,
            );

            DB::table('whats_app_campaign_contact_imports')->where('id', $batchId)->update([
                'status' => 'committed',
                'created_count' => $result['created'],
                'matched_count' => $result['matched'],
                'updated_count' => $result['updated'],
                'skipped_count' => $result['skipped'],
                'invalid_count' => $result['invalid'],
                'summary' => json_encode([
                    'customer_ids' => $result['customer_ids'],
                    'errors' => $result['errors'],
                    'consent_skipped' => $result['consent_skipped'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

            return [$batchId, $result];
        }, 3);

        return response()->json(['ok' => true, 'batch_id' => $batchId] + $result);
    }

    public function storeTemplate(Request $request, TemplateRegistryService $templates): JsonResponse|RedirectResponse
    {
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider_name' => ['required', 'string', 'max:160'],
            'locale' => ['required', 'string', 'max:16'],
            'category' => ['sometimes', 'string', 'max:32'],
            'provider_status' => ['required', 'string', 'max:24'],
            'body_preview' => ['nullable', 'string', 'max:4000'],
            'components' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $id = $templates->upsert($data, $request->user()?->getKey());

        return $this->mutationResponse($request, ['template_id' => $id], 'Template registry updated.');
    }

    public function createMetaTemplate(Request $request, MetaTemplateService $metaTemplates): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider_name' => ['required', 'string', 'max:160'],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'category' => ['required', Rule::in(['marketing', 'utility'])],
            'body_preview' => ['required', 'string', 'max:1024'],
        ]);

        $result = $metaTemplates->create($data, $actor->getKey());

        return $this->mutationResponse(
            $request,
            $result,
            __('whatsapp.campaigns.meta_template_created'),
        );
    }

    public function syncMetaTemplates(Request $request, MetaTemplateService $metaTemplates): JsonResponse|RedirectResponse
    {
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $result = $metaTemplates->sync();

        return $this->mutationResponse(
            $request,
            $result,
            __('whatsapp.campaigns.meta_templates_sync_summary', [
                'fetched' => $result['fetched'],
                'updated' => $result['updated'],
                'imported' => $result['imported'],
                'approved' => $result['approved'],
                'pending' => $result['pending'],
                'rejected' => $result['rejected'],
            ]),
        );
    }

    public function storeConsent(
        Request $request,
        MarketingConsentService $consent,
        OperationalTenantScope $scope,
    ): JsonResponse|RedirectResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $request->validate([
            'platform_customer_id' => ['required', 'integer', 'min:1'],
            'state' => ['required', Rule::in([
                MarketingConsentService::GRANTED,
                MarketingConsentService::DENIED,
                MarketingConsentService::OPTED_OUT,
            ])],
            'source' => ['required', 'string', 'max:64'],
            'evidence' => ['required', 'string', 'max:1000'],
        ]);

        $customer = DB::table('platform_customers')->where('id', (int) $data['platform_customer_id'])->first();
        abort_unless($customer !== null && $customer->origin_store_id !== null, 404);
        $scope->assertStore($actor, (int) $customer->origin_store_id, 'whatsapp.campaigns.manage');

        $id = $consent->record(
            (int) $data['platform_customer_id'],
            (string) $data['state'],
            (string) $data['source'],
            $actor->getKey(),
            ['evidence' => (string) $data['evidence']],
        );

        return $this->mutationResponse($request, ['consent_id' => $id], 'Marketing consent updated.');
    }

    public function storeCampaign(
        Request $request,
        CampaignService $campaigns,
        CampaignAudienceService $audience,
        CampaignReportService $reports,
        AuditLogger $audit,
    ): JsonResponse|RedirectResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $this->campaignInput($request, true);
        $segment = $audience->scopedSegment($actor, $this->segmentInput($data));
        $audienceSource = (string) ($data['audience_source'] ?? 'existing');

        if (($segment['customer_ids'] ?? []) === [] && ($segment['store_ids'] ?? []) === []) {
            return $this->validationFailure($request, 'audience', 'Select customers or an authorized store audience.');
        }

        if (! empty($data['import_batch_id'])) {
            $batch = DB::table('whats_app_campaign_contact_imports')
                ->where('id', (int) $data['import_batch_id'])
                ->where('actor_user_id', $actor->getKey())
                ->where('status', 'committed')
                ->first();
            if ($batch === null) {
                return $this->validationFailure($request, 'import_batch_id', 'Import batch is invalid or not committed.');
            }
        }

        $now = now();
        $id = (int) DB::table('whats_app_campaigns')->insertGetId([
            'name' => trim((string) $data['name']),
            'template_id' => (int) $data['template_id'],
            'status' => 'draft',
            'segment' => json_encode($segment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'audience_source' => $audienceSource,
            'import_batch_id' => empty($data['import_batch_id']) ? null : (int) $data['import_batch_id'],
            'test_mode' => (bool) ($data['test_mode'] ?? true),
            'test_allowlist' => json_encode($this->allowlist($data['test_allowlist'] ?? []), JSON_UNESCAPED_SLASHES),
            'batch_size' => (int) ($data['batch_size'] ?? 50),
            'per_minute_limit' => (int) ($data['per_minute_limit'] ?? 100),
            'max_recipients' => (int) ($data['max_recipients'] ?? 1000),
            'created_by' => $actor->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $snapshot = $campaigns->snapshot($id);
        $launch = (string) ($data['launch_action'] ?? 'draft');
        if ($launch === 'send_now') {
            $this->authorizePermission($request, 'whatsapp.campaigns.send');
            $campaigns->schedule($id, null);
        } elseif ($launch === 'schedule') {
            $this->authorizePermission($request, 'whatsapp.campaigns.send');
            $campaigns->schedule($id, $data['scheduled_at'] ?? null);
        }

        $reports->assertVisible($actor, $id);
        $audit->record(
            'whatsapp.campaign.created',
            $actor,
            null,
            null,
            $this->campaignAuditSnapshot($id),
            $request,
        );

        return $this->mutationResponse(
            $request,
            ['campaign_id' => $id, 'snapshot' => $snapshot],
            'Campaign created and recipient snapshot prepared.',
        );
    }

    public function snapshot(
        Request $request,
        int $campaign,
        CampaignService $campaigns,
        CampaignReportService $reports,
        AuditLogger $audit,
    ): JsonResponse|RedirectResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $reports->assertVisible($actor, $campaign, 'whatsapp.campaigns.manage');
        $before = $this->campaignAuditSnapshot($campaign);
        $result = $campaigns->snapshot($campaign);
        $audit->record(
            'whatsapp.campaign.snapshot_rebuilt',
            $actor,
            null,
            $before,
            $this->campaignAuditSnapshot($campaign) + ['snapshot_result' => $result],
            $request,
        );

        return $this->mutationResponse($request, $result, 'Recipient snapshot rebuilt.');
    }

    public function schedule(
        Request $request,
        int $campaign,
        CampaignService $campaigns,
        CampaignReportService $reports,
        AuditLogger $audit,
    ): JsonResponse|RedirectResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.send');
        $reports->assertVisible($actor, $campaign, 'whatsapp.campaigns.send');
        $data = $request->validate(['scheduled_at' => ['nullable', 'date']]);
        $before = $this->campaignAuditSnapshot($campaign);
        $campaigns->schedule($campaign, $data['scheduled_at'] ?? null);
        $audit->record(
            'whatsapp.campaign.scheduled',
            $actor,
            null,
            $before,
            $this->campaignAuditSnapshot($campaign),
            $request,
        );

        return $this->mutationResponse($request, ['campaign_id' => $campaign], 'Campaign scheduled.');
    }

    public function pause(
        Request $request,
        int $campaign,
        CampaignService $campaigns,
        CampaignReportService $reports,
        AuditLogger $audit,
    ): JsonResponse|RedirectResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.send');
        $reports->assertVisible($actor, $campaign, 'whatsapp.campaigns.send');
        $before = $this->campaignAuditSnapshot($campaign);
        $campaigns->pause($campaign);
        $audit->record(
            'whatsapp.campaign.paused',
            $actor,
            null,
            $before,
            $this->campaignAuditSnapshot($campaign),
            $request,
        );

        return $this->mutationResponse($request, ['campaign_id' => $campaign], 'Campaign paused.');
    }

    public function cancel(
        Request $request,
        int $campaign,
        CampaignService $campaigns,
        CampaignReportService $reports,
        AuditLogger $audit,
    ): JsonResponse|RedirectResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.send');
        $reports->assertVisible($actor, $campaign, 'whatsapp.campaigns.send');
        $before = $this->campaignAuditSnapshot($campaign);
        $campaigns->cancel($campaign);
        $audit->record(
            'whatsapp.campaign.cancelled',
            $actor,
            null,
            $before,
            $this->campaignAuditSnapshot($campaign),
            $request,
        );

        return $this->mutationResponse($request, ['campaign_id' => $campaign], 'Campaign cancelled.');
    }

    public function report(
        Request $request,
        int $campaign,
        CampaignReportService $reports,
    ): View|JsonResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.view');
        $overview = $reports->overview($actor, $campaign);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'send_status' => ['nullable', 'string', 'max:32'],
            'eligibility_status' => ['nullable', 'string', 'max:32'],
            'suppression_reason' => ['nullable', 'string', 'max:64'],
            'consent_state' => ['nullable', 'string', 'max:24'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'agent_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'template_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:24'],
        ]);
        $recipients = $reports->recipients($actor, $campaign, $filters);
        $recipients->setCollection($recipients->getCollection()->map(function (object $row): object {
            $snapshot = json_decode((string) $row->snapshot, true);
            $row->snapshot_data = is_array($snapshot) ? $snapshot : [];

            return $row;
        }));
        $comparison = $reports->comparison($actor, $filters);

        if ($request->expectsJson()) {
            return response()->json([
                'overview' => $overview,
                'recipients' => $recipients,
                'comparison' => $comparison,
            ]);
        }

        $reportStoreIds = app(OperationalTenantScope::class)
            ->allowedStoreIds($actor, 'whatsapp.campaigns.view');
        $reportAgents = DB::table('whats_app_customer_assignments as a')
            ->join('platform_customers as pc', 'pc.id', '=', 'a.platform_customer_id')
            ->join('users as u', 'u.id', '=', 'a.primary_agent_id')
            ->where('a.is_active', true)
            ->where('u.is_active', true)
            ->whereIn('pc.origin_store_id', $reportStoreIds)
            ->distinct()
            ->orderBy('u.name')
            ->get(['u.id', 'u.name']);

        return view('admin.whatsapp-campaigns.report', [
            'overview' => $overview,
            'recipients' => $recipients,
            'comparison' => $comparison,
            'filters' => $filters,
            'reportStores' => DB::table('stores as s')
                ->join('store_types as st', 'st.id', '=', 's.store_type_id')
                ->whereIn('s.id', $reportStoreIds)
                ->orderBy('s.name')
                ->get(['s.id', 's.name', DB::raw('LOWER(st.code) as channel')]),
            'reportAgents' => $reportAgents,
            'reportTemplates' => DB::table('whats_app_templates')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'user' => $actor,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => 'whatsapp_campaigns',
        ]);
    }

    public function export(
        Request $request,
        int $campaign,
        string $format,
        CampaignReportService $reports,
        CampaignReportExporter $exporter,
    ): Response|BinaryFileResponse {
        $actor = $this->actor($request);
        $this->authorizePermission($request, 'whatsapp.campaigns.view');
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $campaignRow = $reports->assertVisible($actor, $campaign);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'send_status' => ['nullable', 'string', 'max:32'],
            'eligibility_status' => ['nullable', 'string', 'max:32'],
            'suppression_reason' => ['nullable', 'string', 'max:64'],
            'consent_state' => ['nullable', 'string', 'max:24'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'agent_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $rows = $reports->exportRows($actor, $campaign, $filters);
        $base = Str::slug((string) $campaignRow->name) ?: 'campaign-'.$campaign;

        if ($format === 'csv') {
            return response(
                "\xEF\xBB\xBF".$exporter->csv($rows),
                200,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="'.$base.'-recipients.csv"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ],
            );
        }

        return response()
            ->download(
                $exporter->xlsx($rows),
                $base.'-recipients.xlsx',
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ],
            )
            ->deleteFileAfterSend(true);
    }

    /** @return array<string,mixed> */
    private function campaignAuditSnapshot(int $campaignId): array
    {
        $row = DB::table('whats_app_campaigns')
            ->where('id', $campaignId)
            ->first([
                'id',
                'name',
                'template_id',
                'status',
                'audience_source',
                'test_mode',
                'scheduled_at',
                'started_at',
                'completed_at',
                'paused_at',
                'cancelled_at',
            ]);

        if ($row === null) {
            return ['campaign_id' => $campaignId, 'missing' => true];
        }

        return (array) $row + [
            'recipient_total' => DB::table('whats_app_campaign_recipients')
                ->where('campaign_id', $campaignId)
                ->count(),
            'recipient_eligible' => DB::table('whats_app_campaign_recipients')
                ->where('campaign_id', $campaignId)
                ->where('eligibility_status', 'eligible')
                ->count(),
            'recipient_suppressed' => DB::table('whats_app_campaign_recipients')
                ->where('campaign_id', $campaignId)
                ->where('eligibility_status', 'suppressed')
                ->count(),
        ];
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission) === true, 403);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }

    /** @return array<string,mixed> */
    private function campaignInput(Request $request, bool $withCampaign): array
    {
        $rules = [
            'segment' => ['nullable', 'array'],
            'search' => ['nullable', 'string', 'max:120'],
            'customer_ids' => ['nullable', 'array', 'max:10000'],
            'customer_ids.*' => ['integer', 'min:1'],
            'store_ids' => ['nullable', 'array', 'max:500'],
            'store_ids.*' => ['integer', 'min:1'],
            'channels' => ['nullable', 'array', 'max:2'],
            'channels.*' => [Rule::in(['b2b', 'b2c'])],
            'agent_ids' => ['nullable', 'array', 'max:500'],
            'agent_ids.*' => ['integer', 'min:1'],
            'tag_ids' => ['nullable', 'array', 'max:500'],
            'tag_ids.*' => ['integer', 'min:1'],
            'category_ids' => ['nullable', 'array', 'max:500'],
            'category_ids.*' => ['integer', 'min:1'],
            'min_value' => ['nullable', 'numeric', 'min:0'],
            'max_value' => ['nullable', 'numeric', 'min:0'],
            'recency_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'test_mode' => ['sometimes', 'boolean'],
            'test_allowlist' => ['nullable'],
            'max_recipients' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];

        if (! $withCampaign) {
            $rules += [
                'template_id' => ['nullable', 'integer', 'min:1'],
                'batch_size' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            ];
        }

        if ($withCampaign) {
            $rules += [
                'name' => ['required', 'string', 'max:160'],
                'template_id' => ['required', 'integer', 'min:1'],
                'audience_source' => ['required', Rule::in(['existing', 'import', 'manual'])],
                'import_batch_id' => ['nullable', 'integer', 'min:1'],
                'batch_size' => ['sometimes', 'integer', 'min:1', 'max:1000'],
                'per_minute_limit' => ['sometimes', 'integer', 'min:1', 'max:5000'],
                'launch_action' => ['nullable', Rule::in(['draft', 'send_now', 'schedule'])],
                'scheduled_at' => ['nullable', 'date'],
            ];
        }

        return $request->validate($rules);
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function segmentInput(array $data): array
    {
        $segment = is_array($data['segment'] ?? null) ? $data['segment'] : [];
        foreach ([
            'search',
            'customer_ids',
            'store_ids',
            'channels',
            'agent_ids',
            'tag_ids',
            'category_ids',
            'min_value',
            'max_value',
            'recency_days',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $segment[$key] = $data[$key];
            }
        }

        return $segment;
    }

    /** @return list<string> */
    private function allowlist(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : preg_split('/[\r\n,;]+/', (string) $value);
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $phone): string => trim((string) $phone),
            $items,
        ), static fn (string $phone): bool => $phone !== '')));
    }

    /** @return list<array{name:string,phone:string,email:?string}> */
    private function manualRows(string $value): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = str_getcsv($line);
            $rows[] = [
                'name' => trim((string) ($parts[0] ?? '')),
                'phone' => trim((string) ($parts[1] ?? '')),
                'email' => isset($parts[2]) ? trim((string) $parts[2]) : null,
            ];
        }

        return $rows;
    }

    /** @param array<string,mixed> $payload */
    private function mutationResponse(Request $request, array $payload, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($payload + ['ok' => true]);
        }

        return back()->with('status', $message);
    }

    private function validationFailure(Request $request, string $key, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'errors' => [$key => [$message]]], 422);
        }

        return back()->withErrors([$key => $message])->withInput();
    }
}
