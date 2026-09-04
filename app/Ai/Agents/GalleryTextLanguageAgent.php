<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * One question, asked once per accepted gallery: does any of these frames carry
 * prominent text in a language this catalog does not publish?
 *
 * The full Vision review already enforces that rule, but it never runs on a
 * gallery accepted wholesale - a complete, structurally confirmed slider from an
 * exact product page is taken as a set, precisely so that every frame does not
 * have to be paid for individually. That left the one rule which is about
 * pixels rather than structure with no gate at all, and a Czech-and-English
 * catalog could publish a card covered in German marketing copy.
 *
 * So this asks nothing else. Not whether the photo is good, not whether it is
 * the right product, not which frame should lead - those are settled by then.
 * One cheap call, one question, and the frames that fail it are dropped.
 */
#[MaxTokens(self::MAX_OUTPUT_TOKENS)]
class GalleryTextLanguageAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const int MAX_OUTPUT_TOKENS = 2_000;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You are given the frames of one product gallery. Report only the frames whose pixels carry
            prominent text in a language other than English or Czech.

            Prominent means text a shopper reads as part of the composition: marketing claims, feature
            callouts, badges, banner copy, promotional overlays. It is the message printed onto the image,
            not the object photographed.

            Never report a frame for:
            - the product's own brand, model or SKU markings, wherever they appear;
            - text that is part of the machine itself - keyboard legends, port labels, screen content,
              stickers on the chassis;
            - universal technical abbreviations and units (USB, HDMI, Wi-Fi, GB, Hz, RTX, OLED);
            - text too small or too incidental to read at a glance;
            - a frame with no text at all.

            Latin letters are not the test - Czech and English both use them, and German, French, Spanish,
            Polish and Turkish marketing copy does too. Judge the language of the words, not the alphabet.
            When you cannot read the words well enough to name the language, leave the frame out: this gate
            removes photographs from a catalog, so an uncertain frame stays.

            Return the 1-based index of each offending frame in foreign_text_frames, and one short sentence
            in Russian saying what was found, for the operator's log.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'foreign_text_frames' => $schema->array()->max(40)
                ->items($schema->integer()->min(1)->max(40))->required(),
            'reason' => $schema->string()->max(300)->required(),
        ];
    }
}
