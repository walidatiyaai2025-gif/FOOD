<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantGrowthScaleService;
use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RestaurantGrowthController extends Controller
{
    public function index(
        Request $request,
        SmartOrderingSite $site,
        RestaurantGrowthScaleService $growth,
        TenantContextResolver $tenants,
    ): View {
        [$actor, $store] = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');
        $from = CarbonImmutable::now('Asia/Kuwait')->subDays(30)->startOfDay();
        $to = CarbonImmutable::now('Asia/Kuwait')->endOfDay();

        $organizationId = $this->organizationId($store);

        return view('admin.smart-ordering-growth', [
            'site' => $site,
            'user' => $actor,
            'store' => $store,
            'analytics' => $growth->analytics($store, $from, $to),
            'accounting' => $growth->accountingSummary($store, $from, $to),
            'giftCards' => DB::table('restaurant_saas_gift_cards')
                ->where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->limit(100)
                ->get(['public_id', 'display_code', 'currency', 'status', 'expires_at', 'created_at']),
            'journeys' => DB::table('restaurant_saas_lifecycle_journeys')
                ->where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->limit(100)
                ->get(['public_id', 'name', 'trigger_event', 'channel', 'consent_required', 'is_active']),
            'policies' => DB::table('restaurant_saas_franchise_policies')
                ->where('organization_id', $organizationId)
                ->orderBy('scope_key')
                ->orderBy('policy_key')
                ->limit(200)
                ->get(['scope_key', 'policy_key', 'policy_value', 'is_locked']),
            'exports' => DB::table('restaurant_saas_export_requests')
                ->where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->limit(50)
                ->get(['public_id', 'status', 'checksum_sha256', 'record_counts', 'completed_at']),
        ]);
    }

    public function issueGiftCard(
        Request $request,
        SmartOrderingSite $site,
        RestaurantGrowthScaleService $growth,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        [$actor, $store] = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'scope' => ['required', 'in:organization,brand,location'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $result = $growth->issueGiftCard(
            $store,
            $actor,
            (float) $data['amount'],
            (string) $data['currency'],
            (string) $data['idempotency_key'],
            (string) $data['scope'],
            isset($data['expires_at']) ? CarbonImmutable::parse((string) $data['expires_at']) : null,
        );

        return back()->with('status', __('restaurant_growth.gift_card_issued'))->with('restaurant_gift_card_code', $result['code']);
    }

    public function redeemGiftCard(
        Request $request,
        SmartOrderingSite $site,
        RestaurantGrowthScaleService $growth,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        [$actor, $store] = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'reference' => ['nullable', 'string', 'max:128'],
        ]);
        $result = $growth->redeemGiftCard(
            $store,
            $actor,
            (string) $data['code'],
            (float) $data['amount'],
            (string) $data['idempotency_key'],
            $data['reference'] ?? null,
        );

        return back()->with('status', __('restaurant_growth.gift_card_redeemed').' '.number_format($result['balance'], 3));
    }

    public function giftCardStatus(
        Request $request,
        SmartOrderingSite $site,
        string $giftCard,
        RestaurantGrowthScaleService $growth,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        [, $store] = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,closed'],
        ]);
        $growth->setGiftCardStatus($store, $giftCard, (string) $data['status']);

        return back()->with('status', __('restaurant_growth.gift_card_status_updated'));
    }

    public function journey(
        Request $request,
        SmartOrderingSite $site,
        RestaurantGrowthScaleService $growth,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        [$actor, $store] = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger_event' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'in:whatsapp,push,email,internal'],
            'segment_key' => ['nullable', 'string', 'max:120'],
            'scope' => ['required', 'in:organization,brand,location'],
            'consent_required' => ['nullable', 'boolean'],
        ]);
        $growth->createJourney(
            $store,
            $actor,
            (string) $data['name'],
            (string) $data['trigger_event'],
            (string) $data['channel'],
            (bool) ($data['consent_required'] ?? false),
            $data['segment_key'] ?? null,
            [],
            (string) $data['scope'],
        );

        return back()->with('status', __('restaurant_growth.journey_saved'));
    }

    public function policy(
        Request $request,
        SmartOrderingSite $site,
        RestaurantGrowthScaleService $growth,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        [$actor, $store] = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $data = $request->validate([
            'level' => ['required', 'in:organization,brand,location'],
            'policy_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'policy_value' => ['required', 'string', 'max:1000'],
            'is_locked' => ['nullable', 'boolean'],
        ]);
        $growth->setFranchisePolicy(
            $store,
            $actor,
            (string) $data['level'],
            (string) $data['policy_key'],
            (string) $data['policy_value'],
            (bool) ($data['is_locked'] ?? false),
        );

        return back()->with('status', __('restaurant_growth.policy_saved'));
    }

    public function export(
        Request $request,
        SmartOrderingSite $site,
        RestaurantGrowthScaleService $growth,
        TenantContextResolver $tenants,
    ): RedirectResponse {
        [$actor, $store] = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.manage');
        $result = $growth->exportOrganization($store, $actor);

        return back()->with('status', __('restaurant_growth.export_ready').' '.$result['checksum']);
    }

    public function accountingExport(
        Request $request,
        SmartOrderingSite $site,
        RestaurantGrowthScaleService $growth,
        TenantContextResolver $tenants,
    ): StreamedResponse {
        [, $store] = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $from = isset($data['from'])
            ? CarbonImmutable::parse((string) $data['from'], 'Asia/Kuwait')->startOfDay()
            : CarbonImmutable::now('Asia/Kuwait')->subDays(30)->startOfDay();
        $to = isset($data['to'])
            ? CarbonImmutable::parse((string) $data['to'], 'Asia/Kuwait')->endOfDay()
            : CarbonImmutable::now('Asia/Kuwait')->endOfDay();
        $summary = $growth->accountingSummary($store, $from, $to);

        return response()->streamDownload(function () use ($summary): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open accounting export stream.');
            }
            fputcsv($output, ['sales_date', 'store_id', 'currency', 'subtotal', 'discounts', 'delivery', 'net_sales', 'refunded_value']);
            foreach ($summary['rows'] as $row) {
                fputcsv($output, [
                    $row['sales_date'],
                    $row['store_id'],
                    $row['currency'],
                    $row['subtotal'],
                    $row['discounts'],
                    $row['delivery'],
                    $row['net_sales'],
                    $row['refunded_value'],
                ]);
            }
            fclose($output);
        }, 'foodex-accounting-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function download(
        Request $request,
        SmartOrderingSite $site,
        string $export,
        TenantContextResolver $tenants,
    ): StreamedResponse {
        [, $store] = $this->authorizeSite($request, $site, $tenants, 'smart_ordering.view');
        $row = DB::table('restaurant_saas_export_requests')
            ->where('organization_id', $this->organizationId($store))
            ->where('public_id', $export)
            ->where('status', 'completed')
            ->first(['file_path']);
        abort_unless($row !== null && $row->file_path !== null && Storage::disk('local')->exists($row->file_path), 404);

        return Storage::disk('local')->download((string) $row->file_path, 'foodex-restaurant-export-'.$export.'.json');
    }

    /** @return array{0:User,1:Store} */
    private function authorizeSite(
        Request $request,
        SmartOrderingSite $site,
        TenantContextResolver $tenants,
        string $permission,
    ): array {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        app(RestaurantSaasStoreAccess::class)->assertStore(
            $actor,
            (int) $site->store_id,
            $permission,
            'b2c',
        );
        $store = Store::query()->findOrFail($site->store_id);

        return [$actor, $store];
    }

    private function organizationId(Store $store): int
    {
        $id = DB::table('enterprise_store_scopes as ss')
            ->join('enterprise_business_units as bu', 'bu.id', '=', 'ss.business_unit_id')
            ->join('enterprise_legal_entities as le', 'le.id', '=', 'bu.legal_entity_id')
            ->join('enterprise_companies as c', 'c.id', '=', 'le.company_id')
            ->where('ss.store_id', $store->getKey())
            ->value('c.organization_id');
        abort_unless($id !== null, 409);

        return (int) $id;
    }
}
