<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseOrganizationAdministration;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class EnterpriseOrganizationAdministrationController extends Controller
{
    public function __construct(
        private readonly EnterpriseOrganizationAdministration $administration,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->platformAdmin($request);

        return $this->page($actor, 'organizations', [
            'organizations' => EnterpriseOrganization::query()
                ->withCount('companies')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(25),
        ]);
    }

    public function showOrganization(Request $request, EnterpriseOrganization $organization): View
    {
        $actor = $this->platformAdmin($request);

        return $this->page($actor, 'organization', [
            'record' => $organization,
            'items' => $organization->companies()
                ->withCount('legalEntities')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(25),
        ]);
    }

    public function showCompany(Request $request, EnterpriseCompany $company): View
    {
        $actor = $this->platformAdmin($request);
        $company->loadMissing('organization');

        return $this->page($actor, 'company', [
            'record' => $company,
            'parent' => $company->organization,
            'items' => $company->legalEntities()
                ->withCount('businessUnits')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(25),
        ]);
    }

    public function showLegalEntity(Request $request, EnterpriseLegalEntity $legalEntity): View
    {
        $actor = $this->platformAdmin($request);
        $legalEntity->loadMissing('company.organization');

        return $this->page($actor, 'legal_entity', [
            'record' => $legalEntity,
            'parent' => $legalEntity->company,
            'organization' => $legalEntity->company->organization,
            'items' => $legalEntity->businessUnits()
                ->withCount('storeScopes')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(25),
        ]);
    }

    public function showBusinessUnit(Request $request, EnterpriseBusinessUnit $businessUnit): View
    {
        $actor = $this->platformAdmin($request);
        $businessUnit->loadMissing('legalEntity.company.organization');
        $organizationId = (int) $businessUnit->legalEntity->company->organization_id;

        $stores = DB::table('stores')
            ->join('enterprise_store_scopes as scopes', 'scopes.store_id', '=', 'stores.id')
            ->join('enterprise_business_units as units', 'units.id', '=', 'scopes.business_unit_id')
            ->join('enterprise_legal_entities as entities', 'entities.id', '=', 'units.legal_entity_id')
            ->join('enterprise_companies as companies', 'companies.id', '=', 'entities.company_id')
            ->where('companies.organization_id', $organizationId)
            ->select([
                'stores.id',
                'stores.name',
                'stores.code',
                'stores.is_active',
                'units.name as business_unit_name',
                'scopes.business_unit_id',
            ])
            ->orderBy('stores.name')
            ->orderBy('stores.id')
            ->paginate(50);

        return $this->page($actor, 'business_unit', [
            'record' => $businessUnit,
            'parent' => $businessUnit->legalEntity,
            'company' => $businessUnit->legalEntity->company,
            'organization' => $businessUnit->legalEntity->company->organization,
            'stores' => $stores,
        ]);
    }

    public function storeOrganization(Request $request): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $data = $this->validatedRecord($request, Rule::unique('enterprise_organizations', 'code'));
        $organization = $this->administration->createOrganization($data, $actor);

        return redirect()->route('admin.enterprise-organizations.show', $organization)->with('status', __('enterprise_organization.saved'));
    }

    public function updateOrganization(Request $request, EnterpriseOrganization $organization): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $data = $this->validatedRecord($request, Rule::unique('enterprise_organizations', 'code')->ignore($organization->id));
        $this->administration->updateOrganization($organization, $data, $actor);

        return back()->with('status', __('enterprise_organization.saved'));
    }

    public function storeCompany(Request $request, EnterpriseOrganization $organization): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $data = $this->validatedRecord(
            $request,
            Rule::unique('enterprise_companies', 'code')->where('organization_id', $organization->id),
        );
        $company = $this->administration->createCompany($organization, $data, $actor);

        return redirect()->route('admin.enterprise-companies.show', $company)->with('status', __('enterprise_organization.saved'));
    }

    public function updateCompany(Request $request, EnterpriseCompany $company): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $data = $this->validatedRecord(
            $request,
            Rule::unique('enterprise_companies', 'code')->where('organization_id', $company->organization_id)->ignore($company->id),
        );
        $this->administration->updateCompany($company, $data, $actor);

        return back()->with('status', __('enterprise_organization.saved'));
    }

    public function storeLegalEntity(Request $request, EnterpriseCompany $company): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $data = $this->validatedRecord(
            $request,
            Rule::unique('enterprise_legal_entities', 'code')->where('company_id', $company->id),
        );
        $entity = $this->administration->createLegalEntity($company, $data, $actor);

        return redirect()->route('admin.enterprise-legal-entities.show', $entity)->with('status', __('enterprise_organization.saved'));
    }

    public function updateLegalEntity(Request $request, EnterpriseLegalEntity $legalEntity): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $data = $this->validatedRecord(
            $request,
            Rule::unique('enterprise_legal_entities', 'code')->where('company_id', $legalEntity->company_id)->ignore($legalEntity->id),
        );
        $this->administration->updateLegalEntity($legalEntity, $data, $actor);

        return back()->with('status', __('enterprise_organization.saved'));
    }

    public function storeBusinessUnit(Request $request, EnterpriseLegalEntity $legalEntity): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $data = $this->validatedRecord(
            $request,
            Rule::unique('enterprise_business_units', 'code')->where('legal_entity_id', $legalEntity->id),
        );
        $unit = $this->administration->createBusinessUnit($legalEntity, $data, $actor);

        return redirect()->route('admin.enterprise-business-units.show', $unit)->with('status', __('enterprise_organization.saved'));
    }

    public function updateBusinessUnit(Request $request, EnterpriseBusinessUnit $businessUnit): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $data = $this->validatedRecord(
            $request,
            Rule::unique('enterprise_business_units', 'code')->where('legal_entity_id', $businessUnit->legal_entity_id)->ignore($businessUnit->id),
        );
        $this->administration->updateBusinessUnit($businessUnit, $data, $actor);

        return back()->with('status', __('enterprise_organization.saved'));
    }

    public function assignStore(Request $request, EnterpriseBusinessUnit $businessUnit, Store $store): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $this->administration->assignStore($store, $businessUnit, $actor);

        return back()->with('status', __('enterprise_organization.store_assigned'));
    }

    private function validatedRecord(Request $request, Rule $unique): array
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/', $unique],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'code' => strtolower((string) $validated['code']),
            'name' => trim((string) $validated['name']),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function platformAdmin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('platform.manage');

        return $actor;
    }

    private function page(User $actor, string $screen, array $data): View
    {
        return view('admin.enterprise-organization', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'screen' => $screen,
            ...$data,
        ]);
    }
}
