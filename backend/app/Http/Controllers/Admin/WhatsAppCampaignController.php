<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\CampaignService;
use App\Domain\WhatsApp\MarketingConsentService;
use App\Domain\WhatsApp\TemplateRegistryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class WhatsAppCampaignController extends Controller
{
    public function index(Request $request, CampaignService $campaigns): View|JsonResponse
    {
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
            ->map(function (object $row) use ($campaigns): object {
                $row->metrics = $campaigns->metrics((int) $row->id);

                return $row;
            });

        $templates = DB::table('whats_app_templates')->orderByDesc('id')->limit(100)->get();

        if ($request->expectsJson()) {
            return response()->json(['campaigns' => $rows, 'templates' => $templates]);
        }

        return view('admin.whatsapp-campaigns.index', [
            'campaigns' => $rows,
            'templates' => $templates,
        ]);
    }

    public function preview(Request $request, CampaignService $campaigns): JsonResponse
    {
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $request->validate([
            'segment' => ['required', 'array'],
            'test_mode' => ['sometimes', 'boolean'],
            'test_allowlist' => ['sometimes', 'array'],
            'test_allowlist.*' => ['string', 'max:32'],
            'max_recipients' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json($campaigns->preview(
            $data['segment'],
            (bool) ($data['test_mode'] ?? true),
            array_values($data['test_allowlist'] ?? []),
            (int) ($data['max_recipients'] ?? 1000),
        ));
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

    public function storeConsent(Request $request, MarketingConsentService $consent): JsonResponse|RedirectResponse
    {
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $request->validate([
            'platform_customer_id' => ['required', 'integer', 'min:1'],
            'state' => ['required', 'string', 'max:24'],
            'source' => ['required', 'string', 'max:64'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $id = $consent->record(
            (int) $data['platform_customer_id'],
            (string) $data['state'],
            (string) $data['source'],
            $request->user()?->getKey(),
            $data['metadata'] ?? [],
        );

        return $this->mutationResponse($request, ['consent_id' => $id], 'Marketing consent updated.');
    }

    public function storeCampaign(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'template_id' => ['required', 'integer', 'min:1'],
            'segment' => ['required', 'array'],
            'test_mode' => ['sometimes', 'boolean'],
            'test_allowlist' => ['sometimes', 'array'],
            'test_allowlist.*' => ['string', 'max:32'],
            'batch_size' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_minute_limit' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'max_recipients' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);

        $now = now();
        $id = (int) DB::table('whats_app_campaigns')->insertGetId([
            'name' => trim($data['name']),
            'template_id' => (int) $data['template_id'],
            'status' => 'draft',
            'segment' => json_encode($data['segment'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'test_mode' => (bool) ($data['test_mode'] ?? true),
            'test_allowlist' => json_encode(array_values($data['test_allowlist'] ?? []), JSON_UNESCAPED_SLASHES),
            'batch_size' => (int) ($data['batch_size'] ?? 50),
            'per_minute_limit' => (int) ($data['per_minute_limit'] ?? 100),
            'max_recipients' => (int) ($data['max_recipients'] ?? 1000),
            'created_by' => $request->user()?->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->mutationResponse($request, ['campaign_id' => $id], 'Campaign created.');
    }

    public function snapshot(Request $request, int $campaign, CampaignService $campaigns): JsonResponse|RedirectResponse
    {
        $this->authorizePermission($request, 'whatsapp.campaigns.manage');
        $result = $campaigns->snapshot($campaign);

        return $this->mutationResponse($request, $result, 'Recipient snapshot rebuilt.');
    }

    public function schedule(Request $request, int $campaign, CampaignService $campaigns): JsonResponse|RedirectResponse
    {
        $this->authorizePermission($request, 'whatsapp.campaigns.send');
        $data = $request->validate(['scheduled_at' => ['nullable', 'date']]);
        $campaigns->schedule($campaign, $data['scheduled_at'] ?? null);

        return $this->mutationResponse($request, ['campaign_id' => $campaign], 'Campaign scheduled.');
    }

    public function pause(Request $request, int $campaign, CampaignService $campaigns): JsonResponse|RedirectResponse
    {
        $this->authorizePermission($request, 'whatsapp.campaigns.send');
        $campaigns->pause($campaign);

        return $this->mutationResponse($request, ['campaign_id' => $campaign], 'Campaign paused.');
    }

    public function cancel(Request $request, int $campaign, CampaignService $campaigns): JsonResponse|RedirectResponse
    {
        $this->authorizePermission($request, 'whatsapp.campaigns.send');
        $campaigns->cancel($campaign);

        return $this->mutationResponse($request, ['campaign_id' => $campaign], 'Campaign cancelled.');
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission) === true, 403);
    }

    /** @param array<string, mixed> $payload */
    private function mutationResponse(Request $request, array $payload, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($payload + ['ok' => true]);
        }

        return back()->with('status', $message);
    }
}
