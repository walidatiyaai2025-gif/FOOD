<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_action_can_emit_structured_audit_entry(): void
    {
        $actor = User::query()->create([
            'name' => 'Auditor',
            'email' => 'auditor@example.test',
            'password' => 'password',
        ]);
        $request = Request::create('/admin/settings', 'PATCH', server: [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'FOODEX-Test',
        ]);

        $log = app(AuditLogger::class)->record(
            'settings.updated',
            $actor,
            $actor,
            ['locale' => 'en'],
            ['locale' => 'ar'],
            $request,
        );

        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame('settings.updated', $log->event);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($actor->id, $log->auditable_id);
        $this->assertSame(['locale' => 'en'], $log->before);
        $this->assertSame(['locale' => 'ar'], $log->after);
        $this->assertSame('192.0.2.10', $log->ip_address);
        $this->assertSame('FOODEX-Test', $log->user_agent);
    }

    public function test_audit_payload_redacts_secrets_recursively(): void
    {
        $log = app(AuditLogger::class)->record('credentials.changed', null, null, null, [
            'email' => 'safe@example.test',
            'password' => 'plain-secret',
            'profile' => [
                'api_key' => 'secret-key',
                'display_name' => 'Safe Name',
            ],
        ], Request::create('/internal', 'POST'));

        $this->assertSame('safe@example.test', $log->after['email']);
        $this->assertSame('[REDACTED]', $log->after['password']);
        $this->assertSame('[REDACTED]', $log->after['profile']['api_key']);
        $this->assertStringNotContainsString('plain-secret', $log->toJson());
        $this->assertStringNotContainsString('secret-key', $log->toJson());
    }
}
