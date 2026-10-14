@php
    $assistantBusy = in_array($this->productAiStatus, ['queued', 'running'], true);
    $assistantId = 'product-ai-' . $this->getId();
    $assistantTargets = 'sendProductAiMessage,translateProductWithAi,enrichProductWithAi,cancelProductAi,discardProductAiProposal';
    $assistantVariants = $this->getProductAiVariantOptions();
@endphp

<aside
    class="product-form-assistant"
    aria-label="{{ __('product-assistant.heading') }}"
    @if ($assistantBusy) wire:poll.3s="pollProductAi" @endif
    x-data="{
        submitting: false,
        async ask(action) {
            if (this.submitting || ['queued', 'running'].includes(this.$wire.productAiStatus)) return;
            this.submitting = true;
            try {
                await this.$wire[action]();
            } finally {
                this.submitting = false;
            }
        },
    }"
>
    <x-filament::section icon="heroicon-o-sparkles" :compact="true">
        <x-slot name="heading">{{ __('product-assistant.heading') }}</x-slot>
        <x-slot name="description">{{ __('product-assistant.model', ['model' => $this->getProductAiModelLabel()]) }}</x-slot>

        <div class="product-form-assistant__body">
            <p class="product-form-assistant__hint">{{ __('product-assistant.form_only') }}</p>

            @if (count($assistantVariants) > 1)
                <div class="product-form-assistant__variant">
                    <label for="{{ $assistantId }}-variant">{{ __('product-assistant.variant') }}</label>
                    <x-filament::input.wrapper :disabled="$assistantBusy">
                        <x-filament::input.select
                            id="{{ $assistantId }}-variant"
                            wire:model="productAiVariantKey"
                            aria-describedby="{{ $assistantId }}-variant-help"
                            :disabled="$assistantBusy"
                            wire:loading.attr="disabled"
                            wire:target="{{ $assistantTargets }}"
                        >
                            <option value="">{{ __('product-assistant.default_variant') }}</option>
                            @foreach ($assistantVariants as $variantKey => $variantLabel)
                                <option value="{{ $variantKey }}">{{ $variantLabel }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p id="{{ $assistantId }}-variant-help" class="product-form-assistant__hint">{{ __('product-assistant.variant_help') }}</p>
                </div>
            @endif

            <div class="product-form-assistant__tools">
                <x-filament::button
                    type="button"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-language"
                    x-on:click="ask('translateProductWithAi')"
                    x-bind:disabled="submitting || ['queued', 'running'].includes($wire.productAiStatus)"
                    :disabled="$assistantBusy"
                    wire:loading.attr="disabled"
                    wire:target="{{ $assistantTargets }}"
                    title="{{ __('product-assistant.translate_help') }}"
                >
                    {{ __('product-assistant.translate') }}
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-globe-alt"
                    x-on:click="ask('enrichProductWithAi')"
                    x-bind:disabled="submitting || ['queued', 'running'].includes($wire.productAiStatus)"
                    :disabled="$assistantBusy"
                    wire:loading.attr="disabled"
                    wire:target="{{ $assistantTargets }}"
                    title="{{ __('product-assistant.enrich_help') }}"
                >
                    {{ __('product-assistant.enrich') }}
                </x-filament::button>
            </div>

            <div class="product-form-assistant__status" role="status" aria-live="polite">
                @if ($assistantBusy)
                    <x-filament::loading-indicator class="product-form-assistant__spinner" />
                @endif
                <span>{{ __('product-assistant.statuses.' . $this->productAiStatus) }}</span>
                @if ($assistantBusy)
                    <x-filament::button
                        type="button"
                        color="gray"
                        size="xs"
                        wire:click="cancelProductAi"
                        wire:loading.attr="disabled"
                        wire:target="{{ $assistantTargets }}"
                    >
                        {{ __('product-assistant.cancel') }}
                    </x-filament::button>
                @endif
            </div>

            <div
                class="product-form-assistant__messages"
                x-ref="messages"
                x-effect="$wire.productAiMessages.length; $nextTick(() => { $refs.messages.scrollTop = $refs.messages.scrollHeight })"
                role="log"
                aria-label="{{ __('product-assistant.conversation') }}"
                aria-live="polite"
                aria-relevant="additions text"
                aria-busy="{{ $assistantBusy ? 'true' : 'false' }}"
                tabindex="0"
            >
                @forelse ($this->productAiMessages as $messageIndex => $message)
                    @php
                        $messageRole = in_array($message['role'] ?? '', ['user', 'assistant', 'system'], true)
                            ? $message['role']
                            : 'assistant';
                    @endphp
                    <article
                        class="product-form-assistant__message product-form-assistant__message--{{ $messageRole }}"
                        wire:key="{{ $assistantId }}-message-{{ $messageIndex }}"
                    >
                        <p class="product-form-assistant__role">{{ __('product-assistant.roles.' . $messageRole) }}</p>
                        @if (array_key_exists('changed', $message))
                            <p class="product-form-assistant__result">
                                {{ (int) $message['changed'] > 0
                                    ? __('product-assistant.applied_count', ['count' => (int) $message['changed']])
                                    : __('product-assistant.no_changes') }}
                            </p>
                        @endif
                        <p class="product-form-assistant__content">{{ $message['content'] ?? '' }}</p>

                        @if (! empty($message['conflicts']))
                            <div class="product-form-assistant__conflicts">
                                <p>{{ __('product-assistant.conflicts') }}</p>
                                <p class="product-form-assistant__conflict-notice">{{ __('product-assistant.conflict_notice') }}</p>
                                <ul>
                                    @foreach ($message['conflicts'] as $conflict)
                                        <li>{{ $conflict }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (! empty($message['sources']))
                            <div class="product-form-assistant__sources">
                                <p>{{ __('product-assistant.sources') }}</p>
                                <ul>
                                    @foreach ($message['sources'] as $source)
                                        @php
                                            $sourceUrl = is_string($source['url'] ?? null) ? $source['url'] : '';
                                            $sourceScheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
                                            $sourceHost = parse_url($sourceUrl, PHP_URL_HOST);
                                        @endphp
                                        @if (in_array($sourceScheme, ['http', 'https'], true) && filled($sourceHost))
                                            <li>
                                                <a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">
                                                    {{ filled($source['title'] ?? null) ? $source['title'] : $sourceHost }}
                                                    <span aria-hidden="true">↗</span>
                                                </a>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="product-form-assistant__empty">
                        <p class="product-form-assistant__empty-title">{{ __('product-assistant.empty_title') }}</p>
                        <p>{{ __('product-assistant.empty_help') }}</p>
                    </div>
                @endforelse
            </div>

            <div class="product-form-assistant__composer">
                <label for="{{ $assistantId }}-message">{{ __('product-assistant.message_label') }}</label>
                <textarea
                    id="{{ $assistantId }}-message"
                    class="product-form-assistant__input"
                    rows="3"
                    maxlength="6000"
                    wire:model="productAiMessage"
                    x-on:keydown.ctrl.enter.prevent="ask('sendProductAiMessage')"
                    x-on:keydown.meta.enter.prevent="ask('sendProductAiMessage')"
                    placeholder="{{ __('product-assistant.message_placeholder') }}"
                    aria-describedby="{{ $assistantId }}-message-help"
                    @disabled($assistantBusy)
                    wire:loading.attr="disabled"
                    wire:target="{{ $assistantTargets }}"
                ></textarea>
                @error('productAiMessage')
                    <p class="product-form-assistant__error" role="alert">{{ $message }}</p>
                @enderror
                <div class="product-form-assistant__composer-footer">
                    <span id="{{ $assistantId }}-message-help" class="product-form-assistant__hint">{{ __('product-assistant.keyboard_hint') }}</span>
                    <x-filament::button
                        type="button"
                        size="sm"
                        icon="heroicon-o-paper-airplane"
                        x-on:click="ask('sendProductAiMessage')"
                        x-bind:disabled="submitting || ['queued', 'running'].includes($wire.productAiStatus) || !($wire.productAiMessage || '').trim()"
                        :disabled="$assistantBusy"
                        wire:loading.attr="disabled"
                        wire:target="{{ $assistantTargets }}"
                    >
                        {{ __('product-assistant.send') }}
                    </x-filament::button>
                </div>
            </div>

            @if ($this->productAiPendingCatalog !== [])
                <div class="product-form-assistant__catalog">
                    <h3>{{ __('product-assistant.pending_catalog') }}</h3>
                    <p class="product-form-assistant__hint">{{ __('product-assistant.pending_catalog_help') }}</p>
                    <ul>
                        @foreach ($this->productAiPendingCatalog as $proposalKey => $proposal)
                            <li wire:key="{{ $assistantId }}-proposal-{{ $proposalKey }}">
                                <div>
                                    <span class="product-form-assistant__proposal-kind">{{ __('product-assistant.kinds.' . ($proposal['kind'] ?? 'attribute')) }}</span>
                                    <span class="product-form-assistant__proposal-label">{{ $proposal['label'] ?? '' }}</span>
                                    @if (isset($proposal['value']) && is_scalar($proposal['value']))
                                        <span class="product-form-assistant__hint">{{ $proposal['value'] }} {{ is_scalar($proposal['unit'] ?? null) ? $proposal['unit'] : '' }}</span>
                                    @endif
                                    @php
                                        $proposedGroup = is_array($proposal['group'] ?? null) ? $proposal['group'] : [];
                                        $proposedGroupName = app()->getLocale() === 'cs' && filled($proposedGroup['name_cs'] ?? null)
                                            ? $proposedGroup['name_cs']
                                            : ($proposedGroup['name'] ?? null);
                                    @endphp
                                    @if (($proposal['kind'] ?? null) === 'attribute' && is_string($proposedGroupName) && filled($proposedGroupName))
                                        <span class="product-form-assistant__hint">{{ __('product-assistant.group', ['name' => $proposedGroupName]) }}</span>
                                    @endif
                                </div>
                                <x-filament::button
                                    type="button"
                                    color="gray"
                                    size="xs"
                                    :disabled="$assistantBusy"
                                    :wire:click="'discardProductAiProposal(' . \Illuminate\Support\Js::from((string) $proposalKey)->toHtml() . ')'"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $assistantTargets }}"
                                    aria-label="{{ __('product-assistant.discard_named', ['name' => $proposal['label'] ?? '']) }}"
                                >
                                    {{ __('product-assistant.discard') }}
                                </x-filament::button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-filament::section>

    <style>
        .product-form-assistant { min-width: 0; color: var(--gray-950); }
        .product-form-assistant__body { display: flex; flex-direction: column; gap: 1rem; }
        .product-form-assistant__variant { display: grid; gap: .375rem; }
        .product-form-assistant__variant > label { font-size: .8125rem; font-weight: 600; }
        .product-form-assistant__hint { color: var(--gray-500); font-size: .75rem; line-height: 1.5; }
        .product-form-assistant__tools { display: flex; flex-wrap: wrap; gap: .5rem; }
        .product-form-assistant__tools > button { flex: 1 1 auto; }
        .product-form-assistant__status { display: flex; align-items: center; gap: .5rem; min-height: 1.5rem; color: var(--gray-500); font-size: .75rem; }
        .product-form-assistant__status > button { margin-inline-start: auto; }
        .product-form-assistant__spinner { width: 1rem; height: 1rem; flex: none; }
        .product-form-assistant__messages { display: flex; flex-direction: column; gap: .75rem; min-height: 8rem; max-height: 26rem; max-height: min(42dvh, 26rem); overflow-y: auto; overscroll-behavior: contain; scrollbar-gutter: stable; padding: .125rem .375rem .125rem .125rem; }
        .product-form-assistant__message { padding: .75rem; border-radius: .75rem; background: var(--gray-50); overflow-wrap: anywhere; font-size: .8125rem; line-height: 1.6; }
        .product-form-assistant__message--user { margin-inline-start: 1rem; background: var(--gray-100); }
        .product-form-assistant__message--system { background: transparent; border-inline-start: 2px solid var(--gray-200); border-radius: 0; }
        .product-form-assistant__role { font-size: .6875rem; font-weight: 600; color: var(--gray-500); margin-bottom: .25rem; }
        .product-form-assistant__content { white-space: pre-wrap; }
        .product-form-assistant__result { margin-bottom: .375rem; font-size: .75rem; font-weight: 600; }
        .product-form-assistant__sources, .product-form-assistant__conflicts { margin-top: .75rem; font-size: .75rem; }
        .product-form-assistant__sources > p, .product-form-assistant__conflicts > p { font-weight: 600; }
        .product-form-assistant__conflicts > .product-form-assistant__conflict-notice { margin-top: .25rem; font-weight: 400; }
        .product-form-assistant__sources ul, .product-form-assistant__conflicts ul { display: grid; gap: .25rem; margin-top: .25rem; padding-inline-start: 1rem; list-style: disc; }
        .product-form-assistant__sources a { color: var(--primary-600); text-decoration: underline; text-underline-offset: 2px; }
        .product-form-assistant__conflicts { padding: .625rem; background: var(--warning-50); color: var(--warning-800); border-radius: .5rem; }
        .product-form-assistant__empty { margin: auto 0; padding: .75rem; color: var(--gray-500); font-size: .8125rem; line-height: 1.6; }
        .product-form-assistant__empty-title { color: var(--gray-700); font-weight: 500; margin-bottom: .25rem; }
        .product-form-assistant__composer { display: grid; gap: .5rem; }
        .product-form-assistant__composer > label, .product-form-assistant__catalog h3 { font-size: .8125rem; font-weight: 600; }
        .product-form-assistant__input { display: block; width: 100%; min-height: 6rem; max-height: 15rem; resize: vertical; padding: .625rem .75rem; border: 1px solid var(--gray-300); border-radius: .5rem; background: white; color: var(--gray-950); font: inherit; font-size: .875rem; line-height: 1.5; box-shadow: 0 1px 2px rgb(0 0 0 / .03); }
        .product-form-assistant__input::placeholder { color: var(--gray-400); }
        .product-form-assistant__input:disabled { opacity: .65; }
        .product-form-assistant__input:focus-visible, .product-form-assistant__messages:focus-visible { outline: 2px solid var(--primary-500); outline-offset: 2px; }
        .product-form-assistant__composer-footer { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
        .product-form-assistant__error { color: var(--danger-600); font-size: .75rem; }
        .product-form-assistant__catalog { display: grid; gap: .375rem; padding-top: 1rem; border-top: 1px solid var(--gray-200); }
        .product-form-assistant__catalog > ul { display: grid; gap: .5rem; max-height: 16rem; overflow-y: auto; }
        .product-form-assistant__catalog li { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding-block: .375rem; }
        .product-form-assistant__catalog li > div { display: grid; min-width: 0; overflow-wrap: anywhere; }
        .product-form-assistant__catalog li > button { flex: none; }
        .product-form-assistant__proposal-kind { color: var(--gray-500); font-size: .6875rem; }
        .product-form-assistant__proposal-label { font-size: .8125rem; }
        .dark .product-form-assistant { color: var(--gray-100); }
        .dark .product-form-assistant__hint, .dark .product-form-assistant__status, .dark .product-form-assistant__role, .dark .product-form-assistant__empty, .dark .product-form-assistant__proposal-kind { color: var(--gray-400); }
        .dark .product-form-assistant__message { background: rgb(255 255 255 / .04); }
        .dark .product-form-assistant__message--user { background: rgb(255 255 255 / .08); }
        .dark .product-form-assistant__message--system { background: transparent; border-color: var(--gray-700); }
        .dark .product-form-assistant__empty-title { color: var(--gray-200); }
        .dark .product-form-assistant__sources a { color: var(--primary-400); }
        .dark .product-form-assistant__conflicts { background: rgb(245 158 11 / .1); color: var(--warning-300); }
        .dark .product-form-assistant__input { background: rgb(255 255 255 / .05); color: white; border-color: var(--gray-700); }
        .dark .product-form-assistant__catalog { border-color: var(--gray-700); }
        .dark .product-form-assistant__error { color: var(--danger-400); }
        @media (max-width: 639px) {
            .product-form-assistant__messages { max-height: 22rem; }
            .product-form-assistant__input { font-size: 1rem; }
            .product-form-assistant__tools > button { min-height: 2.75rem; }
        }
    </style>
</aside>
