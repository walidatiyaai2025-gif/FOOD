<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\ConnectionHealthRecorder;
use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileManager;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Domain\WhatsApp\ConnectionValidationResult;
use App\Domain\WhatsApp\ConnectionValidator;
use App\Domain\WhatsApp\Lifecycle;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Domain\WhatsApp\UnavailableConnectionValidator;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class WhatsAppConnectionProfileTest extends TestCase
{
    use RefreshDatabase;

    private FakeConnectionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Http::preventStrayRequests();
        $this->validator = new FakeConnectionValidator;
        $this->app->instance(ConnectionValidator::class, $this->validator);
    }

    private function admin(array $attributes = []): User
    {
        $user = User::factory()->create(array_replace(['is_active' => true, 'locale' => 'en'], $attributes));
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    /** @return array<string, mixed> */
    private function profileData(string $name, string $phone): array
    {
        return [
            'name' => $name,
            'display_phone_number' => $phone,
            'normalized_phone_number' => $phone,
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'phone-id-'.substr($phone, -4),
            'provider_account_id' => 'waba-demo',
            'provider_app_id' => 'app-demo',
            'provider_metadata' => ['region' => 'test'],
            'webhook_metadata' => ['mode' => 'future-1402'],
        ];
    }

    private function createProfile(User $actor, string $name, string $phone, ?string $secret = null): WhatsAppConnectionProfile
    {
        return app(ConnectionProfileManager::class)->create($actor, $this->profileData($name, $phone), $secret);
    }

    public function test_zero_profiles_and_zero_whatsapp_credentials_are_valid(): void
    {
        $this->assertSame(0, WhatsAppConnectionProfile::query()->count());
        $this->get('/health')->assertOk();
        $this->assertSame(Lifecycle::DISABLED, app(RuntimePolicy::class)->state()->lifecycle);
        $this->assertFalse(app(RuntimePolicy::class)->isOperational());
        Http::assertNothingSent();
    }

    public function test_profile_is_created_non_active_and_secret_is_encrypted_hidden_and_audited_safely(): void
    {
        $actor = $this->admin();
        $secret = 'test-secret-never-log-123';
        $profile = $this->createProfile($actor, 'Kuwait main', '+96550000001', $secret);

        $this->assertSame(ConnectionProfileStatus::DRAFT, $profile->status);
        $this->assertSame(ConnectionHealthStatus::NEVER_TESTED, $profile->health_status);
        $this->assertFalse($profile->is_primary);
        $this->assertSame($secret, $profile->credential_secret);
        $this->assertNotSame($secret, DB::table('whats_app_connection_profiles')->where('id', $profile->id)->value('credential_secret'));
        $this->assertArrayNotHasKey('credential_secret', $profile->toArray());
        $this->assertSame('••••••••', $profile->maskedSecret());

        $auditPayload = AuditLog::query()->where('event', 'like', 'whatsapp.connection_%')->get()->toJson();
        $this->assertStringNotContainsString($secret, $auditPayload);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.connection_profile_created', 'user_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.connection_credential_replaced', 'user_id' => $actor->id]);
    }

    public function test_omitted_secret_on_edit_does_not_blank_existing_credential(): void
    {
        $actor = $this->admin();
        $profile = $this->createProfile($actor, 'A', '+96550000002', 'keep-me');

        $data = $this->profileData('A renamed', '+96550000002');
        $updated = app(ConnectionProfileManager::class)->update($actor, $profile, $data);

        $this->assertSame('keep-me', $updated->credential_secret);
        $this->assertSame('A renamed', $updated->name);
        $this->assertSame(ConnectionProfileStatus::DRAFT, $updated->status);
    }

    public function test_sensitive_metadata_keys_are_rejected_and_provider_diagnostics_are_credential_redacted(): void
    {
        $actor = $this->admin();

        try {
            app(ConnectionProfileManager::class)->create($actor, array_replace(
                $this->profileData('Unsafe metadata', '+96550000018'),
                ['provider_metadata' => ['nested' => ['access_token' => 'do-not-store-here']]],
            ));
            $this->fail('Credential-shaped metadata was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, WhatsAppConnectionProfile::query()->count());
        }

        $secret = 'provider-secret-xyz';
        $profile = $this->createProfile($actor, 'Diagnostic redaction', '+96550000019', $secret);
        $this->validator->valid = false;
        $this->validator->health = ConnectionHealthStatus::INVALID_CREDENTIALS;
        $this->validator->message = 'Provider rejected token '.$secret;

        $this->assertFalse(app(ConnectionProfileManager::class)->validate($actor, $profile));
        $profile->refresh();

        $this->assertStringNotContainsString($secret, (string) $profile->last_provider_error);
        $this->assertStringContainsString('[REDACTED]', (string) $profile->last_provider_error);
        $this->assertStringNotContainsString($secret, AuditLog::query()->get()->toJson());

        app(ConnectionHealthRecorder::class)->providerError($profile, 'Transport echoed '.$secret);
        $this->assertStringNotContainsString($secret, (string) $profile->fresh()->last_provider_error);
    }

    public function test_default_runtime_validator_never_fakes_provider_success(): void
    {
        $this->app->forgetInstance(ConnectionValidator::class);
        $this->app->bind(ConnectionValidator::class, UnavailableConnectionValidator::class);
        $actor = $this->admin();
        $profile = $this->createProfile($actor, 'No adapter', '+96550000003', 'placeholder');

        $this->assertFalse(app(ConnectionProfileManager::class)->validate($actor, $profile));
        $profile->refresh();
        $this->assertSame(ConnectionProfileStatus::INVALID, $profile->status);
        $this->assertSame(ConnectionHealthStatus::PROVIDER_UNAVAILABLE, $profile->health_status);
        $this->assertFalse($profile->is_primary);
    }

    public function test_invalid_or_unvalidated_profile_cannot_replace_primary_and_failed_switch_preserves_old_primary(): void
    {
        $actor = $this->admin();
        $manager = app(ConnectionProfileManager::class);
        $a = $this->createProfile($actor, 'A', '+96550000004', 'a-secret');
        $a = $manager->switchPrimary($actor, $a);
        $b = $this->createProfile($actor, 'B', '+96550000005', 'b-secret');

        $this->validator->valid = false;
        $this->validator->health = ConnectionHealthStatus::INVALID_CREDENTIALS;
        try {
            $manager->switchPrimary($actor, $b);
            $this->fail('Invalid profile replaced the healthy primary.');
        } catch (ValidationException) {
            $this->assertTrue($a->fresh()->is_primary);
            $this->assertSame(ConnectionProfileStatus::ACTIVE, $a->fresh()->status);
            $this->assertFalse($b->fresh()->is_primary);
            $this->assertSame(ConnectionProfileStatus::INVALID, $b->fresh()->status);
        }

        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.connection_primary_switch_rejected']);
    }

    public function test_primary_switch_is_atomic_and_preserves_old_profile_in_drain_mode(): void
    {
        $actor = $this->admin();
        $manager = app(ConnectionProfileManager::class);
        $a = $manager->switchPrimary($actor, $this->createProfile($actor, 'A', '+96550000006', 'a'));
        $b = $this->createProfile($actor, 'B', '+96550000007', 'b');

        $b = $manager->switchPrimary($actor, $b, 'drain');

        $this->assertTrue($b->is_primary);
        $this->assertSame(ConnectionProfileStatus::ACTIVE, $b->status);
        $this->assertSame(1, $b->primary_slot);
        $a->refresh();
        $this->assertFalse($a->is_primary);
        $this->assertTrue($a->receive_only);
        $this->assertSame(ConnectionProfileStatus::RECEIVE_ONLY, $a->status);
        $this->assertSame(2, WhatsAppConnectionProfile::query()->count());
        $this->assertSame(1, WhatsAppConnectionProfile::query()->where('is_primary', true)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.connection_primary_changed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.connection_profile_moved_to_receive_only']);
    }

    public function test_database_constraint_rejects_two_primary_slots(): void
    {
        $actor = $this->admin();
        $manager = app(ConnectionProfileManager::class);
        $manager->switchPrimary($actor, $this->createProfile($actor, 'A', '+96550000008', 'a'));
        $b = $this->createProfile($actor, 'B', '+96550000009', 'b');

        try {
            $b->forceFill([
                'is_primary' => true,
                'primary_slot' => 1,
                'status' => ConnectionProfileStatus::ACTIVE,
            ])->save();
            $this->fail('Database accepted a second primary slot.');
        } catch (QueryException) {
            $this->assertSame(1, WhatsAppConnectionProfile::query()->where('primary_slot', 1)->count());
        }
    }

    public function test_switch_can_deactivate_old_profile_without_deleting_history(): void
    {
        $actor = $this->admin();
        $manager = app(ConnectionProfileManager::class);
        $a = $manager->switchPrimary($actor, $this->createProfile($actor, 'A', '+96550000010', 'a'));
        $b = $manager->switchPrimary($actor, $this->createProfile($actor, 'B', '+96550000011', 'b'), 'inactive');

        $this->assertTrue($b->is_primary);
        $a->refresh();
        $this->assertSame(ConnectionProfileStatus::INACTIVE, $a->status);
        $this->assertFalse($a->receive_only);
        $this->assertNotNull(WhatsAppConnectionProfile::query()->find($a->id));
    }

    public function test_webhook_configuration_changes_require_fresh_verification(): void
    {
        $actor = $this->admin();
        $profile = $this->createProfile($actor, 'A', '+96550000020', 'access-token');
        $profile->forceFill(['last_webhook_at' => now()])->save();

        $updated = app(ConnectionProfileManager::class)->replaceCredential(
            $actor,
            $profile,
            webhookSigningSecret: 'rotated-signing-secret',
        );

        $this->assertNull($updated->last_webhook_at);

        $updated->forceFill(['last_webhook_at' => now()])->save();
        $data = $this->profileData('A renamed', '+96550000020');
        $updated = app(ConnectionProfileManager::class)->update($actor, $updated, $data);

        $this->assertNull($updated->last_webhook_at);
    }

    public function test_credential_replacement_requires_super_admin_and_never_exposes_secret(): void
    {
        $actor = $this->admin();
        $profile = $this->createProfile($actor, 'A', '+96550000012', 'old-secret');
        $normal = User::factory()->create(['is_active' => true]);

        try {
            app(ConnectionProfileManager::class)->replaceCredential($normal, $profile, 'new-secret');
            $this->fail('Ordinary user replaced a provider credential.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $updated = app(ConnectionProfileManager::class)->replaceCredential($actor, $profile, 'new-secret');
        $this->assertSame('new-secret', $updated->credential_secret);
        $this->assertSame(ConnectionHealthStatus::NEVER_TESTED, $updated->health_status);
        $this->assertStringNotContainsString('new-secret', AuditLog::query()->get()->toJson());
    }

    public function test_web_test_send_normalizes_egyptian_01_number_and_sends_real_provider_message(): void
    {
        $actor = $this->admin();
        $profile = $this->createProfile($actor, 'Egypt test', '+201001112233', 'meta-access-token');

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.test-egypt-01']],
            ], 200),
        ]);

        $this->actingAs($actor)
            ->post(route('admin.whatsapp.connections.test-send', $profile), [
                'recipient' => '01012345678',
                'message' => 'FOODEX gateway test',
            ])
            ->assertRedirect(route('admin.whatsapp.connections.index'))
            ->assertSessionHas('status', 'WhatsApp test message sent to +201012345678.');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_ends_with($request->url(), '/messages')
                && data_get($payload, 'to') === '201012345678'
                && data_get($payload, 'type') === 'text'
                && data_get($payload, 'text.body') === 'FOODEX gateway test';
        });

        $this->assertNotNull($profile->fresh()->last_outbound_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'whatsapp.connection_test_message_sent',
            'user_id' => $actor->id,
        ]);
        $auditAfter = AuditLog::query()
            ->where('event', 'whatsapp.connection_test_message_sent')
            ->value('after');
        $this->assertStringNotContainsString(
            '+201012345678',
            json_encode($auditAfter, JSON_THROW_ON_ERROR),
        );
    }

    public function test_web_test_send_rejects_invalid_non_e164_recipient_before_provider_call(): void
    {
        $actor = $this->admin();
        $profile = $this->createProfile($actor, 'Invalid test', '+96550000021', 'meta-access-token');

        Http::fake();

        $this->actingAs($actor)
            ->post(route('admin.whatsapp.connections.test-send', $profile), [
                'recipient' => '12345',
                'message' => 'Should not send',
            ])
            ->assertSessionHasErrors('recipient');

        Http::assertNothingSent();
    }

    public function test_unauthorized_actor_cannot_manage_or_view_connections(): void
    {
        $normal = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($normal)->get(route('admin.whatsapp.connections.index'))->assertForbidden();
        $this->actingAs($normal)->post(route('admin.whatsapp.connections.store'), [
            'name' => 'Denied',
            'display_phone_number' => '+96550000013',
            'provider_type' => 'meta_cloud',
        ])->assertForbidden();
        $this->assertSame(0, WhatsAppConnectionProfile::query()->count());
    }

    public function test_global_disabled_and_emergency_stop_remain_authoritative_with_active_profile(): void
    {
        $actor = $this->admin();
        app(ConnectionProfileManager::class)->switchPrimary(
            $actor,
            $this->createProfile($actor, 'A', '+96550000014', 'a'),
        );

        $policy = app(RuntimePolicy::class);
        $this->assertSame(Lifecycle::DISABLED, $policy->state()->lifecycle);
        $this->assertFalse($policy->allowsOutbound('+96550000014', Capability::OUTBOUND));

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => Lifecycle::INTERNAL_TEST->value,
            'emergency_stop' => true,
            'capabilities' => ['outbound' => true],
            'test_numbers' => ['+96550000014'],
        ])->save();
        $this->assertFalse($policy->allowsOutbound('+96550000014', Capability::OUTBOUND));

        WhatsAppModuleState::query()->findOrFail(1)->forceFill(['emergency_stop' => false])->save();
        $this->assertTrue($policy->allowsOutbound('+96550000014', Capability::OUTBOUND));
    }

    public function test_health_failure_is_normalized_and_does_not_break_unrelated_food(): void
    {
        $actor = $this->admin();
        $profile = $this->createProfile($actor, 'A', '+96550000015', 'health-check-secret');
        $this->validator->throw = true;

        $this->assertFalse(app(ConnectionProfileManager::class)->validate($actor, $profile));
        $profile->refresh();
        $this->assertSame(ConnectionHealthStatus::PROVIDER_UNAVAILABLE, $profile->health_status);
        $this->assertSame('Provider validation failed safely.', $profile->last_provider_error);
        $this->get('/health')->assertOk();
        Http::assertNothingSent();
    }

    public function test_health_recorder_keeps_transport_observability_on_profile_contract(): void
    {
        $actor = $this->admin();
        $profile = app(ConnectionProfileManager::class)->switchPrimary(
            $actor,
            $this->createProfile($actor, 'A', '+96550000016', 'transport-health-secret'),
        );
        $recorder = app(ConnectionHealthRecorder::class);

        $recorder->webhookSucceeded($profile);
        $recorder->outboundSucceeded($profile);
        $recorder->providerError($profile, "safe provider error\nretry later");

        $profile->refresh();
        $this->assertNotNull($profile->last_webhook_at);
        $this->assertNotNull($profile->last_outbound_at);
        $this->assertSame(ConnectionHealthStatus::DEGRADED, $profile->health_status);
        $this->assertSame(ConnectionProfileStatus::DEGRADED, $profile->status);
        $this->assertSame('safe provider error retry later', $profile->last_provider_error);
    }

    public function test_arabic_and_english_settings_surfaces_render_without_exposing_secret(): void
    {
        $en = $this->admin(['locale' => 'en']);
        $profile = $this->createProfile($en, 'Main profile', '+96550000017', 'never-render-me');

        $this->actingAs($en)->get(route('admin.whatsapp.connections.index'))
            ->assertOk()
            ->assertSee('WhatsApp connections')
            ->assertSee('Transport delivery health')
            ->assertSee('dir="ltr"', false)
            ->assertDontSee('never-render-me');

        $ar = $this->admin(['locale' => 'ar']);
        $this->actingAs($ar)->get(route('admin.whatsapp.connections.index'))
            ->assertOk()
            ->assertSee('اتصالات واتساب')
            ->assertSee('صحة نقل رسائل واتساب')
            ->assertSee('dir="rtl"', false)
            ->assertDontSee('never-render-me');

        $this->assertSame('••••••••', $profile->maskedSecret());
    }

    public function test_migration_is_additive_and_round_trip_rollback_safe(): void
    {
        $this->assertTrue(Schema::hasTable('whats_app_connection_profiles'));
        $this->assertTrue(Schema::hasColumns('whats_app_connection_profiles', [
            'provider_type', 'credential_secret', 'primary_slot', 'health_status',
        ]));

        $migration = require database_path('migrations/2026_10_14_061000_create_whats_app_connection_profiles_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('whats_app_connection_profiles'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('whats_app_connection_profiles'));
        $this->get('/health')->assertOk();
    }
}

final class FakeConnectionValidator implements ConnectionValidator
{
    public bool $valid = true;

    public bool $throw = false;

    public ConnectionHealthStatus $health = ConnectionHealthStatus::READY;

    public ?string $message = null;

    public function validate(WhatsAppConnectionProfile $profile): ConnectionValidationResult
    {
        if ($this->throw) {
            throw new RuntimeException('provider unavailable');
        }

        return new ConnectionValidationResult(
            valid: $this->valid,
            health: $this->valid ? ConnectionHealthStatus::READY : $this->health,
            accountIdentity: $this->valid ? 'test-account' : null,
            phoneIdentity: $this->valid ? $profile->normalized_phone_number : null,
            providerAvailable: $this->valid,
            capabilities: $this->valid ? ['messages'] : [],
            diagnosticCode: $this->valid ? 'ok' : 'invalid_credentials',
            message: $this->message ?? ($this->valid ? 'Provider connection validated.' : 'Credentials rejected.'),
            checkedAt: new DateTimeImmutable,
        );
    }
}
