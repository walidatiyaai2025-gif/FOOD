<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarUtilityToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_sidebar_has_five_real_tools_with_audited_locale_form_in_both_languages(): void
    {
        $user = $this->globalUser('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $html = (string) $this->actingAs($user->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();

            $this->assertSame(1, substr_count($html, 'data-foodex-sidebar-utilities'));
            $this->assertStringContainsString('aria-label="'.__('sidebar_utilities.tools').'"', $html);
            foreach (['favorites','history','help','profile'] as $tool) {
                $this->assertSame(1, substr_count($html, 'data-foodex-utility-'.$tool));
                $this->assertStringContainsString(__('sidebar_utilities.'.$tool), $html);
            }
            $this->assertStringContainsString('href="'.route('public.support').'"', $html);
            $this->assertStringContainsString('href="'.route('admin.profile.index').'"', $html);
            $this->assertStringContainsString('action="'.route('admin.profile.locale').'"', $html);
            $this->assertStringContainsString('name="locale" value="'.($locale === 'ar' ? 'en' : 'ar').'"', $html);
            $this->assertStringContainsString('name="_method" value="PATCH"', $html);
            $this->assertStringContainsString('name="_token"', $html);
            $this->assertStringContainsString('aria-controls="foodex-sidebar-recent-panel"', $html);
            $this->assertStringContainsString('aria-expanded="false"', $html);
            $this->assertStringContainsString('data-foodex-nav-favorites', $html);
            $this->assertMatchesRegularExpression('/<a class="foodex-nav-link[^"]*" href="[^"]+" data-nav-item="[^"]+"/', $html);
            $this->actingAs($user->fresh())->get(route('admin.profile.index'))->assertOk();
        }
    }

    public function test_language_switch_uses_existing_authenticated_profile_mutation_and_audit(): void
    {
        $user = $this->globalUser('B2B_ADMIN');
        $user->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($user)
            ->patch(route('admin.profile.locale'), ['locale' => 'en'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('en', $user->fresh()->locale);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'profile.locale.changed',
            'user_id' => $user->id,
        ]);

        $this->actingAs($user->fresh())
            ->get(route('admin.profile.index'))
            ->assertOk()
            ->assertSee('name="locale" value="ar"', false);
    }

    public function test_tools_use_store_scoped_sidebar_and_do_not_disclose_wholesale_or_foreign_store_pages(): void
    {
        $own = $this->retailStore('P165-OWN');
        $other = $this->retailStore('P165-OTHER');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $own,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $html = (string) $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $own]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();

            $this->assertStringContainsString('data-foodex-sidebar-utilities', $html);
            $this->assertStringContainsString('data-nav-item=', $html);
            $this->assertStringNotContainsString('href="'.route('admin.b2b.dashboard').'"', $html);
            $this->actingAs($user->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
            $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $other]))
                ->assertNotFound();
        }
    }

    public function test_unassigned_and_inactive_actors_cannot_render_authorized_sidebar_or_tools(): void
    {
        $unassigned = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$unassigned, $inactive] as $actor) {
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertForbidden()
                ->assertDontSee('data-foodex-sidebar-utilities', false);
        }
    }
}
