<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\ProductSourceDomain;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class FlagDomainRecipeNote implements Tool
{
    use RecordsOperations;

    /**
     * Caps runaway/self-reinforcing auto-notes the same way
     * ProductGalleryRecipeTrainer::DOWNLOAD_DEGRADE_CYCLE_CAP caps auto-
     * retrain cycles - oldest auto note dropped first, human-authored lines
     * never touched.
     */
    private const MAX_AUTO_NOTES = 5;

    public function __construct(
        private readonly ProductSourceDomain $domainSettings,
        private readonly ?TelegramUpdate $update,
    ) {}

    public function description(): Stringable|string
    {
        return 'Leave a short, concrete note for future training rounds on this domain - e.g. a selector that '
            .'looked right but was unreliable, a control that navigates away instead of opening the gallery, or '
            .'a control that picks up the wrong color/variant. This is appended to the domain\'s persistent '
            .'operator-trusted hint (visible as domain_hint on every future round for this domain), never '
            .'replaces or removes any existing note.';
    }

    public function handle(Request $request): Stringable|string
    {
        $note = trim((string) $request->string('note'));
        throw_if($note === '', RuntimeException::class, 'note is required.');
        $note = mb_substr($note, 0, 300);
        $category = (string) $request->string('category', 'other');
        $category = in_array($category, ['selector_unreliable', 'wrong_variant_source', 'navigation_hazard', 'other'], true)
            ? $category
            : 'other';

        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'flag_domain_recipe_note',
            ['domain' => $this->domainSettings->domain, 'category' => $category, 'note' => $note],
            function () use ($category, $note): array {
                $existingLines = array_values(array_filter(
                    preg_split('/\R/', (string) $this->domainSettings->agent_hint) ?: [],
                    fn (string $line): bool => trim($line) !== '',
                ));
                $humanLines = array_values(array_filter(
                    $existingLines,
                    fn (string $line): bool => ! str_starts_with(trim($line), '[auto '),
                ));
                $autoLines = array_values(array_filter(
                    $existingLines,
                    fn (string $line): bool => str_starts_with(trim($line), '[auto '),
                ));

                $autoLines[] = '[auto '.$category.' '.now()->toDateString().'] '.$note;
                $autoLines = array_slice($autoLines, -self::MAX_AUTO_NOTES);

                $this->domainSettings->update([
                    'agent_hint' => implode("\n", [...$humanLines, ...$autoLines]),
                ]);

                return ['ok' => true, 'auto_notes_count' => count($autoLines)];
            },
            ProductSourceDomain::class,
            $this->domainSettings->id,
        );

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->max(300)->required()->description(
                'A short, concrete note for future training rounds on this domain - e.g. "the viewer only opens '
                .'via .zoom-btn, not .gallery-btn".',
            ),
            'category' => $schema->string()->enum([
                'selector_unreliable', 'wrong_variant_source', 'navigation_hazard', 'other',
            ])->description('What kind of problem this note documents.'),
        ];
    }
}
