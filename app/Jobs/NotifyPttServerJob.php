<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifyPttServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [5, 15, 30, 60, 120];

    public function __construct(
        public string $endpoint,
        public array $payload,
    ) {
    }

    public function handle(): void
    {
        $response = Http::timeout(5)
            ->post(env('PTT_SERVER_URL') . $this->endpoint, $this->payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "PTT server responded with {$response->status()}"
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to notify PTT server after deletion', [
            'endpoint' => $this->endpoint,
            'payload' => $this->payload,
            'error' => $exception->getMessage(),
        ]);
    }
}