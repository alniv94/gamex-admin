<?php

namespace App\Jobs;

use App\Services\Auth\AdminSms;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPlayerNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $mobile, public string $message)
    {
        $this->onConnection('database');
    }

    /**
     * Execute the job.
     */
    public function handle(AdminSms $sms): void
    {
        $sms->sendMessage($this->mobile, $this->message);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
