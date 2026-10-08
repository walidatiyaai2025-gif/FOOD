<?php
namespace App\Jobs;

use App\Services\Sms\SmsGateway;
use App\Services\Sms\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly SmsMessage $message) {}

    public function handle(SmsGateway $gateway): void
    {
        $gateway->send($this->message);
    }
}
