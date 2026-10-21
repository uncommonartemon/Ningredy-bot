<?php

use App\Models\AttributeDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every attribute the bot created was a storefront filter, part numbers and
 * EANs included: one checkbox per product. The operator can still switch any
 * of them back on in the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('attribute_definitions')->whereIn('key', AttributeDefinition::IDENTIFIER_KEYS)->update(['is_filterable' => false]);
    }

    public function down(): void
    {
        DB::table('attribute_definitions')->whereIn('key', AttributeDefinition::IDENTIFIER_KEYS)->update(['is_filterable' => true]);
    }
};
