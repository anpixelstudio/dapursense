<?php

namespace App\Domain\Analysis;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class WorkerClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('kitchen.worker.url'))
            ->withToken((string) config('kitchen.worker.service_token'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10);
    }

    /** @param array<string, mixed> $payload */
    public function submit(array $payload): void
    {
        $this->http()->post('/runs', $payload)->throw();
    }

    public function cancel(string $runId): bool
    {
        try {
            return $this->http()->post("/runs/{$runId}/cancel")->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    public function health(): ?array
    {
        try {
            $response = $this->http()->timeout(3)->get('/health');

            return $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
