<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2SidebarHumanLabelContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_every_canonical_sidebar_registry_label_is_translated_in_both_supported_languages(): void
    {
        $registry = config('admin_sidebar');

        foreach (['groups', 'items'] as $section) {
            $this->assertNotEmpty($registry[$section] ?? [], "Sidebar registry [{$section}] must not be empty.");

            foreach ($registry[$section] as $key => $node) {
                $translationKey = $node['label'] ?? null;
                $this->assertIsString($translationKey, "Sidebar {$section} node [{$key}] has no label translation key.");
                $this->assertNotSame('', trim($translationKey));

                foreach (['ar', 'en'] as $locale) {
                    $label = __($translationKey, [], $locale);

                    $this->assertNotSame(
                        $translationKey,
                        $label,
                        "Sidebar {$section} node [{$key}] leaks untranslated internal key [{$translationKey}] in [{$locale}].",
                    );
                    $this->assertNotSame('', trim($label), "Empty human label for [{$key}] in [{$locale}].");
                    $this->assertStringNotContainsString(
                        $key,
                        $label,
                        "Sidebar {$section} node [{$key}] exposes its internal key in [{$locale}].",
                    );
                }
            }
        }
    }

    public function test_authorized_sidebar_groups_and_pages_resolve_human_labels_in_arabic_and_english(): void
    {
        foreach (['SUPER_ADMIN', 'B2B_ADMIN', 'CUSTOMER_SERVICE_AGENT'] as $roleCode) {
            $user = $this->globalUser($roleCode);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $groups = app(AdminNavigation::class)->groupsFor($user);
                $this->assertNotEmpty($groups);

                foreach ($groups as $group) {
                    $this->assertHumanLabel($group, $locale);

                    foreach ($group['children'] as $page) {
                        $this->assertHumanLabel($page, $locale);
                        $this->assertNotEmpty($page['route'] ?? null);

                        foreach ($page['children'] ?? [] as $child) {
                            $this->assertHumanLabel($child, $locale);
                            $this->assertNotEmpty($child['route'] ?? null);
                        }
                    }
                }
            }
        }
    }

    public function test_internal_navigation_keys_are_not_authorization_grants(): void
    {
        $unprivileged = User::query()->create([
            'name' => 'No Navigation Grants',
            'email' => 'v2-sidebar-human-no-role@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $pages = collect(app(AdminNavigation::class)->groupsFor($unprivileged))
            ->flatMap(fn (array $group): array => $group['children']);

        $this->assertFalse($pages->contains('key', 'catalog_management'));
        $this->assertFalse($pages->contains('key', 'erp_center'));

        $this->actingAs($unprivileged)
            ->get(route('admin.catalog.index', [], false))
            ->assertForbidden();
        $this->actingAs($unprivileged)
            ->get(route('admin.erp.index', [], false))
            ->assertForbidden();
    }

    private function assertHumanLabel(array $node, string $locale): void
    {
        $key = (string) ($node['key'] ?? '');
        $translationKey = (string) ($node['label'] ?? '');
        $label = $node['label_text'] ?? __($translationKey, [], $locale);

        $this->assertNotSame('', trim((string) $label), "Empty visible label for [{$key}] in [{$locale}].");
        $this->assertNotSame($translationKey, $label, "Untranslated visible Sidebar label for [{$key}] in [{$locale}].");
        $this->assertNotSame($key, $label, "Internal Sidebar key [{$key}] was exposed as a user-facing label.");
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'@v2-sidebar-labels.example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
