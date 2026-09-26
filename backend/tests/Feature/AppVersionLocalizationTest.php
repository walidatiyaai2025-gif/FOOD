<?php

namespace Tests\Feature;

use App\Models\AppTranslation;
use App\Models\Role;
use App\Models\User;
use App\Services\TranslationCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppVersionLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_form_empty_state_success_and_table_follow_locale(): void
    {
        $user = $this->admin();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $user->update(['locale' => $locale]);
            $response = $this->actingAs($user)->get('/admin/settings/app-versions');
            $response->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('app_versions.title', [], $locale))
                ->assertSee(__('app_versions.save', [], $locale));
            if ($locale === 'ar') {
                $response->assertSee(__('app_versions.empty', [], $locale));
            }

            $this->post('/admin/settings/app-versions', $this->payload())
                ->assertRedirect('/admin/settings/app-versions')
                ->assertSessionHas('status', __('app_versions.saved', [], $locale));
            $this->get('/admin/settings/app-versions')->assertOk()
                ->assertSee(__('app_versions.driver', [], $locale))
                ->assertSee(__('app_versions.yes', [], $locale))
                ->assertSee('<bdi dir="ltr">2.1.0</bdi>', false);
        }

        $this->assertDatabaseHas('audit_logs', ['event' => 'app_version_policy.updated']);
    }

    public function test_validation_is_localized_and_preserves_entered_values(): void
    {
        $this->actingAs($this->admin());
        $payload = [...$this->payload(), 'minimum_supported_version' => '3.0.0'];
        $this->from('/admin/settings/app-versions')->post('/admin/settings/app-versions', $payload)
            ->assertRedirect('/admin/settings/app-versions')
            ->assertSessionHasErrors(['minimum_supported_version' => __('app_versions.invalid_policy')]);
        $this->get('/admin/settings/app-versions')->assertOk()
            ->assertSee('value="2.1.0"', false)
            ->assertSee('value="3.0.0"', false)
            ->assertSee('ملاحظات فودكس');
        $this->post('/admin/settings/app-versions', [])
            ->assertSessionHasErrors(['app' => 'حقل التطبيق مطلوب.']);
        $this->assertDatabaseCount('app_versions', 0);
    }

    public function test_version_text_is_editable_resettable_and_protected(): void
    {
        $user = $this->admin();
        $catalog = app(TranslationCatalog::class);
        $this->actingAs($user)->get('/admin/settings/translations?q=app_versions.title')->assertOk();
        $row = AppTranslation::query()->where('key', 'app_versions.title')->firstOrFail();
        $this->patch('/admin/settings/translations/'.$row->id, [
            'ar' => 'إصدارات فودكس', 'en' => 'FOODEX Versions',
        ])->assertRedirect();
        $catalog->syncDefaults();
        $this->assertDatabaseHas('translations', ['id' => $row->id, 'en' => 'FOODEX Versions']);
        $this->post('/admin/settings/translations/'.$row->id.'/reset')->assertRedirect();
        $this->assertDatabaseHas('translations', ['id' => $row->id, 'en' => 'App Version Policy']);

        $user->roles()->detach();
        $user->unsetRelation('roles');
        $this->post('/admin/settings/app-versions', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('app_versions', 0);
    }

    private function admin(): User
    {
        $this->seed(CoreReferenceSeeder::class);
        $user = User::query()->create([
            'name' => 'Version Admin', 'email' => 'version-locale@example.test',
            'password' => 'password', 'locale' => 'ar', 'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function payload(): array
    {
        return [
            'app' => 'driver', 'platform' => 'ios', 'latest_version' => '2.1.0',
            'minimum_supported_version' => '2.0.0', 'force_update' => '1',
            'store_url' => 'https://apps.apple.com/app/id123456789',
            'release_notes' => 'ملاحظات فودكس',
        ];
    }
}
