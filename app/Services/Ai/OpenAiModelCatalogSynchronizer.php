<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class OpenAiModelCatalogSynchronizer
{
    /**
     * @return array{available: int, unavailable: int, prices_updated: int, errors: array<string, string>}
     */
    public function sync(): array
    {
        if (! Schema::hasTable('ai_models')) {
            throw new RuntimeException('Таблица ai_models отсутствует. Сначала выполните миграции.');
        }

        $apiKey = (string) config('ai.providers.openai.key');

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY не задан.');
        }

        $baseUrl = rtrim((string) config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/');
        $modelsResponse = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(30)
            ->get($baseUrl.'/models')
            ->throw();
        $availableIds = collect($modelsResponse->json('data', []))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->all();
        $records = AiModel::query()->where('provider', 'openai')->orderBy('id')->get();

        $priceResponses = Http::pool(function (Pool $pool) use ($records): array {
            $requests = [];

            foreach ($records as $record) {
                $requests[] = $pool
                    ->as((string) $record->id)
                    ->withHeaders(['User-Agent' => 'Ningredy AI catalog checker'])
                    ->timeout(30)
                    ->get($record->source_url);
            }

            return $requests;
        });

        $result = [
            'available' => 0,
            'unavailable' => 0,
            'prices_updated' => 0,
            'errors' => [],
        ];

        foreach ($records as $record) {
            $record->is_available = in_array($record->model, $availableIds, true);
            $result[$record->is_available ? 'available' : 'unavailable']++;

            try {
                $response = $priceResponses[(string) $record->id] ?? null;

                if (! $response instanceof Response) {
                    throw new RuntimeException('Официальная страница не ответила.');
                }

                $response->throw();
                $prices = $this->extractStandardTokenPrices($response->body());
                $record->fill($prices);
                $record->pricing_checked_at = now()->toDateString();
                $result['prices_updated']++;
            } catch (Throwable $exception) {
                $result['errors'][$record->model] = $exception->getMessage();
            }

            $record->save();
        }

        return $result;
    }

    /** @return array{input_per_million: float, cached_input_per_million: float, output_per_million: float} */
    private function extractStandardTokenPrices(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML($html)) {
                throw new RuntimeException('Не удалось прочитать HTML страницы.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);

        return [
            'input_per_million' => $this->priceAfterLabel($xpath, 'Input'),
            'cached_input_per_million' => $this->priceAfterLabel($xpath, 'Cached input'),
            'output_per_million' => $this->priceAfterLabel($xpath, 'Output'),
        ];
    }

    private function priceAfterLabel(DOMXPath $xpath, string $label): float
    {
        $query = sprintf(
            "//div[normalize-space(text())='%s']/following-sibling::div[1]",
            $label,
        );
        $nodes = $xpath->query($query);

        if ($nodes === false) {
            throw new RuntimeException("Не найден тариф {$label}.");
        }

        foreach ($nodes as $node) {
            $text = trim($node->textContent);

            if (preg_match('/\\$([0-9]+(?:\\.[0-9]+)?)/', $text, $match) === 1) {
                return (float) $match[1];
            }
        }

        throw new RuntimeException("Не найден тариф {$label}.");
    }
}
