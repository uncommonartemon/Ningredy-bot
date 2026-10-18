<?php

namespace Tests\Unit;

use App\Services\Ai\DatabaseOperatorIntentDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatabaseOperatorIntentDetectorTest extends TestCase
{
    #[DataProvider('requests')]
    public function test_it_requires_database_tools_for_operator_requests(string $text, bool $expected): void
    {
        $this->assertSame($expected, (new DatabaseOperatorIntentDetector)->matches($text));
    }

    public static function requests(): array
    {
        return [
            ['удали все черновики', true],
            ['Создай группу ОЗУ и привяжи к ней характеристики от 5 до 10', true],
            ['Поменяй название товара с SKU ABC-123', true],
            ['Покажи все атрибуты', true],
            ['Assign attributes 5 to 10 to the RAM group', true],
            ['Додай групу характеристик', true],
            ['Почему удалились все черновики?', false],
            ['Как изменить таблицу характеристик?', false],
            ['Lenovo LOQ 15IRX10 83JE0013US', false],
            ['Привет', false],
        ];
    }
}
