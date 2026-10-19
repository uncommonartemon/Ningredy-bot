<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductPhotoApplicabilityAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** An auditable photo-only verdict, bound to the target and observed source. */
class ProductPhotoApplicability
{
    private const array PHYSICAL_CHECKS = ['platform', 'revision', 'construction', 'dimensions', 'ports', 'color'];

    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchTimeBudget $timeBudget,
    ) {}

    public function assess(ProductDraft $draft, array $source, ?int $updateId, bool $isRetry = false): array
    {
        $updateId ??= $draft->telegram_update_id;
        app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
        $unavailable = fn (string $reason): array => self::bind($draft, $source, [
            'verdict' => 'unavailable', 'reason' => $reason, 'source_model' => '',
            'differences' => [], 'evidence' => [], 'may_inspect_gallery' => false,
            'telegram_update_id' => $updateId,
        ]);
        if (! $this->settings->sourceIdentityAgentEnabled()) {
            return $unavailable('Оценка применимости фотографий отключена.');
        }

        $context = ProductRequestContext::forDraft($draft)->forAgent();
        $observed = self::sourceEvidence($source);
        if (trim((string) $draft->model) === '' && empty($context['selected_product']['identifiers'])) {
            return $unavailable('Нет модели или идентификаторов проверяемого товара.');
        }
        if ($observed['url'] === '' || ($observed['title'] === '' && $observed['text'] === '')) {
            return $unavailable('Нет наблюдений о товаре на странице фотоисточника.');
        }

        $payload = [
            'assessment_version' => 1,
            'verification_stage' => 'photo_applicability',
            'product_draft_id' => $draft->id,
            'request_context' => $context,
            'source_evidence' => $observed,
            'target_fingerprint' => self::targetFingerprint($draft),
            'source_fingerprint' => self::sourceFingerprint($source),
        ];
        $prompt = self::json($payload);
        $provider = $this->settings->providerFor('product_source_identity');
        $model = $this->settings->modelFor('product_source_identity');
        $cached = $updateId ? AiRun::query()->where('telegram_update_id', $updateId)
            ->where('provider', $provider)->where('model', $model)->where('prompt', $prompt)
            ->where('status', 'completed')->latest('id')->first() : null;
        if ($cached && is_array($cached->response) && self::isBoundTo($draft, $source, $cached->response)) {
            return [...$cached->response, 'ai_run_id' => $cached->id, 'cache_hit' => true];
        }
        if (! $this->timeBudget->canStart($updateId, 10) || app(ProductSearchCostBudget::class)->exceeded($updateId)) {
            return $unavailable('Недостаточно оставшегося бюджета для оценки применимости фотографий.');
        }

        $timeout = $this->timeBudget->timeoutFor($updateId, (int) config('services.product_source_identity.timeout', 20));
        $run = $updateId ? AiRun::query()->create([
            'telegram_update_id' => $updateId, 'provider' => $provider, 'model' => $model,
            'status' => 'running', 'prompt' => $prompt, 'started_at' => now(),
            'activity' => ['stage' => 'photo_applicability', 'search_budget' => true,
                'product_draft_id' => $draft->id, 'source_url' => $observed['url']],
        ]) : null;
        try {
            $response = app(OpenAiHeavyOperationGate::class)->run($provider, $timeout,
                function () use ($prompt, $provider, $model, $timeout, $updateId) {
                    app(ProductSearchCancellation::class)->throwIfCancelled($updateId);

                    return ProductPhotoApplicabilityAgent::make()->prompt($prompt,
                        provider: $provider, model: $model, timeout: $timeout);
                });
            // Invalid structured output is still a paid call: retain usage before validation.
            $run?->update(['invocation_id' => $response->invocationId,
                'response' => $response->toArray(), 'usage' => $response->usage->toArray()]);
            app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
            $data = $this->validate($response->toArray());
            $result = self::bind($draft, $source, [
                ...$this->guardVerdict($data, $draft), 'telegram_update_id' => $updateId,
                'ai_run_id' => $run?->id, 'cache_hit' => false,
                'assessment_scope' => 'source_metadata_not_pixels',
            ]);
            $run?->update(['status' => 'completed', 'response' => $result, 'completed_at' => now()]);

            return $result;
        } catch (ProductSearchCancelled $exception) {
            $run?->update(['status' => 'cancelled', 'error' => $exception->getMessage(), 'completed_at' => now()]);
            throw $exception;
        } catch (Throwable $exception) {
            $run?->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            report($exception);
            // One timeout or malformed answer is not a verdict (live #443 lost a good gallery to two).
            if (! $isRetry && $this->timeBudget->canStart($updateId, 10)) {
                return $this->assess($draft, $source, $updateId, isRetry: true);
            }

            return [...$unavailable('Технический сбой оценки применимости фотографий; совпадение не подтверждено.'),
                'ai_run_id' => $run?->id, 'technical_failure' => true];
        }
    }

    public static function bind(ProductDraft $draft, array $source, array $assessment): array
    {
        $physicalKeys = self::physicalKeys($draft);

        return [...$assessment, 'product_draft_id' => $draft->id,
            'target_physical_keys' => $physicalKeys,
            'target_fingerprint' => self::targetFingerprint($draft, $physicalKeys),
            'source_fingerprint' => self::sourceFingerprint($source),
            'source_url' => trim((string) ($source['url'] ?? ''))];
    }

    public static function targetFingerprint(ProductDraft $draft, ?array $physicalKeys = null): string
    {
        $context = ProductRequestContext::forDraft($draft)->forAgent();
        $physicalKeys ??= self::physicalKeys($draft);
        $physical = collect($draft->specifications ?? [])->filter(fn (mixed $spec): bool => is_array($spec)
            && in_array(mb_strtolower((string) ($spec['key'] ?? '')), $physicalKeys, true))
            ->map(fn (array $spec): array => ['key' => mb_strtolower((string) ($spec['key'] ?? '')),
                'value' => (string) ($spec['value'] ?? ''), 'unit' => (string) ($spec['unit'] ?? '')])
            ->sortBy('key')->values()->all();

        // Text repair may add RAM/clock/SSD or previously unknown physical
        // facts. It must not invalidate checked pictures just by enrichment.
        // Known appearance facts, original input and selected model stay bound.
        $normalize = fn (mixed $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));

        return hash('sha256', self::json(['draft_id' => $draft->id,
            'brand' => $normalize($draft->brand), 'model' => $normalize($draft->model), 'color' => $normalize($draft->color),
            'physical_configuration' => $physical,
            'original_operator_request' => $context['original_operator_request'],
            'input_evidence' => $context['input_evidence'] ?? [],
        ]));
    }

    public static function matchesTarget(ProductDraft $draft, array $assessment): bool
    {
        $keys = $assessment['target_physical_keys'] ?? null;

        return ($assessment['product_draft_id'] ?? null) === $draft->id
            && is_string($assessment['target_fingerprint'] ?? null)
            && $assessment['target_fingerprint'] === self::targetFingerprint($draft, is_array($keys) ? $keys : null);
    }

    private static function physicalKeys(ProductDraft $draft): array
    {
        return collect($draft->specifications ?? [])->filter(function (mixed $spec): bool {
            if (! is_array($spec)) {
                return false;
            }
            $key = mb_strtolower((string) ($spec['key'] ?? ''));

            return preg_match('/(?:^|_)(?:chassis|body|revision|generation|form_factor|construction|dimensions|width|height|depth|diagonal|screen_size|display_size|ports?|connectors?|keyboard|layout|color|colour)(?:_|$)/u', $key) === 1;
        })->map(fn (array $spec): string => mb_strtolower((string) $spec['key']))->unique()->sort()->values()->all();
    }

    public static function sourceFingerprint(array $source): string
    {
        return hash('sha256', self::json(self::sourceEvidence($source)));
    }

    public static function isAccepted(ProductDraft $draft, array $source, array $assessment): bool
    {
        return in_array($assessment['verdict'] ?? null, ['exact', 'compatible'], true)
            && self::isBoundTo($draft, $source, $assessment);
    }

    private static function isBoundTo(ProductDraft $draft, array $source, array $assessment): bool
    {
        return self::matchesTarget($draft, $assessment)
            && ($assessment['source_url'] ?? null) === trim((string) ($source['url'] ?? ''))
            && ($assessment['source_fingerprint'] ?? null) === self::sourceFingerprint($source);
    }

    private function validate(array $data): array
    {
        $rules = [
            'verdict' => ['required', 'in:exact,compatible,conflicting,uncertain'],
            'reason' => ['required', 'string', 'max:1000'],
            'source_model' => ['present', 'string', 'max:300'],
            'differences' => ['present', 'array'], 'differences.*' => ['string', 'max:600'],
            'evidence' => ['present', 'array'], 'evidence.*' => ['string', 'max:600'],
            'physical_checks' => ['required', 'array'],
            'plausible_target' => ['required', 'boolean'],
            'may_inspect_gallery' => ['required', 'boolean'],
        ];
        foreach (self::PHYSICAL_CHECKS as $key) {
            $rules['physical_checks.'.$key.'.status'] = ['required', 'in:match,conflicting,unknown'];
            $rules['physical_checks.'.$key.'.evidence'] = ['present', 'string', 'max:600'];
        }

        return Validator::make($data, $rules)->validate();
    }

    private function guardVerdict(array $data, ProductDraft $draft): array
    {
        if (trim((string) $draft->color) === '' && $data['physical_checks']['color']['status'] === 'match') {
            $data['physical_checks']['color'] = ['status' => 'unknown',
                'evidence' => 'Цвет выбранного товара ещё не установлен; совпадение цвета не доказано.'];
        }
        $checks = collect($data['physical_checks']);
        $physicalConflict = $checks->contains(fn (array $check): bool => $check['status'] === 'conflicting');
        $positive = fn (array $check): bool => $check['status'] === 'match' && trim($check['evidence']) !== '';
        if ($physicalConflict) {
            $data['verdict'] = 'conflicting';
        } elseif (in_array($data['verdict'], ['exact', 'compatible'], true)) {
            $evidencePresent = collect($data['evidence'])->contains(fn (string $item): bool => trim($item) !== '');
            $allPhysicalConfirmed = $checks->every($positive);
            $colorConfirmed = $positive($data['physical_checks']['color']);
            if (! $data['plausible_target'] || ! $evidencePresent || trim($data['source_model']) === ''
                || ! $colorConfirmed || ($data['verdict'] === 'compatible' && ! $allPhysicalConfirmed)) {
                $data['verdict'] = 'uncertain';
                $data['reason'] = mb_substr($data['reason'].' Не хватает положительных доказательств применимости фото; требуется дополнительная проверка.', 0, 1000);
            }
        }
        $data['may_inspect_gallery'] = $data['verdict'] !== 'conflicting'
            && $data['plausible_target'] && (bool) $data['may_inspect_gallery'];

        return $data;
    }

    private static function sourceEvidence(array $source): array
    {
        $observedSource = $source['_observed_identity']['source'] ?? [];
        $observedSource = is_array($observedSource) ? $observedSource : [];
        $url = trim((string) ($source['url'] ?? ''));
        $observedUrl = trim((string) ($observedSource['url'] ?? ''));
        $samePage = $observedUrl === '' || $observedUrl === $url;
        // Browser observation can be attached after the first assessment. Its
        // duplicate copy is not new evidence; a genuinely changed page is.
        $effective = $source;
        $differences = [];
        foreach (['title', '_preflight_identity_evidence'] as $key) {
            $current = trim((string) ($source[$key] ?? ''));
            $observed = trim((string) ($observedSource[$key] ?? ''));
            if ($samePage && ! array_key_exists($key, $source)) {
                $effective[$key] = $observed;
            } elseif ($observed !== '' && $current !== $observed) {
                // Keep both observations rather than letting an old attached
                // verdict hide a changed top-level runtime page, or vice versa.
                $differences[$key] = $observed;
            }
        }

        return [
            'url' => $url,
            'title' => trim((string) ($effective['title'] ?? '')),
            'text' => trim((string) ($effective['_preflight_identity_evidence'] ?? '')),
            'conflicting_observed_url' => $samePage ? null : $observedUrl,
            'different_attached_observation' => $differences,
        ];
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
