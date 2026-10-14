<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\Provider;

class ActivityAwareOpenAiGateway extends OpenAiGateway
{
    private array $researchStream = [];

    /** Preserve protocol evidence before the SDK discards terminal/error details. */
    protected function parseServerSentEvents($streamBody): \Generator
    {
        $this->researchStream = ['raw_text' => '', 'output_items' => [], 'response_id' => null,
            'response_status' => null, 'usage' => null, 'refusal' => null];
        foreach (parent::parseServerSentEvents($streamBody) as $event) {
            $type = $event['type'] ?? '';
            if ($type === 'response.created') {
                $this->researchStream['response_id'] = $event['response']['id'] ?? null;
            }
            if ($type === 'response.output_text.delta') {
                $this->researchStream['raw_text'] .= (string) ($event['delta'] ?? '');
            }
            if ($type === 'response.refusal.delta') {
                $this->researchStream['refusal'] .= (string) ($event['delta'] ?? '');
            }
            if ($type === 'response.output_item.done') {
                $item = $event['item'] ?? [];
                if (in_array($item['type'] ?? '', ['message', 'web_search_call'], true)) {
                    $this->researchStream['output_items'][] = $item;
                }
            }
            if (in_array($type, ['response.completed', 'response.incomplete', 'response.failed'], true)) {
                $response = $event['response'] ?? [];
                $this->researchStream['response_status'] = $response['status'] ?? substr($type, 9);
                $this->researchStream['incomplete_reason'] = $response['incomplete_details']['reason'] ?? null;
                $this->researchStream['provider_error'] = $response['error'] ?? null;
                $this->researchStream['usage'] = isset($response['usage']) ? $this->extractUsage($response)->toArray() : null;
                $this->researchStream['response_id'] = $response['id'] ?? $this->researchStream['response_id'];
                $texts = [];
                foreach ($response['output'] ?? [] as $item) {
                    if (($item['type'] ?? '') === 'message') {
                        // Multiple assistant messages may include commentary. Only
                        // the final message is the structured answer, not their concatenation.
                        $messageTexts = [];
                        foreach ($item['content'] ?? [] as $content) {
                            if (($content['type'] ?? '') === 'output_text') {
                                $messageTexts[] = (string) ($content['text'] ?? '');
                            }
                            if (($content['type'] ?? '') === 'refusal') {
                                $this->researchStream['refusal'] = $content['refusal'] ?? '';
                            }
                        }
                        if ($messageTexts !== []) {
                            $texts = $messageTexts;
                        }
                    }
                }
                if ($texts !== []) {
                    $this->researchStream['final_text'] = implode('', $texts);
                }
            }
            yield $event;
        }
    }

    public function researchEvidence(): array
    {
        return $this->researchStream;
    }

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
