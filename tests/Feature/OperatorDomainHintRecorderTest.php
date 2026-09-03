<?php

namespace Tests\Feature;

use App\Models\ProductSourceDomain;
use App\Services\Products\OperatorDomainHintRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorDomainHintRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_telegram_hint_becomes_persistent_domain_knowledge(): void
    {
        // Real gap: the hint typed after "переобучить с подсказкой" reached one
        // training run as a one-off operator_hint and then evaporated, so the
        // next search on the same domain started blind again.
        app(OperatorDomainHintRecorder::class)->remember(
            'https://www.bhphotovideo.com/c/product/1218008-REG/dell.html',
            'Нажми на само фото в открытом viewer, а не на миниатюру.',
        );

        // ProductSourceDomain is keyed by the www-stripped host; storing it any
        // other way creates a row the trainer never reads.
        $settings = ProductSourceDomain::query()->where('domain', 'bhphotovideo.com')->firstOrFail();
        $this->assertStringContainsString('Нажми на само фото', (string) $settings->agent_hint);
    }

    public function test_a_repeated_hint_does_not_stack_duplicate_lines(): void
    {
        $recorder = app(OperatorDomainHintRecorder::class);
        $recorder->remember('https://example.com/p/1', 'Открой viewer и увеличь кадр.');
        $recorder->remember('https://example.com/p/2', 'Открой viewer и увеличь кадр.');

        $settings = ProductSourceDomain::query()->where('domain', 'example.com')->firstOrFail();
        $this->assertSame(1, substr_count((string) $settings->agent_hint, 'Открой viewer и увеличь кадр.'));
    }

    public function test_hand_written_filament_text_is_kept_and_never_pruned(): void
    {
        ProductSourceDomain::query()->create([
            'domain' => 'example.com',
            'agent_hint' => 'Ручная заметка оператора из Filament.',
        ]);
        $recorder = app(OperatorDomainHintRecorder::class);

        // Well past the recorder's own cap: only its own marked lines rotate.
        foreach (range(1, 12) as $index) {
            $recorder->remember('https://example.com/p/'.$index, 'Подсказка номер '.$index);
        }

        $hint = (string) ProductSourceDomain::query()->where('domain', 'example.com')->firstOrFail()->agent_hint;
        $this->assertStringContainsString('Ручная заметка оператора из Filament.', $hint);
        $this->assertStringContainsString('Подсказка номер 12', $hint);
        $this->assertStringNotContainsString('Подсказка номер 1 ', $hint.' ');
    }

    public function test_an_empty_hint_creates_nothing(): void
    {
        $this->assertNull(app(OperatorDomainHintRecorder::class)->remember('https://example.com/p/1', '   '));
        $this->assertSame(0, ProductSourceDomain::query()->count());
    }
}
