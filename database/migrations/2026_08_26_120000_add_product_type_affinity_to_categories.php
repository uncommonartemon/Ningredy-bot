<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('product_type_affinity', 20)->nullable()->after('slug');
        });

        foreach ([
            'laptops' => 'laptop',
            'computers' => 'desktop',
            'components' => 'component',
            'other-tech' => 'other',
        ] as $slug => $type) {
            DB::table('categories')->where('slug', $slug)->update([
                'product_type_affinity' => $type,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('product_type_affinity');
        });
    }
};
