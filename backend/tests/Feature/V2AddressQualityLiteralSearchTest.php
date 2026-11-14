<?php

namespace Tests\Feature;

use App\Models\AddressQualityReview;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2AddressQualityLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_address_quality_search_matches_literal_wildcards_without_false_positives(): void
    {
        $actor = $this->admin();
        $literal = $this->review(1, 'AUD-P%_KEY');
        $escape = $this->review(2, 'AUD-P!_KEY');
        $this->review(3, 'AUD-PABKEY');

        Sanctum::actingAs($actor);

        foreach (['ar-KW', 'en-US'] as $language) {
            $this->withHeader('Accept-Language', $language)
                ->getJson($this->searchUrl('AUD-P%_'))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $literal->id)
                ->assertJsonPath('total', 1);
        }

        $this->getJson($this->searchUrl('AUD-P!_'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $escape->id);

        $this->getJson($this->searchUrl('AUD-P_KEY'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson($this->searchUrl('AUD-P'))
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertDatabaseCount('address_quality_reviews', 3);
        $this->assertDatabaseCount('address_quality_review_events', 0);
    }

    public function test_address_quality_search_uses_literal_territory_and_public_id_fields(): void
    {
        $actor = $this->admin();
        $matched = $this->review(4, 'Unrelated reason', 'ZONE%_ONE');
        $this->review(5, 'Different reason', 'ZONEABONE');

        Sanctum::actingAs($actor);

        $this->getJson($this->searchUrl('ZONE%_'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matched->id);

        $this->getJson($this->searchUrl(substr($matched->public_id, 0, 12)))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matched->id);

        $this->getJson($this->searchUrl('not-found'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('address_quality_reviews', 2);
        $this->assertDatabaseCount('address_quality_review_events', 0);
    }

    public function test_address_quality_search_never_grants_customers_view_to_unassigned_actor(): void
    {
        $this->review(6, 'Sensitive queue reason');
        $actor = User::factory()->create();
        Sanctum::actingAs($actor);

        // The API's active.user guard rejects an inactive identity before
        // evaluating the separate customers.view authorization Gate.
        $this->getJson($this->searchUrl('Sensitive'))->assertUnauthorized();

        $actor->forceFill(['is_active' => true])->save();
        Sanctum::actingAs($actor);
        $this->getJson($this->searchUrl('Sensitive'))->assertForbidden();

        $this->assertSame(1, DB::table('address_quality_reviews')->count());
        $this->assertDatabaseCount('address_quality_review_events', 0);
    }

    private function admin(): User
    {
        $actor = User::query()->create([
            'name' => 'V2 Address Quality Admin',
            'email' => 'v2-address-quality-'.Str::random(8).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    private function review(int $subjectId, string $reason, ?string $territory = null): AddressQualityReview
    {
        return AddressQualityReview::query()->create([
            'public_id' => (string) Str::uuid(),
            'subject_type' => 'customer_address',
            'subject_id' => $subjectId,
            'status' => 'unmapped',
            'quality_class' => 'low_confidence',
            'confidence' => 0.3,
            'resolution_source' => 'unresolved',
            'reason' => $reason,
            'territory_key' => $territory,
        ]);
    }

    private function searchUrl(string $search): string
    {
        return '/api/v1/admin/field-operations/address-quality?search='.rawurlencode($search);
    }
}
