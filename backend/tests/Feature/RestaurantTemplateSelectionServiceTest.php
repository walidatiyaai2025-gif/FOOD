<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Domain\SmartOrdering\Themes\RestaurantTemplateSelectionService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RestaurantTemplateSelectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_selection_updates_draft_only_and_preserves_other_appearance_settings(): void
    {
        [$admin, $site] = $this->context();
        $revisions = app(SmartOrderingRevisionService::class);
        $published = $revisions->ensurePublished($site, $admin);
        $draft = $revisions->createOrReuseDraft($admin, $site);

        $payload = (array) $draft->payload;
        $payload['appearance'] = [
            'mode' => 'dark',
            'customization' => ['primary_color' => '#123456'],
        ];
        $draft = $revisions->updateDraft($admin, $draft, $payload);

        $selected = app(RestaurantTemplateSelectionService::class)
            ->selectDraft($admin, $site, 'premium-arabic-dining');

        $this->assertSame('draft', $selected->status);
        $this->assertSame('premium-arabic-dining', data_get($selected->payload, 'appearance.template'));
        $this->assertSame(1, data_get($selected->payload, 'appearance.template_version'));
        $this->assertSame('dark', data_get($selected->payload, 'appearance.mode'));
        $this->assertSame('#123456', data_get($selected->payload, 'appearance.customization.primary_color'));

        $published->refresh();
        $this->assertNull(data_get($published->payload, 'appearance.template'));
        $this->assertNull(data_get($published->payload, 'appearance.template_version'));
    }

    public function test_legacy_or_unknown_template_cannot_be_selected_as_production_template(): void
    {
        [$admin, $site] = $this->context();

        $this->expectException(ValidationException::class);

        app(RestaurantTemplateSelectionService::class)
            ->selectDraft($admin, $site, 'quick-bite');
    }

    /** @return array{0:User,1:\App\Domain\SmartOrdering\Models\SmartOrderingSite} */
    private function context(): array
    {
        $this->seed(CoreReferenceSeeder::class);
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'TEMPLATE-SELECTION',
            'name' => 'Template Selection Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($storeId);

        $admin = User::query()->create([
            'name' => 'Template Selection Admin',
            'email' => 'template-selection@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);

        return [$admin, $site];
    }
}
