<?php

namespace App\Services\Telegram;

use Illuminate\Support\Str;

/**
 * Shared spec-line icon lookup so a draft card and a published product card
 * pick the same emoji for the same kind of spec instead of drifting apart.
 */
class SpecificationEmoji
{
    public static function for(string $keyOrName): string
    {
        $needle = Str::lower($keyOrName);

        return match (true) {
            Str::contains($needle, ['cpu', 'processor', 'процессор']) => '🧠',
            Str::contains($needle, ['gpu', 'graphic', 'видеокарт', 'видео_карт']) => '🎮',
            Str::contains($needle, ['ram', 'memory', 'память']) => '💾',
            Str::contains($needle, ['storage', 'ssd', 'hdd', 'disk', 'накопитель', 'диск']) => '💽',
            Str::contains($needle, ['refresh_rate', 'refresh rate', 'частота обновления', 'герц']) => '🔄',
            Str::contains($needle, ['resolution', 'разрешение']) => '📐',
            Str::contains($needle, ['screen_size', 'display', 'screen', 'monitor', 'экран', 'дисплей', 'диагональ']) => '🖥',
            Str::contains($needle, ['battery', 'аккумулятор', 'батаре']) => '🔋',
            Str::contains($needle, ['camera', 'камер']) => '📷',
            Str::contains($needle, ['weight', 'вес']) => '⚖️',
            Str::contains($needle, ['size', 'dimension', 'height', 'width', 'length', 'габарит', 'размер']) => '📏',
            Str::contains($needle, ['color', 'colour', 'цвет']) => '🎨',
            Str::contains($needle, ['port', 'connector', 'interface', 'usb', 'hdmi', 'разъем', 'разъём', 'порт']) => '🔌',
            Str::contains($needle, ['wifi', 'bluetooth', 'network', 'lan', 'сеть']) => '📶',
            Str::contains($needle, ['warranty', 'гарант']) => '🛡',
            Str::contains($needle, ['material', 'материал']) => '🧱',
            Str::contains($needle, ['wheel', 'tire', 'tyre', 'шин', 'колес', 'колёс']) => '🛞',
            Str::contains($needle, ['power', 'watt', 'ватт', 'мощност']) => '⚡',
            Str::contains($needle, ['keyboard', 'клавиатур']) => '⌨️',
            Str::contains($needle, ['audio', 'speaker', 'sound', 'звук', 'колонк']) => '🔊',
            Str::contains($needle, ['os', 'система']) => '🗂',
            default => '▪️',
        };
    }
}
