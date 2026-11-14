<?php

namespace Tests\Feature;

use App\Models\AppTranslation;
use App\Models\Role;
use App\Models\User;
use App\Services\TranslationCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2TranslationLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        app(TranslationCatalog::class)->syncDefaults();
    }

    public function test_authorized_translation_search_treats_percent_underscore_and_escape_marker_as_literal(): void
    {
        $literal = $this->translation('v2.literal%_needle', 'قيمة حرفية', 'Literal percent value');
        $escape = $this->translation('v2.literal!_needle', 'حرف التعجب', 'Literal escape value');
        $wildcardLookalike = $this->translation('v2.literalABneedle', 'نتيجة غير مطابقة', 'Wildcard lookalike');
        $admin = $this->actor('SUPER_ADMIN');
        $this->actingAs($admin);

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->get(route('admin.translations.index', ['q' => 'v2.literal%_']))
                ->assertOk()
                ->assertSee('translation-ar-'.$literal->id, false)
                ->assertDontSee('translation-ar-'.$wildcardLookalike->id, false)
                ->assertDontSee('translation-ar-'.$escape->id, false);
        }

        $this->get(route('admin.translations.index', ['q' => 'v2.literal!_']))
            ->assertOk()
            ->assertSee('translation-ar-'.$escape->id, false)
            ->assertDontSee('translation-ar-'.$literal->id, false);

        $this->get(route('admin.translations.index', ['q' => 'v2.literal__']))
            ->assertOk()
            ->assertDontSee('translation-ar-'.$literal->id, false)
            ->assertDontSee('translation-ar-'.$escape->id, false)
            ->assertDontSee('translation-ar-'.$wildcardLookalike->id, false);

        $this->get(route('admin.translations.index', ['q' => 'literal']))
            ->assertOk()
            ->assertSee('translation-ar-'.$literal->id, false)
            ->assertSee('translation-ar-'.$escape->id, false)
            ->assertSee('translation-ar-'.$wildcardLookalike->id, false);

        $this->assertDatabaseHas('translations', [
            'id' => $literal->id, 'ar' => 'قيمة حرفية', 'en' => 'Literal percent value',
        ]);
    }

    public function test_literal_translation_search_covers_arabic_english_and_surface_without_widening_matches(): void
    {
        $arabic = $this->translation('v2.translation.ar.literal', 'بحث%_مخصص', 'Arabic translation');
        $english = $this->translation('v2.translation.en.literal', 'كلمة إنجليزية', 'Search!_English');
        $lookalike = $this->translation('v2.translation.en.lookalike', 'نتيجة أخرى', 'SearchXXEnglish');
        $otherSurface = $this->translation('v2.translation.other.surface', 'بحث%_مخصص', 'Other surface', 'driver');

        $this->actingAs($this->actor('SUPER_ADMIN'));

        $this->get(route('admin.translations.index', ['q' => 'بحث%_', 'surface' => 'admin']))
            ->assertOk()
            ->assertSee('translation-ar-'.$arabic->id, false)
            ->assertDontSee('translation-ar-'.$otherSurface->id, false);

        $this->get(route('admin.translations.index', ['q' => 'Search!_', 'surface' => 'admin']))
            ->assertOk()
            ->assertSee('translation-ar-'.$english->id, false)
            ->assertDontSee('translation-ar-'.$lookalike->id, false);

        $this->get(route('admin.translations.index', ['q' => 'Search__English']))
            ->assertOk()
            ->assertDontSee('translation-ar-'.$english->id, false)
            ->assertDontSee('translation-ar-'.$lookalike->id, false);

        $this->assertDatabaseHas('translations', ['id' => $english->id, 'en' => 'Search!_English']);
    }

    public function test_non_authorized_actor_cannot_query_translation_search(): void
    {
        $this->translation('v2.translation.denied', 'سري', 'Restricted');
        $this->actingAs($this->actor('B2B_ADMIN'))
            ->get(route('admin.translations.index', ['q' => '%']))
            ->assertForbidden();
    }

    private function translation(string $key, string $ar, string $en, string $surface = 'admin'): AppTranslation
    {
        return AppTranslation::query()->create([
            'key' => $key,
            'surface' => $surface,
            'ar' => $ar,
            'en' => $en,
        ]);
    }

    private function actor(string $role): User
    {
        $user = User::query()->create([
            'name' => 'V2 Translation '.$role,
            'email' => strtolower($role).'-translation-literals@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
