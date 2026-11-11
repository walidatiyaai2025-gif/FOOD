<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\User;
use App\Services\DashboardDeploymentProvenance;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2DashboardDeploymentProvenanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_matching_installed_and_build_versions_are_only_metadata_consistent(): void
    {
        SystemVersion::query()->create(['version' => '1.0.127', 'installed_at' => now()]);
        $sha = str_repeat('a', 40);
        [$version, $build] = $this->fixtures('1.0.127', json_encode([
            'version' => '1.0.127', 'source_commit' => $sha,
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'api.github.com/*' => Http::response(['sha' => $sha], 200),
        ]);

        $service = app(DashboardDeploymentProvenance::class);
        $unchecked = $service->inspect(false, $version, $build);
        $this->assertSame('consistent', $unchecked['metadata_state']);
        $this->assertSame('not_checked', $unchecked['github_state']);
        Http::assertNothingSent();

        $verified = $service->inspect(true, $version, $build);
        $this->assertSame('consistent', $verified['metadata_state']);
        $this->assertSame('found', $verified['github_state']);
        $this->assertSame($sha, $verified['source_commit']);

        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool =>
            $request->url() === 'https://api.github.com/repos/walidatiyaai2025-gif/FOOD/commits/'.$sha
        );
    }

    public function test_outdated_or_missing_release_build_metadata_fails_closed_without_network(): void
    {
        SystemVersion::query()->create(['version' => '1.0.127', 'installed_at' => now()]);
        Http::fake();
        [$version, $build] = $this->fixtures('1.0.127', json_encode([
            'version' => '1.0.95', 'source_commit' => str_repeat('b', 40),
        ], JSON_THROW_ON_ERROR));

        $service = app(DashboardDeploymentProvenance::class);
        $mismatch = $service->inspect(false, $version, $build);
        $this->assertSame('mismatch', $mismatch['metadata_state']);
        $this->assertSame('1.0.95', $mismatch['build_version']);
        $this->assertSame('not_checked', $mismatch['github_state']);
        Http::assertNothingSent();

        File::put($build, '{"version":"1.0.127","source_commit":"not-a-sha"}');
        $invalid = $service->inspect(true, $version, $build);
        $this->assertSame('unknown', $invalid['metadata_state']);
        $this->assertSame('unknown', $invalid['github_state']);
        $this->assertNull($invalid['source_commit']);
        Http::assertNothingSent();

        File::put($build, '{invalid-json');
        $this->assertSame('unknown', $service->inspect(false, $version, $build)['metadata_state']);
    }

    public function test_source_and_build_conflict_is_reported_even_without_installed_database_row(): void
    {
        [$version, $build] = $this->fixtures('1.0.127', json_encode([
            'version' => '1.0.95', 'source_commit' => str_repeat('f', 40),
        ], JSON_THROW_ON_ERROR));
        $result = app(DashboardDeploymentProvenance::class)->inspect(false, $version, $build);
        $this->assertNull($result['installed_version']);
        $this->assertSame('mismatch', $result['metadata_state']);
        $this->assertSame('not_checked', $result['github_state']);
    }

    public function test_symlinked_or_oversized_metadata_is_never_read_or_exported(): void
    {
        SystemVersion::query()->create(['version' => '1.0.127', 'installed_at' => now()]);
        $sha = str_repeat('a', 40);
        [$version, $build] = $this->fixtures('1.0.127', json_encode([
            'version' => '1.0.127', 'source_commit' => $sha,
        ], JSON_THROW_ON_ERROR));
        $service = app(DashboardDeploymentProvenance::class);

        File::put($build, str_repeat('s', 131073));
        $oversized = $service->inspect(false, $version, $build);
        $this->assertSame('unknown', $oversized['metadata_state']);
        $this->assertNull($oversized['source_commit']);

        File::put($build, json_encode([
            'version' => '1.0.127', 'source_commit' => $sha,
        ], JSON_THROW_ON_ERROR));
        File::put($version, 'APP_KEY=secret-should-never-appear');
        $invalid = $service->inspect(false, $version, $build);
        $this->assertNull($invalid['source_version']);
        $this->assertSame('unknown', $invalid['metadata_state']);

        // Symlinked metadata must not be followed even when it points inside
        // the repository and the target contains valid-looking values.
        File::put($version, '1.0.127');
        $target = dirname($version).'/hidden-version';
        File::put($target, '1.0.127');
        File::delete($version);
        if (! symlink($target, $version)) {
            $this->markTestSkipped('The filesystem does not support symlinks.');
        }
        $linked = $service->inspect(false, $version, $build);
        $this->assertNull($linked['source_version']);
        $this->assertSame('unknown', $linked['metadata_state']);
    }

    public function test_github_not_found_or_outage_never_attests_deployed_files(): void
    {
        SystemVersion::query()->create(['version' => '1.0.127', 'installed_at' => now()]);
        $sha = str_repeat('c', 40);
        [$version, $build] = $this->fixtures('1.0.127', json_encode([
            'version' => '1.0.127', 'source_commit' => $sha,
        ], JSON_THROW_ON_ERROR));

        Http::fake(['api.github.com/*' => Http::response([], 404)]);
        $service = app(DashboardDeploymentProvenance::class);
        $this->assertSame('not_found', $service->inspect(true, $version, $build)['github_state']);

        // HTTP fake callbacks accumulate on one Facade factory, so each
        // independent remote outcome needs an isolated factory instance.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.github.com/*' => Http::response([], 503)]);
        $this->assertSame('unknown', $service->inspect(true, $version, $build)['github_state']);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.github.com/*' => Http::response(['sha' => str_repeat('d', 40)], 200)]);
        $this->assertSame('unknown', $service->inspect(true, $version, $build)['github_state']);
    }

    public function test_only_authorized_platform_admin_can_see_or_export_the_bilingual_provenance(): void
    {
        $super = $this->userWithRole('SUPER_ADMIN');
        $wholesale = $this->userWithRole('B2B_ADMIN');

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $super->forceFill(['locale' => $locale])->save();

            $this->actingAs($super)
                ->get(route('admin.inspector.index'))
                ->assertOk()
                ->assertSee('data-dashboard-provenance', false)
                ->assertSee(__('system_admin.provenance.title'))
                ->assertSee(__('system_admin.provenance.description'))
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
        }

        Http::fake();
        $this->actingAs($super)
            ->get(route('admin.inspector.index', ['verify_source' => 1]))
            ->assertOk()
            ->assertSee(__('system_admin.provenance.github_state'));
        // No valid, matching build fingerprint means the verification is
        // not represented as successful even if GitHub is reachable.
        $export = $this->actingAs($super)->get(route('admin.inspector.export'));
        $export->assertOk();
        $payload = json_decode($export->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('deployment_provenance', $payload);
        $this->assertArrayHasKey('metadata_state', $payload['deployment_provenance']);
        $this->assertArrayHasKey('github_state', $payload['deployment_provenance']);

        $this->actingAs($wholesale)->get(route('admin.inspector.index'))->assertForbidden();
        $this->actingAs($wholesale)->get(route('admin.inspector.index', ['verify_source' => 1]))->assertForbidden();
        $this->actingAs($wholesale)->get(route('admin.inspector.export'))->assertForbidden();
    }

    /** @return array{string,string} */
    private function fixtures(string $sourceVersion, string $buildJson): array
    {
        $directory = storage_path('framework/testing/dashboard-provenance-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $this->temporaryDirectories[] = $directory;
        $version = $directory.'/VERSION';
        $build = $directory.'/BUILD_INFO.json';
        File::put($version, $sourceVersion."\n");
        File::put($build, $buildJson);

        return [$version, $build];
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user->fresh();
    }
}
