<?php

namespace Tests\Feature;

use App\Enterprise\Events\VersionedDomainEvent;
use App\Enterprise\Outbox\OutboxConsumer;
use App\Enterprise\Outbox\OutboxDispatcher;
use App\Enterprise\Outbox\TransactionalOutbox;
use App\Models\EnterpriseOutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class EnterpriseTransactionalOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingOutboxConsumer::$handled = [];
        FailingOutboxConsumer::$calls = 0;
        config()->set('enterprise_events.contracts.OrderConfirmed', [1]);
    }

    public function test_outbox_insert_commits_and_rolls_back_with_local_transaction(): void
    {
        $event = $this->event();

        try {
            DB::transaction(function () use ($event): void {
                app(TransactionalOutbox::class)->enqueue($event);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseMissing('enterprise_outbox_messages', ['event_id' => $event->id]);

        DB::transaction(function () use ($event): void {
            app(TransactionalOutbox::class)->enqueue($event);
        });

        $this->assertDatabaseHas('enterprise_outbox_messages', [
            'event_id' => $event->id,
            'status' => 'pending',
        ]);
    }

    public function test_duplicate_enqueue_is_idempotent_by_event_identity(): void
    {
        $event = $this->event();
        $outbox = app(TransactionalOutbox::class);

        $first = $outbox->enqueue($event);
        $second = $outbox->enqueue($event);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('enterprise_outbox_messages', 1);
    }

    public function test_dispatch_is_bounded_and_completed_rows_are_not_replayed(): void
    {
        config()->set('enterprise_outbox.consumers.OrderConfirmed', [
            RecordingOutboxConsumer::class,
        ]);

        $outbox = app(TransactionalOutbox::class);
        $first = $outbox->enqueue($this->event());
        $second = $outbox->enqueue($this->event());

        $dispatcher = app(OutboxDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue(1));
        $this->assertCount(1, RecordingOutboxConsumer::$handled);
        $this->assertSame(1, $dispatcher->dispatchDue(1));
        $this->assertCount(2, RecordingOutboxConsumer::$handled);
        $this->assertSame(0, $dispatcher->dispatchDue(1));

        $this->assertSame('dispatched', $first->refresh()->status);
        $this->assertSame('dispatched', $second->refresh()->status);
    }

    public function test_failed_message_isolated_and_scheduled_for_retry(): void
    {
        config()->set('enterprise_outbox.consumers.OrderConfirmed', [
            FailingOutboxConsumer::class,
        ]);

        $message = app(TransactionalOutbox::class)->enqueue($this->event());

        $this->assertSame(0, app(OutboxDispatcher::class)->dispatchDue(10));

        $message->refresh();
        $this->assertSame('retry', $message->status);
        $this->assertSame(1, $message->attempt_count);
        $this->assertNotNull($message->failed_at);
        $this->assertSame(RuntimeException::class, $message->last_error);
    }

    public function test_poison_message_dead_letters_without_blocking_unrelated_row(): void
    {
        config()->set('enterprise_outbox.max_attempts', 1);
        config()->set('enterprise_outbox.consumers.OrderConfirmed', [
            FailingOutboxConsumer::class,
        ]);
        $poison = app(TransactionalOutbox::class)->enqueue($this->event());

        $this->assertSame(0, app(OutboxDispatcher::class)->dispatchDue(10));
        $this->assertSame('dead', $poison->refresh()->status);

        config()->set('enterprise_outbox.consumers.OrderConfirmed', [
            RecordingOutboxConsumer::class,
        ]);
        app(TransactionalOutbox::class)->enqueue($this->event());

        $this->assertSame(1, app(OutboxDispatcher::class)->dispatchDue(10));
        $this->assertCount(1, RecordingOutboxConsumer::$handled);
    }

    public function test_processing_claim_is_not_visible_to_another_dispatch_pass(): void
    {
        $message = app(TransactionalOutbox::class)->enqueue($this->event());
        $message->update([
            'status' => 'processing',
            'claim_token' => '00000000-0000-4000-8000-000000000001',
            'claimed_at' => now(),
        ]);

        $this->assertSame(0, app(OutboxDispatcher::class)->dispatchDue(10));
        $this->assertSame('processing', $message->refresh()->status);
    }

    public function test_outbox_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_15_143500_create_enterprise_outbox_messages.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_outbox_messages'));
        $this->assertTrue(Schema::hasTable('enterprise_modules'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_outbox_messages'));
    }

    private function event(): VersionedDomainEvent
    {
        return VersionedDomainEvent::create(
            type: 'OrderConfirmed',
            version: 1,
            scope: ['store_id' => 1],
            payload: ['order_id' => random_int(1, 999999)],
        );
    }
}

final class RecordingOutboxConsumer implements OutboxConsumer
{
    /** @var list<string> */
    public static array $handled = [];

    public function handle(VersionedDomainEvent $event): void
    {
        self::$handled[] = $event->id;
    }
}

final class FailingOutboxConsumer implements OutboxConsumer
{
    public static int $calls = 0;

    public function handle(VersionedDomainEvent $event): void
    {
        self::$calls++;

        throw new RuntimeException('provider-secret-message');
    }
}
