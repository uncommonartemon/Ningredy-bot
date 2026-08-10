<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\Provider;

class ActivityAwareOpenAiGateway extends OpenAiGateway
{
    public function __construct(
        Dispatcher $events,
        private readonly int $idleTimeoutSeconds,
    ) {
        parent::__construct($events);
    }

    /**
     * Keep a generous absolute deadline for active work, but abort a streamed
     * response whose connection has stopped delivering bytes.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $idleTimeout = max(15, $this->idleTimeoutSeconds);

        return Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->connectTimeout(min(15, $idleTimeout))
            ->timeout($timeout ?? 60)
            ->withOptions([
                'read_timeout' => $idleTimeout,
                'curl' => [
                    CURLOPT_LOW_SPEED_LIMIT => 1,
                    CURLOPT_LOW_SPEED_TIME => $idleTimeout,
                ],
            ])
            ->throw();
    }
}
