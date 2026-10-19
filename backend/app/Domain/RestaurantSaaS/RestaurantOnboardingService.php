<?php

namespace App\Domain\RestaurantSaaS;

use App\Domain\RestaurantSaaS\Models\RestaurantLocationProfile;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingPriceResolver;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RestaurantOnboardingService
{
    public function __construct(
        private readonly RestaurantControlPlaneService $controlPlane,
        private readonly SmartOrderingRevisionService $revisions,
        private readonly SmartOrderingPriceResolver $prices,
        private readonly SmartOrderingSiteService $sites,
    ) {}

    /** @return array<string, mixed> */
    public function status(Store $store, User $actor): array
    {
        $site = SmartOrderingSite::query()
            ->where('store_id', $store->getKey())
            ->first();

        $context = $this->controlPlane->context($store);
        $profile = RestaurantLocationProfile::query()
            ->where('store_id', $store->getKey())
            ->first();

        if (! $site instanceof SmartOrderingSite) {
            return $this->result([
                $this->gate('control_plane', true, true, false),
            ]);
        }

        $published = $this->revisions->published($site);
        $brandingRevision = $this->revisions->draft($site) ?? $published;
        $brandingPayload = $brandingRevision === null ? [] : (array) $brandingRevision->payload;
        $brandingContent = is_array($brandingPayload['content'] ?? null) ? $brandingPayload['content'] : [];
        $storefrontIdentity = is_array($brandingContent['identity'] ?? null) ? $brandingContent['identity'] : [];
        $heroImageReady = trim((string) ($storefrontIdentity['cover_url'] ?? '')) !== '';

        $branches = SmartOrderingBranch::query()
            ->where('site_id', $site->getKey())
            ->where('is_active', true)
            ->get();

        $entitlements = DB::table('smart_ordering_entitlements')
            ->where('site_id', $site->getKey())
            ->where('scope_key', 'site')
            ->pluck('enabled', 'module')
            ->map(static fn ($enabled): bool => (bool) $enabled)
            ->all();

        $items = $published === null
            ? collect()
            : SmartOrderingMenuItem::query()
                ->select('smart_ordering_menu_items.*')
                ->join('smart_ordering_menu_categories', 'smart_ordering_menu_categories.id', '=', 'smart_ordering_menu_items.category_id')
                ->join('smart_ordering_menus', 'smart_ordering_menus.id', '=', 'smart_ordering_menu_categories.menu_id')
                ->where('smart_ordering_menu_items.site_id', $site->getKey())
                ->where('smart_ordering_menu_items.revision_id', $published->getKey())
                ->where('smart_ordering_menu_items.is_visible', true)
                ->where('smart_ordering_menu_categories.is_visible', true)
                ->where('smart_ordering_menus.is_visible', true)
                ->get();

        $menuPublished = $items->isNotEmpty();
        $priceReady = false;
        if ($menuPublished) {
            $resolved = $this->prices->displayPrices($site, $items);
            $priceReady = $resolved['errors'] === [] && count($resolved['prices']) === $items->count();
        }

        $languageComplete = $published !== null
            && $menuPublished
            && ! $this->hasMissingBilingualMenuText((int) $site->getKey(), (int) $published->getKey());

        $openingHoursReady = $this->openingHoursReady($branches);
        $fulfillmentReady = $this->fulfillmentReady($branches, $entitlements);
        $deliveryRequired = $branches->contains(
            static fn (SmartOrderingBranch $branch): bool => (bool) $branch->supports_delivery
                && (bool) ($entitlements['delivery'] ?? false),
        );
        $deliveryReady = ! $deliveryRequired || $this->deliveryRulesReady($branches);
        $qrRequired = (bool) ($entitlements['dine_in'] ?? false)
            || (bool) ($entitlements['campaign_qr'] ?? false);
        $qrReady = ! $qrRequired || $this->qrReady($store, $branches);
        $contactRequired = (bool) ($entitlements['whatsapp'] ?? false);
        $contactReady = ! $contactRequired || $branches->contains(
            static fn (SmartOrderingBranch $branch): bool => trim((string) $branch->whatsapp_routing_identity) !== '',
        );

        $profileReady = $profile instanceof RestaurantLocationProfile
            && trim((string) $profile->business_name) !== ''
            && trim((string) $profile->timezone) !== ''
            && trim((string) $profile->currency) !== ''
            && trim((string) $profile->country_code) !== '';

        $legalReady = $profile instanceof RestaurantLocationProfile
            && trim((string) $profile->legal_name) !== '';

        $operatorReady = $actor->hasPermission('smart_ordering.manage', (int) $store->getKey())
            && $actor->hasPermission('smart_ordering.publish', (int) $store->getKey());

        $publicMenuEntitled = (bool) ($entitlements['public_menu'] ?? false);
        $salesChannelsSelected = (bool) ($entitlements['dine_in'] ?? false)
            || (bool) ($entitlements['pickup'] ?? false)
            || (bool) ($entitlements['delivery'] ?? false);
        $previewReady = $menuPublished && $languageComplete && $priceReady;
        $healthReady = $site->runtime_state !== 'EMERGENCY_STOP';

        $gates = [
            $this->gate('control_plane', true, true, (bool) ($context['provisioned'] ?? false)),
            $this->gate('location_identity', true, true, $profileReady),
            $this->gate('hero_image', true, true, $heroImageReady),
            $this->gate('opening_hours', true, true, $openingHoursReady),
            $this->gate('public_menu_entitlement', true, true, $publicMenuEntitled),
            $this->gate('sales_channels', true, true, $salesChannelsSelected),
            $this->gate('menu_published', true, true, $menuPublished),
            $this->gate('prices', true, true, $priceReady),
            $this->gate('fulfillment', true, true, $fulfillmentReady),
            $this->gate('language', true, true, $languageComplete),
            $this->gate('delivery_rules', true, $deliveryRequired, $deliveryReady),
            $this->gate('qr', true, $qrRequired, $qrReady),
            $this->gate('operator', true, true, $operatorReady),
            $this->gate('legal_contact', false, true, $legalReady),
            $this->gate('contact_channel', true, $contactRequired, $contactReady),
            $this->gate('payment', false, false, true),
            $this->gate('launch_preview', false, true, $previewReady),
            $this->gate('health', true, true, $healthReady),
        ];

        $result = $this->result($gates);
        // Lifecycle marks completion of the guided launch. Runtime may later be
        // paused for maintenance without sending an already-launched restaurant
        // back through onboarding.
        $launched = $profile instanceof RestaurantLocationProfile
            && (string) $profile->lifecycle_state === 'ACTIVE';
        $setupFlow = $this->setupFlow($gates, $launched);

        return $result + [
            'setup_flow' => $setupFlow['steps'],
            'current_step' => $setupFlow['current_step'],
            'launched' => $launched,
            'profile' => $profile,
            'site' => $site,
            'context' => $context,
            'entitlements' => $entitlements,
            'storefront_identity' => $storefrontIdentity,
            'imports' => $this->recentImports((int) $store->getKey()),
        ];
    }

    /** @return array<string, mixed> */
    public function launch(Store $store, User $actor, ?Request $request = null): array
    {
        $status = $this->status($store, $actor);
        if (! ($status['can_launch'] ?? false)) {
            throw ValidationException::withMessages([
                'launch' => [__('restaurant_onboarding.validation.launch_blocked')],
            ]);
        }

        $site = $status['site'] ?? null;
        if (! $site instanceof SmartOrderingSite) {
            throw ValidationException::withMessages([
                'launch' => [__('restaurant_onboarding.validation.launch_blocked')],
            ]);
        }

        DB::transaction(function () use ($store, $actor, $site, $request): void {
            $this->controlPlane->updateLocationProfile(
                $store,
                $actor,
                ['lifecycle_state' => 'ACTIVE'],
                $request,
            );
            $this->sites->updateRuntime($actor, $site, 'ENABLED', true, $request);
        });

        return $this->status($store->refresh(), $actor);
    }

    /** @return list<object> */
    public function recentImports(int $storeId): array
    {
        return DB::table('restaurant_onboarding_imports')
            ->where('store_id', $storeId)
            ->latest('id')
            ->limit(5)
            ->get([
                'public_id',
                'format',
                'original_filename',
                'status',
                'rows_total',
                'formula_rows',
                'headers',
                'sample',
                'created_at',
            ])
            ->map(function ($row): object {
                $row->headers = json_decode((string) $row->headers, true) ?: [];
                $row->sample = json_decode((string) $row->sample, true) ?: [];

                return $row;
            })
            ->all();
    }

    /** @param array<string, bool> $entitlements */
    private function fulfillmentReady(Collection $branches, array $entitlements): bool
    {
        foreach ($branches as $branch) {
            if (! $branch instanceof SmartOrderingBranch || ! $branch->accepting_orders) {
                continue;
            }

            if ($branch->supports_dine_in && ($entitlements['dine_in'] ?? false)) {
                return true;
            }
            if ($branch->supports_pickup && ($entitlements['pickup'] ?? false)) {
                return true;
            }
            if ($branch->supports_delivery && ($entitlements['delivery'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function openingHoursReady(Collection $branches): bool
    {
        if ($branches->isEmpty()) {
            return false;
        }

        $branchIds = $branches->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $configured = DB::table('smart_ordering_branch_hours')
            ->whereIn('branch_id', $branchIds)
            ->where('is_closed', false)
            ->whereNotNull('opens_at')
            ->whereNotNull('closes_at')
            ->distinct()
            ->pluck('branch_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return count(array_unique($configured)) === count(array_unique($branchIds));
    }

    private function deliveryRulesReady(Collection $branches): bool
    {
        $deliveryBranchIds = $branches
            ->filter(static fn (SmartOrderingBranch $branch): bool => (bool) $branch->supports_delivery)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($deliveryBranchIds === []) {
            return true;
        }

        $withZones = DB::table('smart_ordering_delivery_zones')
            ->whereIn('branch_id', $deliveryBranchIds)
            ->where('is_active', true)
            ->distinct()
            ->pluck('branch_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return count(array_unique($withZones)) === count(array_unique($deliveryBranchIds));
    }

    private function qrReady(Store $store, Collection $branches): bool
    {
        if (DB::table('smart_ordering_qr_codes')
            ->where('store_id', $store->getKey())
            ->where('is_enabled', true)
            ->exists()) {
            return true;
        }

        $dineInBranchIds = $branches
            ->filter(static fn (SmartOrderingBranch $branch): bool => (bool) $branch->supports_dine_in)
            ->pluck('id')
            ->all();

        return $dineInBranchIds !== []
            && DB::table('smart_ordering_tables')
                ->whereIn('branch_id', $dineInBranchIds)
                ->where('is_active', true)
                ->exists();
    }

    private function hasMissingBilingualMenuText(int $siteId, int $revisionId): bool
    {
        foreach (['smart_ordering_menus', 'smart_ordering_menu_categories', 'smart_ordering_menu_items'] as $table) {
            $missing = DB::table($table)
                ->where('site_id', $siteId)
                ->where('revision_id', $revisionId)
                ->where('is_visible', true)
                ->where(function ($query): void {
                    $query->whereNull('name_ar')
                        ->orWhere('name_ar', '')
                        ->orWhereNull('name_en')
                        ->orWhere('name_en', '');
                })
                ->exists();

            if ($missing) {
                return true;
            }
        }

        return false;
    }

    /** @return array{key:string,hard:bool,required:bool,ready:bool,state:string} */
    private function gate(string $key, bool $hard, bool $required, bool $ready): array
    {
        return [
            'key' => $key,
            'hard' => $hard,
            'required' => $required,
            'ready' => $ready,
            'state' => ! $required ? 'not_applicable' : ($ready ? 'ready' : ($hard ? 'blocked' : 'attention')),
        ];
    }

    /**
     * Build a strict progressive setup path. A step becomes available only when
     * every required hard gate in all previous steps is ready.
     *
     * @param list<array{key:string,hard:bool,required:bool,ready:bool,state:string}> $gates
     * @return array{steps:list<array{key:string,number:int,available:bool,complete:bool,state:string,gates:list<string>}>,current_step:string}
     */
    private function setupFlow(array $gates, bool $launched): array
    {
        $gateMap = collect($gates)->keyBy('key');
        $definitions = [
            ['key' => 'identity', 'gates' => ['control_plane', 'location_identity', 'hero_image', 'operator']],
            ['key' => 'channels', 'gates' => ['public_menu_entitlement', 'sales_channels']],
            ['key' => 'operations', 'gates' => ['opening_hours', 'fulfillment', 'delivery_rules']],
            ['key' => 'menu', 'gates' => ['menu_published', 'prices', 'language']],
            ['key' => 'entry', 'gates' => ['qr']],
            ['key' => 'review', 'gates' => ['legal_contact', 'contact_channel', 'payment', 'launch_preview', 'health']],
        ];

        $steps = [];
        $previousComplete = true;
        $currentStep = $launched ? 'complete' : 'review';

        foreach ($definitions as $index => $definition) {
            $isReview = $definition['key'] === 'review';
            $hardRequired = collect($definition['gates'])
                ->map(fn (string $key) => $gateMap->get($key))
                ->filter(fn ($gate) => is_array($gate) && ($gate['hard'] ?? false) && ($gate['required'] ?? false));

            $complete = $isReview
                ? $launched
                : $hardRequired->every(fn (array $gate): bool => (bool) ($gate['ready'] ?? false));
            $available = $launched || $previousComplete;
            $state = $launched || $complete
                ? 'complete'
                : ($available ? 'current' : 'locked');

            if (! $launched && $state === 'current' && $currentStep === 'review') {
                $currentStep = $definition['key'];
            }

            $steps[] = [
                'key' => $definition['key'],
                'number' => $index + 1,
                'available' => $available,
                'complete' => $complete,
                'state' => $state,
                'gates' => $definition['gates'],
            ];

            if (! $complete) {
                $previousComplete = false;
            }
        }

        return ['steps' => $steps, 'current_step' => $currentStep];
    }

    /** @param list<array{key:string,hard:bool,required:bool,ready:bool,state:string}> $gates
     * @return array<string, mixed>
     */
    private function result(array $gates): array
    {
        $required = array_values(array_filter($gates, static fn (array $gate): bool => $gate['required']));
        $ready = array_values(array_filter($required, static fn (array $gate): bool => $gate['ready']));
        $hardBlockers = array_values(array_filter(
            $gates,
            static fn (array $gate): bool => $gate['hard'] && $gate['required'] && ! $gate['ready'],
        ));

        return [
            'gates' => $gates,
            'completion_percent' => $required === [] ? 0 : (int) round((count($ready) / count($required)) * 100),
            'hard_blockers' => $hardBlockers,
            'can_launch' => $hardBlockers === [],
        ];
    }
}
