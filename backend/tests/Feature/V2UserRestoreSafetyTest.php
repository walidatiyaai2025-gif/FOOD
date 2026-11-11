<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2UserRestoreSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archive_and_restore_are_rejected_until_reason_impact_and_email_are_confirmed(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $target = $this->userWithRole('B2B_ADMIN');

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), ['is_active' => false])
            ->assertSessionHasErrors('reason');
        $this->assertTrue((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'Archive only after review',
                'confirm_email' => $target->email,
            ])->assertSessionHasErrors('impact_reviewed');
        $this->assertTrue((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'Archive only after review',
                'impact_reviewed' => '1',
                'confirm_email' => 'incorrect@example.test',
            ])->assertSessionHasErrors('confirm_email');
        $this->assertTrue((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), $this->reviewedStatus($target, false, 'Archive with signed impact review'))
            ->assertRedirect();
        $this->assertFalse((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), ['is_active' => true])
            ->assertSessionHasErrors('reason');
        $this->assertFalse((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), $this->reviewedStatus($target, true, 'Restore after current entitlement review'))
            ->assertRedirect();
        $this->assertTrue((bool) $target->fresh()->is_active);

        $events = AuditLog::query()
            ->where('user_id', $admin->id)
            ->where('auditable_id', $target->id)
            ->where('event', 'user.status_changed')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $events);
        $this->assertSame('Archive with signed impact review', $events[0]->after['change_reason']);
        $this->assertSame('Restore after current entitlement review', $events[1]->after['change_reason']);
        $this->assertTrue($events[1]->after['impact_reviewed']);
    }

    public function test_admin_api_requires_same_server_side_confirmations(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $target = $this->userWithRole('B2B_ADMIN');
        $url = '/api/v1/admin/security/users/'.$target->id.'/status';

        $this->actingAs($admin)
            ->patchJson($url, ['is_active' => false, 'reason' => 'API archive without approval'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('impact_reviewed');
        $this->assertTrue((bool) $target->fresh()->is_active);

        $this->actingAs($admin)
            ->patchJson($url, $this->reviewedStatus($target, false, 'API archive with full review'))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    /** @return array<string, mixed> */
    private function reviewedStatus(User $target, bool $active, string $reason): array
    {
        return [
            'is_active' => $active,
            'reason' => $reason,
            'impact_reviewed' => true,
            'confirm_email' => $target->email,
        ];
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);

        return $user;
    }
}
