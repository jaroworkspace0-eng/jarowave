<?php

namespace App\Jobs;

use App\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CancelUserSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 300, 900]; // seconds between attempts

    public function __construct(public int $userId)
    {
    }

    public function handle(SubscriptionService $subscriptionService): void
    {
        $subscriptionService->cancelForUser($this->userId);
    }

    public function failed(Throwable $exception): void
    {
        // All retries exhausted — this needs a human to look at billing.
        Log::error('Failed to cancel subscription after deletion', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}