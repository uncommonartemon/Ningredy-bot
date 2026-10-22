<?php

namespace Tests\Feature;

use App\Models\TelegramUpdate;
use App\Services\BotHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BotStatusReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_health_checks_and_the_request_being_worked_on_as_json(): void
    {
        $check = ['key' => 'worker', 'label' => 'Обработчик задач', 'state' => 'up', 'detail' => 'Очередь пуста.', 'hint' => ''];
        $this->mock(BotHealth::class)->shouldReceive('checks')->once()->andReturn([$check]);
        TelegramUpdate::query()->create(['update_id' => 1, 'payload' => [], 'status' => 'completed', 'text' => 'старый', 'processed_at' => now()]);
        $active = TelegramUpdate::query()->create(['update_id' => 2, 'payload' => [], 'status' => 'processing', 'text' => 'Acer Helios 16']);

        Artisan::call('bot:status');
        $report = json_decode(Artisan::output(), true);

        $this->assertSame([$check], $report['checks']);
        $this->assertSame(['id' => $active->id, 'text' => 'Acer Helios 16', 'minutes' => 0], $report['active']);
    }

    public function test_an_idle_bot_has_no_active_request(): void
    {
        $this->mock(BotHealth::class)->shouldReceive('checks')->once()->andReturn([]);

        Artisan::call('bot:status');

        $this->assertNull(json_decode(Artisan::output(), true)['active']);
    }
}
