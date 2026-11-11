<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operator, 2026-11-11, about the table of units inside a product: the number should appear
 * the moment a unit is added, and the supplier should be picked from a list that takes a new
 * name on the spot.
 *
 * So suppliers become a list of their own (a unit still keeps the name itself, as it keeps its
 * product's), and unit numbers are issued by a counter instead of following the row's id: a
 * number can then be given before the unit is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach (DB::table('stock_units')->whereNotNull('supplier')->where('supplier', '!=', '')->distinct()->orderBy('supplier')->pluck('supplier') as $name) {
            DB::table('suppliers')->insertOrIgnore(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Numbers given so far followed the rows' ids; the counter goes on from the highest id ever used.
        $highest = (int) DB::table('stock_units')->max('id');
        if (DB::connection()->getDriverName() === 'sqlite') {
            $highest = max($highest, (int) DB::table('sqlite_sequence')->where('name', 'stock_units')->value('seq'));
        }
        DB::table('app_settings')->updateOrInsert(['key' => 'stock.last_unit_number'],
            ['value' => (string) $highest, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'stock.last_unit_number')->delete();
        Schema::dropIfExists('suppliers');
    }
};
