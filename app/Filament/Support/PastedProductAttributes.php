<?php

namespace App\Filament\Support;

use App\Models\AttributeDefinition;
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
        $definitions = AttributeDefinition::query()->get();
        $resolved = [];
        $seen = [];
        $missing = [];
        foreach ($rows as $row) {
            $name = $this->normalize($row['label']);
            $matches = $definitions->filter(fn (AttributeDefinition $definition): bool => $this->normalize($definition->key) === $name);
            if ($matches->isEmpty()) {
                $matches = $definitions->filter(function (AttributeDefinition $definition) use ($name): bool {
                    $labels = [$definition->label, __($definition->label), ...array_values($definition->translations ?? [])];

                    return collect($labels)->filter(fn ($label): bool => is_string($label))
                        ->contains(fn (string $label): bool => $this->normalize($label) === $name);
                });
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

        // Validate the entire clipboard before creating any dictionary records.
        return DB::transaction(function () use ($resolved, $existing, $replaceFilled): array {
            $result = ['rows' => $existing, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'created' => 0];
            foreach ($resolved as [$row, $definition]) {
                if (! $definition) {
                    $definition = AttributeDefinition::query()->create([
                        'label' => $row['label'], 'data_type' => 'text', 'default_unit' => $row['unit'],
                    ]);
                    $result['created']++;
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

    private function normalize(string $value): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($value)));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['pasted_attributes' => $message]);
    }
}
