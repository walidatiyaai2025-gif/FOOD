<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RestaurantTemplateGalleryPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'TEMPLATE-GALLERY',
            'name' => 'Template Gallery Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($storeId);

        DB::table('smart_ordering_sites')->insert([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'template-gallery',
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::query()->create([
            'name' => 'Template Gallery Admin',
            'email' => 'template-gallery@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $this->admin->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_gallery_exposes_all_six_production_templates_before_restaurant_content_is_selected(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/smart-ordering/appearance');

        $response->assertOk()
            ->assertSee('Production template gallery')
            ->assertSee('Demo mode:')
            ->assertSee('appearance/demo-preview', false);

        foreach ($this->themes() as $theme) {
            $response->assertSee('data-theme="'.$theme.'"', false);
        }
    }

    public function test_demo_preview_uses_real_renderer_for_every_template_in_arabic_and_english(): void
    {
        $this->actingAs($this->admin);

        foreach ($this->themes() as $theme) {
            foreach (['en', 'ar'] as $locale) {
                $response = $this->get('/admin/smart-ordering/appearance/demo-preview?theme='.$theme.'&lang='.$locale);

                $response->assertOk()
                    ->assertHeader('Cache-Control', 'no-store, private')
                    ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                    ->assertHeader('X-FOODEX-Preview-State', 'demo')
                    ->assertSee('data-template="'.$theme.'"', false)
                    ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                    ->assertSee($locale === 'ar' ? 'زعفران وستون' : 'Saffron &amp; Stone', false);
            }
        }
    }

    public function test_demo_preview_rejects_non_production_theme_ids(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/smart-ordering/appearance/demo-preview?theme=quick-bite&lang=en')
            ->assertNotFound();
    }

    /** @return list<string> */
    private function themes(): array
    {
        return [
            'fine-dining-elegant-light',
            'fine-dining-dark-luxury',
            'contemporary-bistro',
            'premium-arabic-dining',
            'modern-minimal',
            'chef-tasting',
        ];
    }
}
