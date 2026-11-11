<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per physical item in stock (operator, 2026-11-11): three near-identical used drives
 * are three units, each with a number of its own, what it was bought for and when. A
 * configuration's quantity is the number of its units in stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 24)->nullable()->unique();
            $table->string('serial_number')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->date('purchased_at')->nullable();
            $table->string('supplier')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('in_stock')->index();
            $table->timestamp('sold_at')->nullable();
            $table->decimal('sold_price', 12, 2)->nullable();
            $table->timestamps();
        });

        // What is in stock today becomes units, so the two never disagree from the first day.
        $now = now();
        foreach (DB::table('product_variants')->where('quantity', '>', 0)->orderBy('id')->get(['id', 'quantity']) as $variant) {
            for ($unit = 0; $unit < min(1000, (int) $variant->quantity); $unit++) {
                $id = DB::table('stock_units')->insertGetId(['product_variant_id' => $variant->id, 'status' => 'in_stock',
                    'created_at' => $now, 'updated_at' => $now]);
                DB::table('stock_units')->where('id', $id)->update(['code' => 'U-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_units');
    }
};
