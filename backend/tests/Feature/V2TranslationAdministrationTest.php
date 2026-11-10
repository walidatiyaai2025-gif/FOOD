<?php

namespace Tests\Feature;

use App\Models\AppTranslation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2TranslationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        $user = User::query()->create([
            'name' => 'V2 Translation '.$role,
            'email' => strtolower($role).'@translations.example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    public function test_wholesale_manager_cannot_read_or_mutate_platform_translations(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $translation = AppTranslation::query()->create([
            'key' => 'v2.security.denied',
            'surface' => 'customer',
            'ar' => 'قبل',
            'en' => 'Before',
        ]);

        $this->actingAs($this->actor('B2B_ADMIN'))
            ->get(route('admin.translations.index'))
            ->assertForbidden();

        $this->patch(route('admin.translations.update', $translation), [
            'ar' => 'بعد',
            'en' => 'After',
        ])->assertForbidden();

        $this->post(route('admin.translations.reset', $translation))
            ->assertForbidden();

        $this->assertDatabaseHas('translations', [
            'id' => $translation->id,
            'ar' => 'قبل',
            'en' => 'Before',
        ]);
    }

    public function test_super_admin_can_open_accessible_translation_editor_and_save(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $translation = AppTranslation::query()->create([
            'key' => 'v2.custom.editor',
            'surface' => 'customer',
            'ar' => 'الأصل',
            'en' => 'Original',
        ]);

        $this->actingAs($this->actor('SUPER_ADMIN'))
            ->get(route('admin.translations.index', ['q' => 'v2.custom.editor']))
            ->assertOk()
            ->assertSee('translation-ar-'.$translation->id)
            ->assertSee('translation-en-'.$translation->id)
            ->assertSee('aria-label=', false);

        $this->patch(route('admin.translations.update', $translation), [
            'ar' => 'محدّث',
            'en' => 'Updated',
        ])->assertRedirect();

        $this->assertDatabaseHas('translations', [
            'id' => $translation->id,
            'ar' => 'محدّث',
            'en' => 'Updated',
        ]);
    }
}
