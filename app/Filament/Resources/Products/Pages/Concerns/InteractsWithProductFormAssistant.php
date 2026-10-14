<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Product;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductFormAssistant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait InteractsWithProductFormAssistant
{
    public string $productAiMessage = '';

    public string $productAiVariantKey = '';

    #[Locked]
    public ?int $productAiRunId = null;

    #[Locked]
    public ?string $productAiSession = null;

    #[Locked]
    public string $productAiStatus = 'idle';

    #[Locked]
    public array $productAiMessages = [];

    #[Locked]
    public array $productAiPendingCatalog = [];

    private ?array $productAiBeforeMaterialize = null;

    public function mountInteractsWithProductFormAssistant(): void
    {
        $this->productAiSession = (string) Str::uuid();
    }

    public function getProductAiModelLabel(): string
    {
        return app(AiSettings::class)->modelFor('server_assistant');
    }

    public function getProductAiVariantOptions(): array
    {
        $options = [];
        foreach ($this->data['variants'] ?? [] as $key => $variant) {
            $options[$key] = ($variant['name'] ?? '') ?: (($variant['sku'] ?? '') ?: __('product-assistant.variant').' '.(count($options) + 1));
        }

        return $options;
    }

    public function sendProductAiMessage(): void
    {
        $message = trim($this->productAiMessage);
        $this->validate(['productAiMessage' => 'required|string|max:6000']);
        $this->startProductAi('chat', $message);
    }

    public function translateProductWithAi(): void
    {
        $this->startProductAi('translate', __('product-assistant.translate'));
    }

    public function enrichProductWithAi(): void
    {
        $this->startProductAi('enrich', __('product-assistant.enrich'));
    }

    private function startProductAi(string $mode, string $message): void
    {
        $this->authorizeProductAi();
        if (in_array($this->productAiStatus, ['queued', 'running'], true)) {
            return;
        }
        if ($mode === 'enrich' && blank($this->data['title'] ?? null)
            && ! collect($this->data['variants'] ?? [])->contains(fn ($variant) => filled($variant['sku'] ?? null) || filled($variant['mpn'] ?? null))) {
            throw ValidationException::withMessages(['productAiMessage' => __('product-assistant.missing_identity')]);
        }
        $this->productAiSession ??= (string) Str::uuid();
        if ($this->productAiVariantKey !== '' && ! isset($this->data['variants'][$this->productAiVariantKey])) {
            throw ValidationException::withMessages(['productAiMessage' => __('product-assistant.invalid_response')]);
        }
        $run = app(ProductFormAssistant::class)->start(auth()->user(), $this->productAiRecord(), $this->productAiSession,
            $mode, $message, $this->data ?? [], $this->productAiMessages, $this->productAiVariantKey ?: null, $this->productAiPendingCatalog);
        $this->productAiRunId = $run->id;
        $this->productAiStatus = 'queued';
        $this->resetValidation('productAiMessage');
        $this->productAiMessages[] = ['role' => 'user', 'content' => $message];
        $this->productAiMessages = array_slice($this->productAiMessages, -30);
        $this->productAiMessage = '';
    }

    public function pollProductAi(): void
    {
        $this->authorizeProductAi();
        if (! in_array($this->productAiStatus, ['queued', 'running'], true)) {
            return;
        }
        $run = $this->ownedProductAiRun();
        if (! $run) {
            return;
        }
        $clock = $run->started_at ?? $run->created_at;
        if (in_array($run->status, ['queued', 'running'], true) && $clock->lt(now()->subSeconds(ProductFormAssistant::TIMEOUT + 45))) {
            $wasQueued = $run->status === 'queued';
            AiRun::whereKey($run->id)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error' => $wasQueued ? 'Worker unavailable' : 'Response timeout', 'completed_at' => now()]);
            $run->refresh();
        }
        if ($run->status === 'completed') {
            try {
                $result = app(ProductFormAssistant::class)->apply($run, $this->data ?? [], $this->productAiPendingCatalog);
                $this->data = $result['form'];
                $this->productAiPendingCatalog = $result['pending'];
                $this->productAiMessages[] = [
                    'role' => 'assistant', 'content' => $run->response['message'],
                    'sources' => app(ProductFormAssistant::class)->normalize($run->response)['sources'],
                    'conflicts' => array_values(array_unique(array_map(fn (string $path): string => $this->productAiFieldLabel($path), $result['conflicts']))), 'changed' => $result['changed'],
                ];
                $this->resetValidation('productAiMessage');
            } catch (ValidationException) {
                $this->productAiMessages[] = ['role' => 'system', 'content' => __('product-assistant.invalid_response')];
                $this->productAiStatus = 'failed';

                return;
            }
        } elseif (in_array($run->status, ['failed', 'cancelled'], true)) {
            $key = $run->status === 'cancelled' ? 'cancelled' : ($run->error === 'Worker unavailable' ? 'missing_worker' : 'failed');
            $this->productAiMessages[] = ['role' => 'system', 'content' => __('product-assistant.'.$key)];
        }
        $this->productAiStatus = $run->status;
    }

    public function cancelProductAi(): void
    {
        $this->authorizeProductAi();
        $run = $this->ownedProductAiRun();
        if ($run && in_array($this->productAiStatus, ['queued', 'running'], true)) {
            AiRun::whereKey($run->id)->whereIn('status', ['queued', 'running', 'completed'])->update(['status' => 'cancelled', 'completed_at' => now()]);
            $this->productAiStatus = 'cancelled';
            $this->productAiMessages[] = ['role' => 'system', 'content' => __('product-assistant.cancelled')];
        }
    }

    public function discardProductAiProposal(string $key): void
    {
        $this->authorizeProductAi();
        if (! isset($this->productAiPendingCatalog[$key])) {
            return;
        }
        foreach (['brand_id', 'category_id'] as $field) {
            if (($this->data[$field] ?? null) === $key) {
                $this->data[$field] = null;
            }
        }
        foreach ($this->data['variants'] ?? [] as $variantKey => $variant) {
            foreach ($variant['attributes'] ?? [] as $rowKey => $row) {
                if (($row['attribute_definition_id'] ?? null) === $key) {
                    unset($this->data['variants'][$variantKey]['attributes'][$rowKey]);
                }
            }
        }
        unset($this->productAiPendingCatalog[$key]);
    }

    public function getProductAiOptions(string $kind): array
    {
        $options = [];
        foreach ($this->productAiPendingCatalog as $key => $proposal) {
            if ($proposal['kind'] === $kind) {
                $options[$key] = $proposal['label'].' · '.__('product-assistant.pending_catalog');
            }
        }

        return $options;
    }

    private function productAiFieldLabel(string $path): string
    {
        if (str_contains($path, '.attributes.')) {
            $parts = explode('.', $path);
            $id = $this->data['variants'][$parts[1]]['attributes'][$parts[3]]['attribute_definition_id'] ?? null;
            $label = is_numeric($id) ? AttributeDefinition::find($id)?->label : ($this->productAiPendingCatalog[$id]['label'] ?? null);

            return $label ?: __('Характеристики');
        }
        if (str_contains($path, 'translations.')) {
            return __('Переводы').' · '.(str_ends_with($path, '.description') ? __('Описание') : __('Название'));
        }

        return match (Str::afterLast($path, '.')) {
            'title' => __('Название'), 'description' => __('Описание'), 'model' => __('Модель'),
            'color' => __('Цвет'), 'category_id' => __('Категория'), 'brand_id' => __('Бренд'),
            'sources' => __('Источники'), 'price' => __('Цена'), 'name' => __('Название конфигурации'),
            'sku', 'mpn', 'gtin' => strtoupper(Str::afterLast($path, '.')),
            default => __('Конфигурация товара'),
        };
    }

    private function ownedProductAiRun(): ?AiRun
    {
        if (! $this->productAiRunId) {
            return null;
        }
        $run = AiRun::find($this->productAiRunId);
        abort_unless($run && ($run->activity['kind'] ?? null) === ProductFormAssistant::KIND
            && (int) ($run->activity['user_id'] ?? 0) === (int) auth()->id()
            && ($run->activity['session_id'] ?? null) === $this->productAiSession
            && ($run->activity['product_id'] ?? null) === $this->productAiRecord()?->id, 403);

        return $run;
    }

    private function authorizeProductAi(): void
    {
        abort_unless(auth()->user(), 403);
        app(ProductFormAssistant::class)->authorize(auth()->user(), $this->productAiRecord());
        $this->authorizeAccess();
    }

    private function productAiRecord(): ?Product
    {
        return ($this->record ?? null) instanceof Product && $this->record->exists ? $this->record : null;
    }

    protected function materializeProductAiProposals(): void
    {
        $this->authorizeProductAi();
        if ($this->productAiPendingCatalog !== []) {
            $this->productAiBeforeMaterialize = $this->data;
            $this->data = app(ProductFormAssistant::class)->materialize($this->data, $this->productAiPendingCatalog);
        }
    }

    protected function rollBackDatabaseTransaction(): void
    {
        parent::rollBackDatabaseTransaction();
        if ($this->productAiBeforeMaterialize !== null) {
            $this->data = $this->productAiBeforeMaterialize;
            $this->productAiBeforeMaterialize = null;
        }
    }

    protected function commitDatabaseTransaction(): void
    {
        parent::commitDatabaseTransaction();
        $this->productAiBeforeMaterialize = null;
        $this->productAiPendingCatalog = [];
        // A saved/create-another form is a new editing session. Late results cannot cross it.
        if ($this->productAiRunId && in_array($this->productAiStatus, ['queued', 'running'], true)) {
            AiRun::whereKey($this->productAiRunId)->whereIn('status', ['queued', 'running', 'completed'])->update(['status' => 'cancelled', 'completed_at' => now()]);
        }
        $this->productAiRunId = null;
        $this->productAiSession = null;
        $this->productAiStatus = 'idle';
        $this->productAiMessages = [];
        $this->productAiMessage = '';
        $this->productAiVariantKey = '';
    }
}
