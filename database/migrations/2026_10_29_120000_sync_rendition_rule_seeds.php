<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The seed rules of database/data/rendition-rules.json, brought into every
 * installation that already has the table: rules the file gained are inserted,
 * rules it still has take the file's order and note. Rules the agent learned,
 * and what each rule has done on each host, stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeds = json_decode((string) file_get_contents(database_path('data/rendition-rules.json')), true) ?: [];
        foreach (array_values($seeds) as $index => $rule) {
            $attributes = ['host' => null, 'origin' => 'seed', 'find' => $rule['find'], 'flags' => $rule['flags'] ?? '', 'replace' => $rule['replace']];
            $values = ['position' => ($index + 1) * 10, 'note' => $rule['note'] ?? null, 'updated_at' => now()];
            $existing = DB::table('image_rendition_rules')->where($attributes)->first(['id']);
            if ($existing) {
                DB::table('image_rendition_rules')->where('id', $existing->id)->update($values);
            } else {
                DB::table('image_rendition_rules')->insert([...$attributes, ...$values, 'status' => 'active', 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // The seeds are data; an older code version simply ignores the rules it never knew.
    }
};
