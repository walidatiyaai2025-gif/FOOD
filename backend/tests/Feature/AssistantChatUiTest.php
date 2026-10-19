<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssistantChatUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_assistant_ui_is_hidden_when_feature_is_disabled(): void
    {
        config(['assistant.enabled' => false]);

        $user = $this->userWithGlobalRole('SUPER_ADMIN');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('data-foodex-assistant', false)
            ->assertDontSee('assets/admin/assistant-chat.js', false);
    }

    public function test_authorized_admin_sees_persistent_assistant_surface_when_enabled(): void
    {
        config(['assistant.enabled' => true]);

        $user = $this->userWithGlobalRole('SUPER_ADMIN', 'en');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('data-foodex-assistant', false)
            ->assertSee('data-assistant-open', false)
            ->assertSee('data-assistant-drawer', false)
            ->assertSee('data-bootstrap-url="'.url('/admin/assistant/bootstrap').'"', false)
            ->assertSee('assets/admin/assistant-chat.css', false)
            ->assertSee('assets/admin/assistant-chat.js', false)
            ->assertSee('FOODEX Assistant');
    }

    public function test_store_scoped_authorized_admin_sees_assistant_when_enabled(): void
    {
        config(['assistant.enabled' => true]);

        $storeId = $this->createB2cStore();
        $user = User::query()->create([
            'name' => 'Assistant Store Admin',
            'email' => 'assistant-store-admin@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin/b2c/dashboard')
            ->assertOk()
            ->assertSee('data-foodex-assistant', false)
            ->assertSee('مساعد FOODEX');
    }

    public function test_unauthorized_user_does_not_render_active_assistant_markup(): void
    {
        config(['assistant.enabled' => true]);

        $user = User::query()->create([
            'name' => 'No Assistant Permission',
            'email' => 'no-assistant-permission@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $html = view('admin._assistant-chat', ['user' => $user])->render();

        $this->assertStringNotContainsString('data-foodex-assistant', $html);
        $this->assertStringNotContainsString('assistant-chat.js', $html);
    }

    public function test_assistant_assets_cover_persistence_responsive_accessibility_and_safe_actions(): void
    {
        $sidebar = file_get_contents(resource_path('views/admin/_sidebar.blade.php'));
        $view = file_get_contents(resource_path('views/admin/_assistant-chat.blade.php'));
        $css = file_get_contents(public_path('assets/admin/assistant-chat.css'));
        $js = file_get_contents(public_path('assets/admin/assistant-chat.js'));

        $this->assertIsString($sidebar);
        $this->assertIsString($view);
        $this->assertIsString($css);
        $this->assertIsString($js);

        $this->assertStringContainsString("@include('admin._assistant-chat')", $sidebar);
        $this->assertStringContainsString('AssistantRuntimeSettings::class', $view);
        $this->assertStringContainsString("hasPermission('assistant.use')", $view);
        $this->assertStringContainsString('aria-live="polite"', $view);
        $this->assertStringContainsString('data-assistant-new', $view);
        $this->assertStringContainsString('data-assistant-clear', $view);
        $this->assertStringContainsString('data-assistant-scope', $view);
        $this->assertStringContainsString('data-label-context-open', $view);
        $this->assertStringContainsString("__('assistant_ui.scope_label')", $view);

        $this->assertStringContainsString('width:min(410px,calc(100vw - 24px))', $css);
        $this->assertStringContainsString('@media(max-width:1023px)', $css);
        $this->assertStringContainsString('width:100vw', $css);
        $this->assertStringContainsString('inset-inline-end:0', $css);
        $this->assertStringContainsString('.foodex-assistant-context-entry', $css);
        $this->assertStringContainsString('min-height:44px', $css);
        $this->assertStringContainsString('body.foodex-assistant-open-mobile', $css);

        $this->assertStringContainsString('foodex.assistant.conversation.', $js);
        $this->assertStringContainsString('/messages', $js);
        $this->assertStringContainsString('renderCards', $js);
        $this->assertStringContainsString('renderActions', $js);
        $this->assertStringContainsString('renderEvidence', $js);
        $this->assertStringContainsString('renderFindings', $js);
        $this->assertStringContainsString('renderApproval', $js);
        $this->assertStringContainsString('context_token', $js);
        $this->assertStringContainsString('data-dashboard-assistant-open', $js);
        $this->assertStringContainsString('data-assistant-context-open', $js);
        $this->assertStringContainsString('trapFocus', $js);
        $this->assertStringContainsString('INTERNAL_KEY_PATTERN', $js);
        $this->assertStringContainsString('window.location.origin', $js);
        $this->assertStringContainsString("event.key === 'Escape'", $js);
        $this->assertStringNotContainsString('.innerHTML', $js);
    }

    public function test_assistant_ui_translation_catalogs_have_arabic_english_key_parity(): void
    {
        $english = require lang_path('en/assistant_ui.php');
        $arabic = require lang_path('ar/assistant_ui.php');

        $englishKeys = array_keys($english);
        $arabicKeys = array_keys($arabic);
        sort($englishKeys);
        sort($arabicKeys);

        $this->assertSame($englishKeys, $arabicKeys);
        $this->assertSame('Ask about this page', $english['context_open']);
        $this->assertSame('اسأل عن هذه الصفحة', $arabic['context_open']);
        $this->assertStringContainsString('FOODEX', $english['scope_denied']);
        $this->assertStringContainsString('FOODEX', $arabic['scope_denied']);
    }

    private function userWithGlobalRole(string $roleCode, string $locale = 'ar'): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-assistant-ui@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }

    private function createB2cStore(): int
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'B2C-ASSISTANT-UI',
            'name' => 'Assistant UI Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
