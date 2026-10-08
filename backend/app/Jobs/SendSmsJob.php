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

    public int $tries=3;

    public function __construct(public SmsMessage $message) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5,30,120];
    }

    public function handle(SmsGateway $gateway): void
    {
        $gateway->send($this->message);
    }
}
