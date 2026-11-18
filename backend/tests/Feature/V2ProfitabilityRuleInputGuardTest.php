<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #2559 P229 guard: a customer/quota rule cannot impersonate an unapproved
 * profitability policy. This does NOT implement or approve a margin floor.
 */
final class V2ProfitabilityRuleInputGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_nested_minimum_margin_is_rejected_in_both_locales_without_sales_policy_or_audit_writes(): void
    {
        [$actor, $storeId, $productId] = $this->scope();

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())
                ->from(route('admin.commercial.sales-control', ['store_id' => $storeId, 'product' => $productId]))
                ->put(route('admin.commercial.sales-control.save', [
                    'store_id' => $storeId,
                    'product' => $productId,
                ]), $this->validForm([
                    'rules_json' => '[{"channel":"van","min_margin_pct":12.5,"minMarginPct":12.5}]',
                ]));

            $response->assertRedirect()
                ->assertSessionHasErrors(['rules_json.0.min_margin_pct', 'rules_json.0.minMarginPct']);
            $this->assertSame(
                __('commercial.validation.profitability_policy_unavailable', [], $locale),
                session('errors')->first('rules_json.0.min_margin_pct'),
            );

            $this->assertSame(0, DB::table('product_commercial_policies')->where('product_id', $productId)->count());
            $this->assertSame(0, DB::table('product_commercial_rules')->where('product_id', $productId)->count());
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'commercial.product_policy.updated')->count());
        }
    }

    public function test_top_level_profitability_policy_payload_cannot_claim_success_or_change_catalog(): void
    {
        [$actor, $storeId, $productId] = $this->scope();

        $this->actingAs($actor)
            ->from(route('admin.commercial.sales-control', ['store_id' => $storeId, 'product' => $productId]))
            ->put(route('admin.commercial.sales-control.save', [
                'store_id' => $storeId,
                'product' => $productId,
            ]), $this->validForm([
                'minimum_margin_percent' => '15',
                'minimumMarginPercent' => '15',
                'profitability_rules' => '[{"floor":15}]',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['minimum_margin_percent', 'minimumMarginPercent', 'profitability_rules']);

        $this->assertSame(0, DB::table('product_commercial_policies')->where('product_id', $productId)->count());
        $this->assertSame(0, DB::table('product_commercial_rules')->where('product_id', $productId)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'commercial.product_policy.updated')->count());
    }

    public function test_deeply_nested_unsupported_profitability_keys_cannot_be_silently_saved_by_any_builder(): void
    {
        [$actor, $storeId, $productId] = $this->scope();
        $route = route('admin.commercial.sales-control.save', [
            'store_id' => $storeId,
            'product' => $productId,
        ]);

        $cases = [
            ['channels_json', '[{"extensions":{"minMarginPct":18}}]', 'channels_json.0.extensions.minMarginPct'],
            [
                'selling_units_json',
                '[{"code":"PIECE","name":"Piece","conversion_factor":1,"is_base":true,"is_active":true,"attributes":[{"stock_owner":"unverified"}]}]',
                'selling_units_json.0.attributes.0.stock_owner',
            ],
            [
                'availability_windows_json',
                '[{"recurrence":"fixed","metadata":{"valuation":{"costBasis":"client-supplied"}}}]',
                'availability_windows_json.0.metadata.valuation.costBasis',
            ],
            [
                'rules_json',
                '[{"channel":"van","constraints":{"nested":{"minimum_profit":"15"}}}]',
                'rules_json.0.constraints.nested.minimum_profit',
            ],
        ];

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            foreach ($cases as [$field, $json, $errorKey]) {
                $response = $this->actingAs($actor->fresh())
                    ->from(route('admin.commercial.sales-control', ['store_id' => $storeId, 'product' => $productId]))
                    ->put($route, $this->validForm([$field => $json]));

                $response->assertRedirect()->assertSessionHasErrors([$errorKey]);
                $this->assertSame(
                    __('commercial.validation.profitability_policy_unavailable', [], $locale),
                    session('errors')->first($errorKey),
                );
                $this->assertSame(0, DB::table('product_commercial_policies')->where('product_id', $productId)->count());
                $this->assertSame(0, DB::table('product_commercial_rules')->where('product_id', $productId)->count());
                $this->assertSame(0, DB::table('audit_logs')->where('event', 'commercial.product_policy.updated')->count());
            }
        }
    }

    public function test_valid_non_profitability_targeting_configuration_retains_existing_save_behavior(): void
    {
        [$actor, $storeId, $productId] = $this->scope();

        $this->actingAs($actor)
            ->put(route('admin.commercial.sales-control.save', [
                'store_id' => $storeId,
                'product' => $productId,
            ]), $this->validForm([
                'rules_json' => '[{"channel":"van","is_allowed":true}]',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_commercial_policies', [
            'product_id' => $productId,
            'status' => 'RESTRICTED',
        ]);
        $this->assertDatabaseHas('product_commercial_rules', [
            'product_id' => $productId,
            'channel' => 'van',
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.product_policy.updated')->count());
    }

    /** @return array{User,int,int} */
    private function scope(): array
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P229-GUARD-WHOLESALE',
            'name' => 'P229 B2B Control',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        /** @var User $actor */
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'P229-EACH',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'p229-guard',
            'name' => 'P229 Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Guard',
            'slug' => 'p229-guard',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'P229-SKU',
            'name' => 'Guarded Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $storeId, $productId];
    }

    /** @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function validForm(array $extra = []): array
    {
        return [
            'status' => 'RESTRICTED',
            'channels_json' => '["customer","van"]',
            'break_pack_policy' => 'mixed',
            'business_timezone' => 'Asia/Kuwait',
            'week_starts_on' => 1,
            'selling_units_json' => '[{"code":"PIECE","name":"Piece","conversion_factor":1,"is_base":true,"is_active":true}]',
            'availability_windows_json' => '[]',
            'rules_json' => '[]',
            ...$extra,
        ];
    }
}
