<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($checks as $check)
            @php
                $tone = match ($check['state']) {
                    'up' => ['ring-success-600/20', 'text-success-600 dark:text-success-400', 'heroicon-m-check-circle', 'Работает'],
                    'down' => ['ring-danger-600/20', 'text-danger-600 dark:text-danger-400', 'heroicon-m-exclamation-triangle', 'Не работает'],
                    default => ['ring-gray-400/20', 'text-gray-500 dark:text-gray-400', 'heroicon-m-question-mark-circle', 'Неизвестно'],
                };
            @endphp

            <div @class([
                'rounded-xl bg-white p-5 shadow-sm ring-1 dark:bg-gray-900',
                $tone[0],
            ])>
                <div class="flex items-center gap-2">
                    <x-filament::icon :icon="$tone[2]" @class(['h-5 w-5', $tone[1]]) />
                    <span @class(['text-sm font-semibold', $tone[1]])>{{ $tone[3] }}</span>
                </div>

                <p class="mt-2 text-base font-semibold text-gray-950 dark:text-white">
                    {{ $check['label'] }}
                </p>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ $check['detail'] }}
                </p>

                @if ($check['hint'] !== '')
                    <p class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        {{ $check['hint'] }}
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    @if (filled($smokeOutput))
        <div class="mt-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-base font-semibold text-gray-950 dark:text-white">Результат полной проверки</p>

            <pre class="mt-3 overflow-x-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-4 text-xs leading-relaxed text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ $smokeOutput }}</pre>
        </div>
    @endif
</x-filament-panels::page>
