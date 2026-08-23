<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxTokens(self::MAX_OUTPUT_TOKENS)]
class TelegramPhotoTextAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const int MAX_OUTPUT_TOKENS = 2_000;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            The attached image was sent to a Telegram bot that manages an electronics product catalog.
            It is most often a photo of a spec label/sticker, box, receipt, or on-screen specification
            page for a laptop or other electronics product; rarely it is a plain photo of the product
            itself with no readable text.

            If the image contains legible printed or displayed text, transcribe only the details useful
            for identifying and searching this exact product: brand, model/part number, and key
            specifications (CPU, GPU, RAM, storage, screen, color, etc). Skip marketing boilerplate,
            barcodes, prices, and store branding that do not help identify the product. Keep the
            original language and spelling of brand/model names and technical terms; do not translate
            them. Output plain text only, no bullet points or markdown.

            If there is no legible text at all, briefly describe the visible product in one short
            sentence in Russian instead (type of device, brand if visible, color) so it can still be
            used as a search query.

            has_text must be true only when you actually transcribed real text from the image, and
            false when you fell back to a visual description because nothing legible was visible.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->max(2000)->required(),
            'has_text' => $schema->boolean()->required(),
        ];
    }
}
