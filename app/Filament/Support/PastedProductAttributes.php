<?php

namespace App\Filament\Support;

use App\Models\AttributeDefinition;
use App\Services\Catalog\AttributeDictionary;
use App\Services\Catalog\AttributeKeeper;
use App\Services\Products\AiAttributeGroups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Clipboard import into form state, not a product or variant save. */
final class PastedProductAttributes
{
    /** @return array{rows: array, added: int, updated: int, skipped: int, created: int} */
    public function merge(string $text, array $existing, bool $replaceFilled = false, bool $createMissing = false): array
    {
        $rows = $this->parse($text);
        $dictionary = app(AttributeDictionary::class);
        $definitions = $dictionary->all();
        $resolved = [];
        $seen = [];
        $missing = [];
        foreach ($rows as $row) {
            $name = $this->normalize($row['label']);
            $matches = $definitions->filter(fn (AttributeDefinition $definition): bool => $this->normalize($definition->key) === $name);
            if ($matches->isEmpty()) {
                // Its name in any language or another name it was given (decision of 2026-11-13).
                $normalized = AttributeDictionary::normalize($row['label']);
                $matches = $definitions->filter(fn (AttributeDefinition $definition): bool => in_array($normalized, $dictionary->names($definition), true));
            }
            if ($matches->count() > 1) {
                $this->fail(__('catalog-form.ambiguous_attribute', ['name' => $row['label']]));
            }
            $definition = $matches->first();
            $identity = $definition ? 'id:'.$definition->id : 'name:'.$name;
            if (isset($seen[$identity])) {
                $this->fail(__('catalog-form.duplicate_attribute', ['name' => $row['label']]));
            }
            $seen[$identity] = true;
            if (! $definition) {
                $missing[] = $row['label'];
            }
            $resolved[] = [$row, $definition];
        }
        if ($missing !== [] && ! $createMissing) {
            $this->fail(__('catalog-form.missing_attributes', ['names' => implode(', ', $missing)]));
        }
        // Before anything is created, the dictionary's keeper reads the names it does not know:
        // one may be a characteristic under another name, a new one gets its group.
        $verdicts = $missing === [] ? [] : app(AttributeKeeper::class)->settle(collect($resolved)
            ->filter(fn (array $pair): bool => $pair[1] === null)
            ->map(fn (array $pair, int $index): array => ['index' => $index, 'key' => $this->pastedKey($pair[0]['label']),
                'name' => $pair[0]['label'], 'value' => $pair[0]['value']])->values()->all());

        // Validate the entire clipboard before creating any dictionary records.
        return DB::transaction(function () use ($resolved, $existing, $replaceFilled, $verdicts, $dictionary): array {
            $result = ['rows' => $existing, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'created' => 0];
            foreach ($resolved as [$row, $definition]) {
                if (! $definition) {
                    $verdict = $verdicts[$this->pastedKey($row['label'])] ?? null;
                    $definition = ($verdict['decision'] ?? null) === 'same'
                        ? AttributeDefinition::query()->where('key', $verdict['key'])->first() : null;
                    if ($definition === null) {
                        // The operator's own name stays the label; the keeper's English name is the English one.
                        $definition = ($verdict['decision'] ?? null) === 'new'
                            ? AttributeDefinition::query()->firstOrCreate(['key' => $verdict['key']], ['label' => $row['label'], 'data_type' => 'text',
                                'default_unit' => $row['unit'], 'translations' => filled($verdict['name']) ? ['en' => $verdict['name']] : null])
                            : AttributeDefinition::query()->create(['label' => $row['label'], 'data_type' => 'text', 'default_unit' => $row['unit']]);
                        $result['created']++;
                    }
                    if ($definition->attribute_group_id === null && ($group = AiAttributeGroups::resolve($verdict['group'] ?? null)) !== null) {
                        $definition->update(['attribute_group_id' => $group->id]);
                    }
                    if ($verdict['remember'] ?? false) {
                        $dictionary->remember($definition, $row['label']);
                    }
                }
                $key = null;
                foreach ($result['rows'] as $candidateKey => $candidate) {
                    if ((string) ($candidate['attribute_definition_id'] ?? '') === (string) $definition->id) {
                        $key = $candidateKey;
                        break;
                    }
                }
                if ($key !== null && filled($result['rows'][$key]['value'] ?? null) && ! $replaceFilled) {
                    $result['skipped']++;

                    continue;
                }
                $prior = $key !== null ? $result['rows'][$key] : [];
                $result['rows'][$key ?? Str::uuid()->toString()] = [
                    ...$prior,
                    'attribute_definition_id' => $definition->id,
                    'value' => $row['value'],
                    'unit' => $row['unit_provided'] ? ($row['unit'] ?? '') : (array_key_exists('unit', $prior) ? $prior['unit'] : $definition->default_unit),
                    '_category_default' => false,
                ];
                $result[$key === null ? 'added' : 'updated']++;
            }

            return $result;
        });
    }

    /** TSV copied from Excel supports quoted cells, tabs and embedded newlines. */
    public function parse(string $text): array
    {
        if (mb_strlen($text) > 100000) {
            $this->fail(__('catalog-form.too_many'));
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', trim($text, "\r\n"));
        $this->assertBalancedQuotes($text);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $rows = [];
        $line = 0;
        $firstNonemptyRow = true;
        try {
            while (($columns = fgetcsv($stream, separator: "\t", escape: '')) !== false) {
                $line++;
                if (count($columns) === 1 && preg_match('/^([^:]+):\s*(.+)$/su', (string) $columns[0], $match)) {
                    $columns = [$match[1], $match[2]];
                }
                if (count(array_filter($columns, fn ($cell): bool => trim((string) $cell) !== '')) === 0) {
                    continue;
                }
                if ($firstNonemptyRow) {
                    $firstNonemptyRow = false;
                    if ($this->isHeader($columns)) {
                        continue;
                    }
                }
                if (count($columns) < 2 || count($columns) > 3) {
                    $this->fail(__('catalog-form.invalid_columns', ['line' => $line]));
                }
                [$label, $value] = array_map(fn ($cell): string => trim((string) $cell), array_slice($columns, 0, 2));
                $unit = isset($columns[2]) && trim($columns[2]) !== '' ? trim($columns[2]) : null;
                if ($label === '' || $value === '') {
                    $this->fail(__('catalog-form.empty_cell', ['line' => $line]));
                }
                if (mb_strlen($label) > 255 || mb_strlen($value) > 2000 || mb_strlen($unit ?? '') > 24) {
                    $this->fail(__('catalog-form.too_long', ['line' => $line]));
                }
                $rows[] = [...compact('label', 'value', 'unit'), 'unit_provided' => count($columns) === 3];
                if (count($rows) > 100) {
                    $this->fail(__('catalog-form.too_many'));
                }
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            $this->fail(__('catalog-form.empty_table'));
        }

        return $rows;
    }

    private function assertBalancedQuotes(string $text): void
    {
        $quoted = false;
        $afterQuote = false;
        $fieldStart = true;
        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $character = $text[$index];
            if ($quoted) {
                if ($character === '"') {
                    if (($text[$index + 1] ?? null) === '"') {
                        $index++;
                    } else {
                        $quoted = false;
                        $afterQuote = true;
                    }
                }

                continue;
            }
            $separator = in_array($character, ["\t", "\r", "\n"], true);
            if ($afterQuote) {
                if ($separator) {
                    $afterQuote = false;
                    $fieldStart = true;
                } elseif ($character !== ' ') {
                    $this->fail(__('catalog-form.invalid_quotes'));
                }

                continue;
            }
            if ($separator) {
                $fieldStart = true;
            } elseif ($fieldStart && $character === '"') {
                $quoted = true;
                $fieldStart = false;
            } elseif ($character !== ' ') {
                $fieldStart = false;
            }
        }
        if ($quoted) {
            $this->fail(__('catalog-form.invalid_quotes'));
        }
    }

    private function isHeader(array $columns): bool
    {
        return count($columns) >= 2
            && in_array($this->normalize((string) $columns[0]), ['attribute', 'name', 'label', 'характеристика', 'название', 'атрибут', 'назва', 'atribut', 'název'])
            && in_array($this->normalize((string) $columns[1]), ['value', 'значение', 'значення', 'hodnota']);
    }

    /** The key a pasted name is asked about under: its own words in snake_case. */
    private function pastedKey(string $label): string
    {
        return Str::slug($label, '_') ?: 'attribute_'.substr(sha1($label), 0, 12);
    }

    private function normalize(string $value): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($value)));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['pasted_attributes' => $message]);
    }
}
