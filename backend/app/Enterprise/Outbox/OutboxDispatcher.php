<?php

namespace App\Enterprise\Outbox;

use App\Enterprise\Events\DomainEventSerializer;
use App\Models\EnterpriseOutboxMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class OutboxDispatcher
{
    public function __construct(
        private readonly DomainEventSerializer $serializer,
        private readonly OutboxConsumerRegistry $consumers,
    ) {}

    public function dispatchDue(int $limit = 50): int
    {
        $limit = max(1, min($limit, 200));
        $claimToken = (string) Str::uuid();
        $claimed = $this->claimBatch($claimToken, $limit);

        $dispatched = 0;
        foreach ($claimed as $message) {
            if ($this->dispatchClaimed($message, $claimToken)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * @return Collection<int, EnterpriseOutboxMessage>
     */
    private function claimBatch(string $claimToken, int $limit): Collection
    {
        return DB::transaction(function () use ($claimToken, $limit): Collection {
            $rows = EnterpriseOutboxMessage::query()
                ->whereIn('status', ['pending', 'retry'])
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return $rows;
            }

            $ids = $rows->modelKeys();
            EnterpriseOutboxMessage::query()
                ->whereIn('id', $ids)
                ->whereIn('status', ['pending', 'retry'])
                ->update([
                    'status' => 'processing',
                    'claim_token' => $claimToken,
                    'claimed_at' => now(),
                    'updated_at' => now(),
                ]);

            return EnterpriseOutboxMessage::query()
                ->whereIn('id', $ids)
                ->where('status', 'processing')
                ->where('claim_token', $claimToken)
                ->orderBy('id')
                ->get();
        });
    }

    private function dispatchClaimed(
        EnterpriseOutboxMessage $message,
        string $claimToken,
    ): bool {
        if ($message->status !== 'processing' || $message->claim_token !== $claimToken) {
            return false;
        }

        try {
            $event = $this->serializer->deserialize((string) $message->payload);
            foreach ($this->consumers->forEvent($event) as $consumer) {
                $consumer->handle($event);
            }

            EnterpriseOutboxMessage::query()
                ->whereKey($message->getKey())
                ->where('claim_token', $claimToken)
                ->where('status', 'processing')
                ->update([
                    'status' => 'dispatched',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'dispatched_at' => now(),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return true;
        } catch (Throwable $exception) {
            $this->markFailed($message, $claimToken, $exception);

            return false;
        }
    }

    private function markFailed(
        EnterpriseOutboxMessage $message,
        string $claimToken,
        Throwable $exception,
    ): void {
        $attempts = ((int) $message->attempt_count) + 1;
        $maxAttempts = max(1, (int) config('enterprise_outbox.max_attempts', 5));
        $dead = $attempts >= $maxAttempts;
        $delay = min(3600, 30 * (2 ** max(0, $attempts - 1)));

        EnterpriseOutboxMessage::query()
            ->whereKey($message->getKey())
            ->where('claim_token', $claimToken)
            ->where('status', 'processing')
            ->update([
                'status' => $dead ? 'dead' : 'retry',
                'attempt_count' => $attempts,
                'claim_token' => null,
                'claimed_at' => null,
                'available_at' => $dead ? now() : now()->addSeconds($delay),
                'failed_at' => now(),
                'last_error' => mb_substr($exception::class, 0, 500),
                'updated_at' => now(),
            ]);
    }
}
