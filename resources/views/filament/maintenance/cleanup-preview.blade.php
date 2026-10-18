<div style="display: grid; gap: 1rem; font-size: .875rem">
    @if (! $plan)
        <p>{{ __('Подтверждение устарело. Закройте окно и откройте очистку заново.') }}</p>
    @else
        @if ($plan['blockers'])
            <x-filament::section :heading="__('Сначала завершите работу')">
                <ul style="list-style: disc; padding-inline-start: 1.25rem">
                    @foreach ($plan['blockers'] as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('Будет удалено')">
            <dl>
                @foreach ($plan['counts'] as $table => $count)
                    <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; padding-block: .25rem">
                        <dt>{{ __($plan['labels'][$table]) }}</dt>
                        <dd style="font-weight: 600; font-variant-numeric: tabular-nums">{{ number_format($count, 0, '.', ' ') }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        @if ($plan['effects']['ungrouped_attributes'] ?? 0)
            <p>{{ __('Характеристики сохранятся без группы: :count.', ['count' => $plan['effects']['ungrouped_attributes']]) }}</p>
        @endif
        @if ($plan['effects']['draft_catalog_links_removed'] ?? 0)
            <p>{{ __('У сохранённых черновиков будут убраны ссылки на удалённые товары: :count.', ['count' => $plan['effects']['draft_catalog_links_removed']]) }}</p>
        @endif

        <x-filament::section :heading="__('Сохраняется')">
            <ul style="list-style: disc; padding-inline-start: 1.25rem">
                @foreach ($plan['preserved'] as $item)
                    <li>{{ __($item) }}</li>
                @endforeach
                @if (! $plan['options']['dictionaries'] && ! in_array($plan['preset'], ['attributes', 'attribute_groups'], true))
                    <li>{{ __('Определения и группы характеристик, категории и бренды') }}</li>
                @endif
                @if ($plan['preset'] === 'attributes')
                    <li>{{ __('Группы характеристик, категории, бренды и сами товары') }}</li>
                @elseif ($plan['preset'] === 'attribute_groups')
                    <li>{{ __('Определения и значения характеристик, категории, бренды и товары') }}</li>
                @endif
                @if ($plan['preset'] === 'catalog')
                    <li>{{ __('Черновики и их фото') }}</li>
                @elseif ($plan['preset'] === 'drafts')
                    <li>{{ __('Каталог и его фото') }}</li>
                @endif
                @if (! $plan['options']['recipes_history'])
                    <li>{{ __('Рецепты галерей, их версии и статистика источников') }}</li>
                @endif
            </ul>
        </x-filament::section>
        <p style="opacity: .75">{{ __('Подтверждение действует 15 минут и относится только к этому снимку данных. Файлы удаляются фоновым обработчиком после сохранения изменений в базе, если больше нигде не используются.') }}</p>
    @endif
</div>
