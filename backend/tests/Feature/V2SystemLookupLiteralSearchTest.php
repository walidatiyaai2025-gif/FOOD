<?php

namespace Tests\Feature;

use App\Models\B2bPriceTier;
use App\Models\OperationalLookup;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalLookupService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2SystemLookupLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_operational_lookup_search_matches_literal_wildcards_with_type_status_and_locale_scope(): void
    {
        $literal = $this->lookup('v2_literal_percent', 'محفظة%_حرفية', 'V2 Special%_Wallet');
        $escape = $this->lookup('v2_literal_escape', 'محفظة!_حرفية', 'V2 Special!_Wallet');
        $lookalike = $this->lookup('v2_literal_lookalike', 'محفظة بديلة', 'V2 SpecialABWallet');
        $inactive = $this->lookup('v2_literal_inactive', 'محفظة موقوفة', 'V2 Special Other', false);
        $this->lookup('v2_literal_foreign', 'محفظة%_حرفية', 'V2 Special%_Wallet', true, OperationalLookupService::ORDER_STATUS);

        $actor = $this->actor('SUPER_ADMIN');
        $this->actingAs($actor);

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->get('/admin/operations/lookups?type=payment-methods&q='.rawurlencode('V2 Special%_'))
                ->assertOk()
                ->assertSee($literal->code)
                ->assertDontSee($lookalike->code)
                ->assertDontSee($escape->code);
        }

        $this->get('/admin/operations/lookups?type=payment-methods&q='.rawurlencode('محفظة!_'))
            ->assertOk()
            ->assertSee($escape->code)
            ->assertDontSee($literal->code);

        $this->get('/admin/operations/lookups?type=payment-methods&q='.rawurlencode('V2 Special__'))
            ->assertOk()
            ->assertDontSee($literal->code)
            ->assertDontSee($lookalike->code);

        $this->get('/admin/operations/lookups?type=payment-methods&q='.rawurlencode('V2 Special').'&status=inactive')
            ->assertOk()
            ->assertSee($inactive->code)
            ->assertDontSee($literal->code);

        $this->assertDatabaseHas('operational_lookups', [
            'id' => $literal->id,
            'label_en' => 'V2 Special%_Wallet',
        ]);
    }

    public function test_pricing_tier_search_is_literal_and_preserves_active_filter_and_codes(): void
    {
        $literal = $this->tier('V2LITERAL', 'V2Tier%_Gold', 'شريحة%_حرفية');
        $escape = $this->tier('V2ESCAPE', 'V2Tier!_Gold', 'شريحة!_حرفية');
        $lookalike = $this->tier('V2LOOKALIKE', 'V2TierABGold', 'شريحة بديلة');

        $this->actingAs($this->actor('SUPER_ADMIN'));

        $this->get('/admin/operations/lookups?type=pricing-tiers&q='.rawurlencode('V2Tier%_'))
            ->assertOk()
            ->assertSee($literal->code)
            ->assertDontSee($escape->code)
            ->assertDontSee($lookalike->code);

        $this->get('/admin/operations/lookups?type=pricing-tiers&q='.rawurlencode('شريحة!_'))
            ->assertOk()
            ->assertSee($escape->code)
            ->assertDontSee($literal->code);

        $this->get('/admin/operations/lookups?type=pricing-tiers&q='.rawurlencode('V2Tier__'))
            ->assertOk()
            ->assertDontSee($literal->code)
            ->assertDontSee($lookalike->code);

        $this->get('/admin/operations/lookups?type=pricing-tiers&q=V2Tier&status=inactive')
            ->assertOk()
            ->assertDontSee($literal->code)
            ->assertDontSee($escape->code);

        $this->assertDatabaseHas('b2b_price_tiers', [
            'id' => $literal->id,
            'name_en' => 'V2Tier%_Gold',
        ]);
    }

    public function test_roleless_actor_cannot_browse_system_lookups(): void
    {
        $actor = User::factory()->create(['is_active' => true]);

        $this->actingAs($actor)
            ->get('/admin/operations/lookups?type=payment-methods&q=%25')
            ->assertForbidden();
    }

    private function lookup(
        string $code,
        string $ar,
        string $en,
        bool $active = true,
        string $type = OperationalLookupService::PAYMENT_METHOD,
    ): OperationalLookup {
        return OperationalLookup::query()->create([
            'type' => $type,
            'code' => $code,
            'label_ar' => $ar,
            'label_en' => $en,
            'sort_order' => 2000,
            'is_active' => $active,
        ]);
    }

    private function tier(string $code, string $en, string $ar): B2bPriceTier
    {
        return B2bPriceTier::query()->create([
            'code' => $code,
            'name' => $en,
            'name_ar' => $ar,
            'name_en' => $en,
            'priority' => 1000,
            'is_active' => true,
        ]);
    }

    private function actor(string $role): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => strtolower($role).'-v2-system-lookups@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
