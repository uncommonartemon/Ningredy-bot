<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $filterable = ['cpu', 'gpu', 'ram', 'storage', 'display', 'screen_size', 'refresh_rate'];

        DB::table('attribute_definitions')
            ->whereNotIn('key', $filterable)
            ->update(['is_filterable' => false, 'is_variant' => false, 'updated_at' => now()]);

        DB::table('attribute_definitions')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('attribute_values')
                ->whereColumn('attribute_values.attribute_definition_id', 'attribute_definitions.id'))
            ->whereNotIn('key', $filterable)
            ->delete();
    }

    public function down(): void
    {
        // Data cleanup is intentionally not reversed.
    }
};
